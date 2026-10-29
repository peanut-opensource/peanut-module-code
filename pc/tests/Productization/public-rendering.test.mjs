import assert from 'node:assert/strict';
import { execFileSync, spawn } from 'node:child_process';
import { createHash } from 'node:crypto';
import { once } from 'node:events';
import {
  cpSync,
  existsSync,
  mkdirSync,
  readFileSync,
  readdirSync,
  lstatSync,
  readlinkSync,
  writeFileSync,
} from 'node:fs';
import { request } from 'node:http';
import { createRequire } from 'node:module';
import { createServer } from 'node:net';
import { resolve } from 'node:path';
import test from 'node:test';
import { createSsrUpstream } from './fixtures/ssr-upstream.mjs';

const root = resolve(import.meta.dirname, '../../..');
const required = (name) => {
  assert.ok(process.env[name], `${name} must be explicit`);
  return process.env[name];
};
const registry = JSON.parse(
  readFileSync(required('PEANUT_RESOURCE_REGISTRY'))
).resources;
const resource = (id) => {
  const matches = registry.local_listeners.filter(
    (item) => item.stable_resource_id === id
  );
  assert.equal(matches.length, 1, id);
  assert.equal(matches[0].environment, 'development-test');
  return matches[0];
};
const ssr = resource('peanut-pc-ssr-qualification');
const api = resource('peanut-pc-ssr-synthetic-upstream');
const gateway = resource('peanut-pc-public-routing-20261004');
const mode = required('PC_MATRIX_MODE');
assert.ok(['hybrid', 'spa'].includes(mode));
const output = resolve(required('PC_MATRIX_OUTPUT'));
const evidence = resolve(required('PC_MATRIX_EVIDENCE'));
assert.ok(evidence.startsWith(resolve(root, '.local/') + '/'));
assert.ok(!existsSync(evidence), 'Never overwrite earlier evidence');
mkdirSync(evidence, { recursive: true });
const lease = execFileSync(
  'bash',
  [
    resolve(root, 'scripts/project-resource-lease'),
    'show',
    '--lease',
    required('PC_MATRIX_LEASE'),
  ],
  { cwd: root, encoding: 'utf8' }
);
assert.match(lease, /status\tACTIVE/);
for (const item of [ssr, api, gateway]) {
  assert.ok(lease.includes(`listener\t${item.stable_resource_id}`));
  assert.ok(lease.includes(`port\t${item.port}`));
}
const docker = required('PC_MATRIX_DOCKER');
const playwrightRoot = required('PC_BROWSER_PLAYWRIGHT_ROOT');
const require = createRequire(resolve(playwrightRoot, 'package.json'));
assert.equal(require('./package.json').version, '1.63.0-alpha-2026-08-05');
const { chromium } = require(playwrightRoot);
assert.ok(existsSync(chromium.executablePath()));
const publicRoutes = [
  '/',
  '/information',
  '/information/1',
  '/information/detail/1',
  '/about',
];
const privateRoutes = [
  '/login',
  '/user/info',
  '/user/collection',
  '/account/security',
  '/recharge',
  '/oauth/complete',
];
const markers = [
  'Synthetic Private Member',
  'synthetic-private-token',
  'private@example.test',
  '15500000077',
];

async function freePort(port) {
  const server = createServer();
  await new Promise((done, reject) => {
    server.once('error', reject);
    server.listen(port, '127.0.0.1', done);
  });
  await new Promise((done) => server.close(done));
}
function outputDigest(directory) {
  const hash = createHash('sha256');
  function visit(relative = '') {
    for (const name of readdirSync(resolve(directory, relative)).sort()) {
      const path = relative ? `${relative}/${name}` : name;
      const absolute = resolve(directory, path);
      const stat = lstatSync(absolute);
      hash.update(`${path}\0${stat.mode}\0`);
      if (stat.isDirectory()) visit(path);
      else if (stat.isSymbolicLink()) hash.update(readlinkSync(absolute));
      else hash.update(readFileSync(absolute));
    }
  }
  visit();
  return hash.digest('hex');
}
function pageRequest(port, path, cookie = '') {
  return new Promise((done, reject) => {
    const req = request(
      {
        host: '127.0.0.1',
        port,
        path,
        headers: { host: 'tenant-a.example.test', cookie, connection: 'close' },
      },
      (response) => {
        const chunks = [];
        response.on('data', (chunk) => chunks.push(chunk));
        response.on('end', () =>
          done({
            status: response.statusCode,
            headers: response.headers,
            body: Buffer.concat(chunks).toString('utf8'),
          })
        );
      }
    );
    req.on('error', reject);
    req.setTimeout(10000, () => req.destroy(new Error('HTTP timeout')));
    req.end();
  });
}
async function ready(port) {
  for (let count = 0; count < 100; count++) {
    try {
      if ((await pageRequest(port, '/healthz')).status === 200) return;
    } catch {}
    await new Promise((done) => setTimeout(done, 100));
  }
  throw new Error(`Service not ready: ${port}`);
}

test(
  `native ${mode} public rendering, privacy and root routing`,
  { timeout: 120000 },
  async (t) => {
    const before = outputDigest(output);
    for (const item of [ssr, api, gateway]) await freePort(item.port);
    assert.equal(
      execFileSync(
        docker,
        [
          'ps',
          '-a',
          '--filter',
          `name=^/${gateway.container_name}$`,
          '--format',
          '{{.Names}}',
        ],
        { encoding: 'utf8' }
      ).trim(),
      ''
    );
    const upstream = createSsrUpstream();
    let child;
    let browser;
    let page;
    let containerStarted = false;
    const errors = [];
    const warnings = [];
    const checks = [];
    const childLog = [];
    const pendingRequests = new Set();
    t.after(async () => {
      if (page) {
        await page.screenshot({ path: resolve(evidence, 'browser-final.png') });
      }
      if (browser) await browser.close();
      if (containerStarted)
        execFileSync(docker, ['rm', '-f', gateway.container_name]);
      if (child && child.exitCode === null && child.signalCode === null) {
        const stopped = once(child, 'exit');
        child.kill('SIGTERM');
        await stopped;
      }
      if (upstream.server.listening)
        await new Promise((done) => upstream.server.close(done));
      writeFileSync(
        resolve(evidence, 'summary.json'),
        JSON.stringify(
          {
            mode,
            checks,
            errors,
            warnings,
            pending_requests: [...pendingRequests],
            upstream_requests: upstream.requests,
            child_log: childLog.join(''),
            output,
            output_before: before,
            output_after: outputDigest(output),
            no_php_or_database: true,
          },
          null,
          2
        )
      );
      for (const item of [ssr, api, gateway]) await freePort(item.port);
      assert.equal(
        outputDigest(output),
        before,
        'Native output must remain unchanged'
      );
    });
    await new Promise((done) =>
      upstream.server.listen(api.port, api.host, done)
    );
    if (mode === 'hybrid') {
      child = spawn(process.execPath, [resolve(output, 'server/index.mjs')], {
        cwd: root,
        env: {
          PATH: process.env.PATH,
          HOST: ssr.host,
          PORT: String(ssr.port),
          NODE_ENV: 'production',
          NUXT_UPSTREAM_ORIGIN: `http://${api.host}:${api.port}`,
          NUXT_TRUSTED_HOSTS: 'tenant-a.example.test',
          NUXT_FORWARDED_PROTO: 'http',
        },
        stdio: ['ignore', 'pipe', 'pipe'],
      });
      child.stdout.on('data', (chunk) => childLog.push(chunk.toString()));
      child.stderr.on('data', (chunk) => childLog.push(chunk.toString()));
      await ready(ssr.port);
      for (const path of publicRoutes) {
        const response = await pageRequest(ssr.port, path);
        assert.equal(response.status, 200, path);
        assert.ok(
          response.body.includes(
            path === '/about' ? 'Tenant A description' : 'Tenant A article'
          ),
          path
        );
        for (const marker of markers)
          assert.ok(!response.body.includes(marker), path);
        checks.push(`SSR public content ${path}`);
      }
      const cookie = `token=synthetic-private-token; user_info=${encodeURIComponent(
        JSON.stringify({
          id: 77,
          nickname: markers[0],
          email: markers[2],
          mobile: markers[3],
        })
      )}`;
      const anonymous = await pageRequest(ssr.port, '/information/detail/1');
      const logged = await pageRequest(
        ssr.port,
        '/information/detail/1',
        cookie
      );
      const payload = (html) =>
        html.match(
          /<script[^>]*id="__NUXT_DATA__"[^>]*>([\s\S]*?)<\/script>/u
        )?.[1];
      assert.ok(payload(anonymous.body));
      assert.equal(payload(anonymous.body), payload(logged.body));
      assert.ok(!logged.body.includes('已收藏'));
      for (const marker of markers) assert.ok(!logged.body.includes(marker));
      checks.push('anonymous and authenticated public payloads are identical');
      const count = upstream.requests.length;
      for (const path of [
        ...privateRoutes,
        '/policy/privacy',
        '/information/detail/1/unregistered',
      ]) {
        const response = await pageRequest(ssr.port, path, cookie);
        assert.ok(response.status < 500, path);
        assert.ok(!response.body.includes('Tenant A article'), path);
        for (const marker of markers)
          assert.ok(!response.body.includes(marker), path);
        checks.push(`CSR server shell ${path}`);
      }
      assert.equal(
        upstream.requests.length,
        count,
        'CSR HTML must make no public or personal API requests'
      );
      assert.ok(
        upstream.requests.every(
          (item) => !item.cookie && !item.authorization && !item.token
        )
      );
    } else {
      assert.ok(existsSync(resolve(output, 'public/index.html')));
      assert.ok(
        !existsSync(resolve(output, 'server/index.mjs')),
        'SPA must have no SSR entry'
      );
      await freePort(ssr.port);
      checks.push('native generate output works without Node SSR');
    }

    const documentRoot = resolve(evidence, 'public');
    for (const target of ['admin', 'platform', 'mobile']) {
      mkdirSync(resolve(documentRoot, target), { recursive: true });
      writeFileSync(
        resolve(documentRoot, target, 'index.html'),
        `<h1>Synthetic ${target}</h1>`
      );
    }
    writeFileSync(
      resolve(documentRoot, 'index.php'),
      '<?php /* synthetic routing target, never executed */'
    );
    cpSync(resolve(output, 'public'), resolve(documentRoot, 'pc'), {
      recursive: true,
    });
    cpSync(resolve(root, 'pc/public/brand'), resolve(documentRoot, 'brand'), {
      recursive: true,
    });
    const source = readFileSync(
      resolve(
        root,
        `deploy/nginx/peanut-admin${mode === 'hybrid' ? '-ssr' : ''}.conf`
      ),
      'utf8'
    );
    // Only the isolated upstream endpoint changes. Production location ordering,
    // document-root layout, API dispatch and history fallback run unmodified.
    writeFileSync(
      resolve(evidence, 'default.conf'),
      source.replaceAll(
        'http://pc:3000',
        `http://host.docker.internal:${ssr.port}`
      )
    );
    const args = [
      '--add-host',
      'php:host-gateway',
      '-v',
      `${documentRoot}:/var/www/peanut-admin/server/public:ro`,
      '-v',
      `${resolve(evidence, 'default.conf')}:/etc/nginx/conf.d/default.conf:ro`,
    ];
    execFileSync(
      docker,
      ['run', '--rm', ...args, gateway.image, 'nginx', '-t'],
      { stdio: 'pipe' }
    );
    execFileSync(docker, [
      'run',
      '-d',
      '--name',
      gateway.container_name,
      '-p',
      `127.0.0.1:${gateway.port}:80`,
      ...args,
      gateway.image,
    ]);
    containerStarted = true;
    await ready(gateway.port);
    for (const path of ['/admin', '/platform']) {
      const redirect = await pageRequest(gateway.port, path);
      assert.equal(redirect.status, 302, path);
      const entry = await pageRequest(gateway.port, `${path}/`);
      assert.equal(entry.status, 200);
      assert.ok(entry.body.includes(`Synthetic ${path.slice(1)}`));
      checks.push(`root boundary ${path}`);
    }
    for (const path of [
      '/api',
      '/api/index/config',
      '/adminapi',
      '/platformapi',
      '/installapi',
    ]) {
      const response = await pageRequest(gateway.port, path);
      assert.equal(
        response.status,
        502,
        `${path} must reach isolated PHP target, not PC fallback`
      );
    }
    assert.equal(
      (await pageRequest(gateway.port, '/_nuxt/missing.js')).status,
      404
    );
    assert.equal((await pageRequest(gateway.port, '/missing.css')).status, 404);
    checks.push('real Nginx syntax, API dispatch and missing-asset boundaries');

    browser = await chromium.launch({
      headless: true,
      chromiumSandbox: true,
      args: [
        `--host-resolver-rules=MAP tenant-a.example.test 127.0.0.1:${gateway.port}`,
      ],
    });
    const context = await browser.newContext({
      viewport: { width: 1280, height: 900 },
    });
    await context.route('**/*', async (route) => {
      const req = route.request();
      const url = new URL(req.url());
      if (url.hostname !== 'tenant-a.example.test') return route.abort();
      if (!url.pathname.startsWith('/api/')) return route.continue();
      const headers = await req.allHeaders();
      const response = await fetch(
        `http://${api.host}:${api.port}${url.pathname}${url.search}`,
        {
          method: req.method(),
          headers: {
            'x-forwarded-host': url.hostname,
            'x-forwarded-proto': 'http',
            ...(headers.token ? { token: headers.token } : {}),
            ...(headers.authorization
              ? { authorization: headers.authorization }
              : {}),
          },
          body: ['GET', 'HEAD'].includes(req.method())
            ? undefined
            : req.postData(),
        }
      );
      await route.fulfill({
        status: response.status,
        contentType: 'application/json',
        body: await response.text(),
      });
    });
    page = await context.newPage();
    page.on('request', (request) => pendingRequests.add(request.url()));
    page.on('requestfinished', (request) =>
      pendingRequests.delete(request.url())
    );
    page.on('requestfailed', (request) =>
      pendingRequests.delete(request.url())
    );
    page.on('pageerror', (error) => errors.push(error.message));
    page.on('console', (message) => {
      if (/hydration.*mismatch|mismatch.*hydration/iu.test(message.text()))
        warnings.push(message.text());
    });
    for (const path of publicRoutes) {
      await page.goto(`http://tenant-a.example.test${path}`, {
        waitUntil: 'domcontentloaded',
      });
      await page
        .getByText(
          path === '/about' ? 'Tenant A description' : 'Tenant A article',
          { exact: true }
        )
        .first()
        .waitFor();
      await page.reload({ waitUntil: 'domcontentloaded' });
      await page
        .getByText(
          path === '/about' ? 'Tenant A description' : 'Tenant A article',
          { exact: true }
        )
        .first()
        .waitFor();
      checks.push(`browser direct entry and refresh ${path}`);
    }
    await page.goto('http://tenant-a.example.test/information/detail/1', {
      waitUntil: 'domcontentloaded',
    });
    await page.getByRole('button', { name: '收藏', exact: true }).waitFor();
    await page.getByRole('button', { name: '收藏', exact: true }).click();
    await page.waitForURL('**/login');
    await page
      .getByRole('button', { name: '登录', exact: true })
      .last()
      .waitFor();
    await page.reload({ waitUntil: 'domcontentloaded' });
    await page
      .getByRole('button', { name: '登录', exact: true })
      .last()
      .waitFor();
    checks.push('anonymous favorite guides login and direct login refresh');
    await context.addCookies([
      {
        name: 'token',
        value: 'synthetic-private-token',
        domain: 'tenant-a.example.test',
        path: '/',
      },
      {
        name: 'user_info',
        value: encodeURIComponent(
          JSON.stringify({
            id: 77,
            nickname: markers[0],
            avatar: '/brand/avatar-member.svg',
            mobile: markers[3],
          })
        ),
        domain: 'tenant-a.example.test',
        path: '/',
      },
    ]);
    await page.goto('http://tenant-a.example.test/information/detail/1', {
      waitUntil: 'domcontentloaded',
    });
    await page.getByRole('button', { name: '已收藏', exact: true }).waitFor();
    assert.ok(
      upstream.requests.some(
        (item) =>
          item.path.startsWith('/api/article/detail') &&
          (item.token || item.authorization)
      )
    );
    upstream.state.mode = 'favorite-write-failure';
    await page.getByRole('button', { name: '已收藏', exact: true }).click();
    await page.waitForFunction(
      () => !document.querySelector('button.is-loading')
    );
    await page.getByRole('button', { name: '已收藏', exact: true }).waitFor();
    await page.getByText('Tenant A 正文', { exact: true }).waitFor();
    checks.push('favorite write failure preserves article and original state');
    upstream.state.mode = 'normal';
    await page.getByRole('button', { name: '已收藏', exact: true }).click();
    await page.getByRole('button', { name: '收藏', exact: true }).waitFor();
    checks.push('authenticated favorite loads and toggles only in browser');
    upstream.state.mode = 'favorite-failure';
    await page.goto('http://tenant-a.example.test/information/detail/1', {
      waitUntil: 'domcontentloaded',
    });
    await page.getByRole('button', { name: '收藏', exact: true }).waitFor();
    await page.waitForFunction(
      () => !document.querySelector('button.is-loading')
    );
    await page.getByText('Tenant A 正文', { exact: true }).waitFor();
    assert.equal(new URL(page.url()).pathname, '/information/detail/1');
    checks.push('favorite API failure preserves the public article');
    upstream.state.mode = 'normal';
    for (const path of [
      '/user/info',
      '/user/collection',
      '/account/security',
    ]) {
      const assertPrivatePage = async () => {
        const title = {
          '/user/info': '个人资料',
          '/user/collection': '我的收藏',
          '/account/security': '账户安全',
        }[path];
        await page.getByRole('heading', { name: title, exact: true }).waitFor();
        if (path === '/user/info') {
          await page.getByPlaceholder('请输入昵称').waitFor();
          assert.equal(
            await page.getByPlaceholder('请输入昵称').inputValue(),
            markers[0]
          );
        }
      };
      await page.goto(`http://tenant-a.example.test${path}`, {
        waitUntil: 'domcontentloaded',
      });
      await assertPrivatePage();
      assert.equal(new URL(page.url()).pathname, path);
      await page.reload({ waitUntil: 'domcontentloaded' });
      await assertPrivatePage();
      checks.push(`private browser direct entry and refresh ${path}`);
    }
    upstream.state.mode = 'favorite-expired';
    await page.goto('http://tenant-a.example.test/information/detail/1', {
      waitUntil: 'domcontentloaded',
    });
    await page.getByRole('button', { name: '收藏', exact: true }).waitFor();
    await page.waitForFunction(
      () => !document.querySelector('button.is-loading')
    );
    await page.getByText('Tenant A 正文', { exact: true }).waitFor();
    assert.equal(new URL(page.url()).pathname, '/information/detail/1');
    checks.push(
      'expired optional personal session preserves anonymous reading'
    );
    assert.deepEqual(errors, []);
    assert.deepEqual(warnings, []);
  }
);
