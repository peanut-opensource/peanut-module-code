import assert from 'node:assert/strict';
import { execFileSync, spawn } from 'node:child_process';
import { createHash } from 'node:crypto';
import { once } from 'node:events';
import {
  lstatSync,
  mkdirSync,
  readFileSync,
  readdirSync,
  readlinkSync,
  realpathSync,
  writeFileSync,
} from 'node:fs';
import { request as httpRequest } from 'node:http';
import { createServer } from 'node:net';
import { resolve, sep } from 'node:path';
import { fileURLToPath } from 'node:url';
import { test } from 'node:test';
import { createSsrUpstream } from './fixtures/ssr-upstream.mjs';

const root = fileURLToPath(new URL('../../../', import.meta.url));
const required = (key) => {
  assert.ok(
    process.env[key],
    `${key} is required; no implicit resource fallback`
  );
  return process.env[key];
};
const inside = (parent, value) =>
  value === parent || value.startsWith(parent + sep);

function outputDigest(directory) {
  const hash = createHash('sha256');
  const visit = (relative = '') => {
    for (const name of readdirSync(resolve(directory, relative)).sort()) {
      const path = relative ? `${relative}/${name}` : name;
      const absolute = resolve(directory, path);
      const stat = lstatSync(absolute);
      hash.update(`${path}\0${stat.mode}\0`);
      if (stat.isSymbolicLink()) {
        assert.ok(
          inside(directory, realpathSync(absolute)),
          'Output link escapes build'
        );
        hash.update(readlinkSync(absolute));
      } else if (stat.isDirectory()) visit(path);
      else hash.update(readFileSync(absolute));
    }
  };
  visit();
  return hash.digest('hex');
}

async function freePort(port) {
  const probe = createServer();
  await new Promise((resolvePromise, reject) => {
    probe.once('error', reject);
    probe.listen(port, '127.0.0.1', resolvePromise);
  });
  await new Promise((done, reject) =>
    probe.close((error) => (error ? reject(error) : done()))
  );
}

function fetchPage(port, path, host = 'tenant-a.example.test') {
  return new Promise((resolvePromise, reject) => {
    const request = httpRequest(
      {
        hostname: '127.0.0.1',
        port,
        path,
        method: 'GET',
        headers: {
          host,
          'accept': 'text/html',
          'connection': 'close',
          'cookie': 'tenant_session=synthetic-private-cookie',
          'authorization': 'Bearer synthetic-private-token',
          'x-forwarded-host': 'attacker.invalid',
          'x-forwarded-proto': 'http',
        },
      },
      (response) => {
        const chunks = [];
        let length = 0;
        response.on('data', (chunk) => {
          length += chunk.length;
          if (length > 2 * 1024 * 1024)
            request.destroy(new Error('Response exceeds test bound'));
          else chunks.push(chunk);
        });
        response.on('error', reject);
        response.on('end', () =>
          resolvePromise({
            status: response.statusCode,
            headers: response.headers,
            html: Buffer.concat(chunks).toString('utf8'),
          })
        );
      }
    );
    request.on('error', reject);
    request.setTimeout(10000, () =>
      request.destroy(new Error('HTTP test timed out'))
    );
    request.end();
  });
}

test(
  'unmodified native SSR separates public hosts and refuses private forwarding',
  { timeout: 90000 },
  async (context) => {
    const pcRoot = realpathSync(required('PC_SSR_PROJECT_ROOT'));
    const output = resolve(pcRoot, '.output');
    const entry = resolve(output, 'server/index.mjs');
    assert.ok(lstatSync(entry).isFile());
    const temporary = realpathSync(required('TMPDIR'));
    assert.ok(inside(resolve(root, '.local/tmp'), temporary));
    const evidence = resolve(required('PC_SSR_EVIDENCE_DIR'));
    assert.ok(inside(resolve(root, '.local/evidence'), evidence));
    mkdirSync(evidence, { recursive: true, mode: 0o700 });
    assert.equal(realpathSync(evidence), evidence);
    assert.deepEqual(
      readdirSync(evidence),
      [],
      'Preserve earlier evidence; select an empty output directory'
    );
    const listeners = JSON.parse(
      readFileSync(resolve(root, 'resources/project-resources.json'))
    ).resources.local_listeners;
    const resource = (id) => {
      const matches = listeners.filter(
        (item) => item.stable_resource_id === id
      );
      assert.equal(matches.length, 1);
      const [item] = matches;
      assert.equal(item.host, '127.0.0.1');
      assert.equal(item.environment, 'development-test');
      return item;
    };
    const ssr = resource('peanut-pc-ssr-qualification');
    const api = resource('peanut-pc-ssr-synthetic-upstream');
    const lease = execFileSync(
      'bash',
      [
        resolve(root, 'scripts/project-resource-lease'),
        'show',
        '--lease',
        required('PC_SSR_LEASE_ID'),
      ],
      { cwd: root, encoding: 'utf8', timeout: 10000 }
    );
    const lines = lease.trim().split('\n');
    const boundary = lines.indexOf('resources');
    assert.ok(boundary > 0);
    const fields = Object.fromEntries(
      lines.slice(0, boundary).map((line) => line.split('\t'))
    );
    assert.equal(fields.owner, 'pc-ssr-qualification');
    assert.equal(fields.gate, 'pc-ssr-http-qualification');
    assert.equal(fields.worktree, realpathSync(root));
    assert.ok(Number(fields.expires_at) > Date.now() / 1000 + 120);
    const head = execFileSync('git', ['rev-parse', 'HEAD'], {
      cwd: root,
      encoding: 'utf8',
    }).trim();
    assert.equal(fields.candidate, head);
    for (const item of [ssr, api]) {
      assert.ok(lines.includes(`port\t${item.port}`));
      assert.ok(lines.includes(`listener\t${item.stable_resource_id}`));
      await freePort(item.port);
    }
    const before = outputDigest(output);
    const upstream = createSsrUpstream();
    let child;
    let childLog = '';
    const results = [];
    context.after(async () => {
      if (child && child.exitCode === null && child.signalCode === null) {
        const closed = once(child, 'exit');
        child.kill('SIGTERM');
        const timer = setTimeout(() => child.kill('SIGKILL'), 5000);
        try {
          await closed;
        } finally {
          clearTimeout(timer);
        }
      }
      if (upstream.server.listening) {
        upstream.server.closeAllConnections();
        await new Promise((done) => upstream.server.close(done));
      }
      const after = outputDigest(output);
      writeFileSync(resolve(evidence, 'server.log'), childLog);
      writeFileSync(
        resolve(evidence, 'upstream.json'),
        JSON.stringify(upstream.requests, null, 2) + '\n'
      );
      writeFileSync(
        resolve(evidence, 'summary.json'),
        JSON.stringify(
          {
            results,
            output_before: before,
            output_after: after,
            code_commit: head,
            pc_root: pcRoot,
            scope:
              'native HTTP SSR against synthetic API; no PHP, database or browser',
          },
          null,
          2
        ) + '\n'
      );
      assert.equal(
        after,
        before,
        'Qualification must not rewrite Nitro or dependency outputs'
      );
      await freePort(ssr.port);
      await freePort(api.port);
    });
    await new Promise((done, reject) => {
      upstream.server.once('error', reject);
      upstream.server.listen(api.port, api.host, done);
    });
    child = spawn(process.execPath, [entry], {
      cwd: pcRoot,
      stdio: ['ignore', 'pipe', 'pipe'],
      env: {
        PATH: process.env.PATH,
        TMPDIR: temporary,
        NODE_ENV: 'production',
        HOST: ssr.host,
        PORT: String(ssr.port),
        NUXT_UPSTREAM_ORIGIN: `http://${api.host}:${api.port}`,
        NUXT_FORWARDED_PROTO: 'https',
        NUXT_TRUSTED_HOSTS: 'tenant-a.example.test,tenant-b.example.test',
      },
    });
    for (const stream of [child.stdout, child.stderr])
      stream.on('data', (data) => {
        childLog += data.toString();
        if (childLog.length > 512000) child.kill('SIGTERM');
      });
    let ready = false;
    for (let attempt = 0; attempt < 80; attempt++) {
      assert.equal(child.exitCode, null, childLog);
      try {
        const response = await fetchPage(ssr.port, '/healthz');
        if (response.status === 200) {
          ready = true;
          break;
        }
      } catch (error) {
        if (error.code !== 'ECONNREFUSED') throw error;
      }
      await new Promise((done) => setTimeout(done, 100));
    }
    assert.ok(ready, childLog);
    const check = async (name, body) =>
      context.test(name, async () => {
        await body();
        results.push(name);
      });
    const render = (host) => fetchPage(ssr.port, '/information/detail/1', host);
    await check(
      'alternating and concurrent same-ID pages keep host-local HTML',
      async () => {
        const responses = [];
        for (const host of [
          'tenant-a.example.test',
          'tenant-b.example.test',
          'tenant-a.example.test',
        ])
          responses.push([host, await render(host)]);
        const hosts = ['tenant-a.example.test', 'tenant-b.example.test'];
        const concurrent = await Promise.all(hosts.map(render));
        responses.push(...hosts.map((host, i) => [host, concurrent[i]]));
        for (const [host, response] of responses) {
          const tenant = host.startsWith('tenant-a') ? 'Tenant A' : 'Tenant B';
          const other = tenant === 'Tenant A' ? 'Tenant B' : 'Tenant A';
          assert.equal(response.status, 200, response.html);
          assert.ok(response.html.includes(`${tenant} article`));
          assert.ok(response.html.includes(`${tenant} 正文`));
          assert.ok(!response.html.includes(`${other} article`));
          assert.doesNotMatch(
            response.html,
            /<script>alert\('xss'\)<\/script>|<img src=x onerror=alert\(1\)>/
          );
          assert.doesNotMatch(response.html, /synthetic-private|已收藏/);
        }
        assert.equal(
          upstream.requests.filter((item) =>
            item.path.startsWith('/api/article/detail')
          ).length,
          5
        );
      }
    );
    await check(
      'public upstream never receives Cookie or Authorization',
      () => {
        assert.ok(upstream.requests.length >= 5);
        for (const item of upstream.requests) {
          assert.ok(
            ['tenant-a.example.test', 'tenant-b.example.test'].includes(
              item.forwardedHost
            )
          );
          assert.equal(item.host, `${api.host}:${api.port}`);
          assert.equal(item.forwardedProto, 'https');
          assert.equal(item.cookie, null);
          assert.equal(item.authorization, null);
        }
      }
    );
    await check(
      'private routes remain CSR, uncacheable and non-indexable',
      async () => {
        for (const path of ['/login', '/user', '/user/security']) {
          const count = upstream.requests.length;
          const response = await fetchPage(ssr.port, path);
          assert.equal(response.status, 200);
          assert.equal(response.headers['cache-control'], 'private, no-store');
          assert.equal(response.headers['x-robots-tag'], 'noindex, nofollow');
          assert.equal(upstream.requests.length, count);
          assert.doesNotMatch(
            response.html,
            /synthetic-private|Tenant A article|Tenant B article/
          );
        }
      }
    );
    await check('untrusted host fails before contacting upstream', async () => {
      const count = upstream.requests.length;
      const response = await render('attacker.invalid');
      assert.ok(response.status >= 400);
      assert.equal(upstream.requests.length, count);
      assert.doesNotMatch(response.html, /Tenant A article|Tenant B article/);
    });
    await check('explicit article absence remains HTTP 404', async () => {
      upstream.state.mode = 'missing';
      try {
        assert.equal((await render('tenant-a.example.test')).status, 404);
      } finally {
        upstream.state.mode = 'normal';
      }
    });
    await check(
      'service failure is not converted to article absence',
      async () => {
        upstream.state.mode = 'service-failure';
        try {
          assert.ok((await render('tenant-a.example.test')).status >= 500);
        } finally {
          upstream.state.mode = 'normal';
        }
      }
    );
    await check(
      'public configuration failure does not emit a successful default tenant page',
      async () => {
        upstream.state.mode = 'config-failure';
        try {
          assert.equal((await render('tenant-b.example.test')).status, 503);
        } finally {
          upstream.state.mode = 'normal';
        }
        assert.equal((await render('tenant-a.example.test')).status, 200);
      }
    );
  }
);
