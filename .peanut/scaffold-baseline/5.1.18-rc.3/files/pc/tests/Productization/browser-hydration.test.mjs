import assert from 'node:assert/strict';
import { execFileSync, spawn } from 'node:child_process';
import { createHash } from 'node:crypto';
import { once } from 'node:events';
import {
  existsSync,
  lstatSync,
  mkdirSync,
  readFileSync,
  readdirSync,
  readlinkSync,
  realpathSync,
  writeFileSync,
} from 'node:fs';
import { createRequire } from 'node:module';
import { createServer } from 'node:net';
import { resolve, sep } from 'node:path';
import { fileURLToPath } from 'node:url';
import { test } from 'node:test';
import { createSsrUpstream } from './fixtures/ssr-upstream.mjs';

const root = realpathSync(fileURLToPath(new URL('../../../', import.meta.url)));
const hosts = ['tenant-a.example.test', 'tenant-b.example.test'];
const required = (key) => {
  assert.ok(process.env[key], `${key} is required`);
  return process.env[key];
};
const inside = (parent, value) => value.startsWith(parent + sep);
const json = (path) => JSON.parse(readFileSync(path, 'utf8'));

function outputDigest(directory) {
  const hash = createHash('sha256');
  const visit = (relative = '') => {
    for (const name of readdirSync(resolve(directory, relative)).sort()) {
      const path = relative ? `${relative}/${name}` : name;
      const absolute = resolve(directory, path);
      const stat = lstatSync(absolute);
      hash.update(`${path}\0${stat.mode}\0`);
      if (stat.isSymbolicLink()) {
        assert.ok(inside(directory, realpathSync(absolute)));
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
  await new Promise((done, reject) => {
    probe.once('error', reject);
    probe.listen(port, '127.0.0.1', done);
  });
  await new Promise((done, reject) =>
    probe.close((e) => (e ? reject(e) : done()))
  );
}

test(
  'native PC browser hydration with an explicit synthetic API',
  { timeout: 120000 },
  async (t) => {
    const pcRoot = realpathSync(required('PC_SSR_PROJECT_ROOT'));
    const output = resolve(pcRoot, '.output');
    const entry = resolve(output, 'server/index.mjs');
    assert.ok(lstatSync(entry).isFile());
    const before = outputDigest(output);
    assert.equal(before, required('PC_BROWSER_OUTPUT_SHA256'));
    const temporary = realpathSync(required('TMPDIR'));
    assert.ok(inside(resolve(root, '.local/tmp'), temporary));
    const evidence = resolve(required('PC_BROWSER_EVIDENCE_DIR'));
    assert.ok(inside(resolve(root, '.local/evidence'), evidence));
    mkdirSync(evidence, { recursive: true, mode: 0o700 });
    assert.equal(realpathSync(evidence), evidence);
    assert.deepEqual(
      readdirSync(evidence),
      [],
      'Never overwrite previous evidence'
    );
    const registry = json(
      resolve(root, 'resources/project-resources.json')
    ).resources;
    const find = (collection, id) => {
      const matches = collection.filter(
        (item) => item.stable_resource_id === id
      );
      assert.equal(matches.length, 1);
      return matches[0];
    };
    const ssr = find(registry.local_listeners, 'peanut-pc-ssr-qualification');
    const api = find(
      registry.local_listeners,
      'peanut-pc-ssr-synthetic-upstream'
    );
    const tool = find(registry.tooling, 'peanut-pc-browser-qualification');
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
    assert.equal(fields.worktree, root);
    assert.equal(fields.status, 'ACTIVE');
    assert.ok(Number(fields.expires_at) > Date.now() / 1000 + 150);
    const head = execFileSync('git', ['rev-parse', 'HEAD'], {
      cwd: root,
      encoding: 'utf8',
    }).trim();
    assert.equal(fields.candidate, head);
    assert.ok(lines.includes(`tooling\t${tool.stable_resource_id}`));
    assert.ok(lines.includes(`output-dir\t${evidence}`));
    for (const item of [ssr, api]) {
      assert.equal(item.host, '127.0.0.1');
      assert.equal(item.environment, 'development-test');
      assert.ok(lines.includes(`port\t${item.port}`));
      assert.ok(lines.includes(`listener\t${item.stable_resource_id}`));
      await freePort(item.port);
    }
    const playwrightRoot = realpathSync(required('PC_BROWSER_PLAYWRIGHT_ROOT'));
    const manifest = json(resolve(playwrightRoot, 'package.json'));
    assert.equal(manifest.name, 'playwright');
    assert.equal(manifest.version, tool.version);
    const { chromium } = createRequire(resolve(playwrightRoot, 'package.json'))(
      'playwright'
    );
    assert.ok(
      existsSync(chromium.executablePath()),
      'Use the installed browser, do not download implicitly'
    );
    const upstream = createSsrUpstream();
    let child;
    let browser;
    let childLog = '';
    let browserVersion = null;
    const results = [];
    const pageErrors = [];
    const hydrationWarnings = [];
    const dialogs = [];
    const externalRequests = [];
    const clientRequests = [];
    const screenshotNames = [];
    t.after(async () => {
      if (browser) await browser.close();
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
        resolve(evidence, 'requests.json'),
        JSON.stringify(
          {
            upstream: upstream.requests,
            client: clientRequests,
            external: externalRequests,
          },
          null,
          2
        ) + '\n'
      );
      writeFileSync(
        resolve(evidence, 'summary.json'),
        JSON.stringify(
          {
            status:
              results.length === 7 &&
              pageErrors.length === 0 &&
              hydrationWarnings.length === 0 &&
              dialogs.length === 0 &&
              externalRequests.length === 0 &&
              before === after
                ? 'passed'
                : 'failed',
            results,
            pageErrors,
            hydrationWarnings,
            dialogs,
            externalRequests,
            code_commit: head,
            pc_root: pcRoot,
            output_before: before,
            output_after: after,
            playwright_version: manifest.version,
            browser_version: browserVersion,
            screenshot_names: screenshotNames,
            scope:
              'Real Chromium and unmodified Node build; browser API requests use an explicit synthetic HTTP fixture, not PHP, MySQL or a deployed reverse proxy',
          },
          null,
          2
        ) + '\n'
      );
      assert.equal(
        after,
        before,
        'Browser qualification must not rewrite native output'
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
        NUXT_TRUSTED_HOSTS: hosts.join(','),
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
        const res = await fetch(`http://${ssr.host}:${ssr.port}/healthz`);
        if (res.ok) {
          ready = true;
          break;
        }
      } catch (error) {
        if (error.cause?.code !== 'ECONNREFUSED') throw error;
      }
      await new Promise((done) => setTimeout(done, 100));
    }
    assert.ok(ready, childLog);
    browser = await chromium.launch({
      headless: true,
      chromiumSandbox: true,
      args: [
        `--host-resolver-rules=${hosts
          .map((host) => `MAP ${host} 127.0.0.1:${ssr.port}`)
          .join(', ')}`,
      ],
    });
    browserVersion = browser.version();
    const contexts = [];
    const pages = [];
    for (const host of hosts) {
      const context = await browser.newContext({
        viewport: { width: 1365, height: 900 },
      });
      contexts.push(context);
      await context.route('**/*', async (route) => {
        const request = route.request();
        const url = new URL(request.url());
        if (!hosts.includes(url.hostname)) {
          externalRequests.push({ host: url.hostname, path: url.pathname });
          return route.abort();
        }
        if (url.pathname.startsWith('/api/')) {
          clientRequests.push({
            host: url.hostname,
            path: url.pathname,
            method: request.method(),
          });
          // Explicit synthetic API boundary. Do not replace application JS or DOM.
          const response = await fetch(
            `http://${api.host}:${api.port}${url.pathname}${url.search}`,
            {
              method: request.method(),
              headers: {
                'x-forwarded-host': url.hostname,
                'x-forwarded-proto': 'https',
              },
              body: ['GET', 'HEAD'].includes(request.method())
                ? undefined
                : request.postData(),
            }
          );
          return route.fulfill({
            status: response.status,
            contentType: 'application/json',
            body: await response.text(),
          });
        }
        return route.continue();
      });
      const page = await context.newPage();
      page.on('pageerror', (error) =>
        pageErrors.push({ host, message: error.message })
      );
      page.on('console', (message) => {
        if (/hydration|mismatch/i.test(message.text()))
          hydrationWarnings.push({
            host,
            type: message.type(),
            message: message.text(),
          });
      });
      page.on('dialog', async (dialog) => {
        dialogs.push({ host, message: dialog.message() });
        await dialog.dismiss();
      });
      pages.push(page);
    }
    const check = async (name, body) =>
      t.test(name, async () => {
        await body();
        results.push(name);
      });
    const assertArticle = async (page, name, other) => {
      await page
        .locator('h1')
        .filter({ hasText: `${name} article` })
        .waitFor();
      await page.locator('div.prose').waitFor();
      assert.match(
        await page.locator('div.prose').innerText(),
        new RegExp(`${name} 正文`)
      );
      assert.ok(
        !(await page.locator('body').innerText()).includes(`${other} article`)
      );
      assert.equal(
        await page.locator('div.prose script, div.prose [onerror]').count(),
        0
      );
      assert.equal(
        await page.getByRole('button', { name: '已收藏', exact: true }).count(),
        0
      );
    };
    await check(
      'both hosts hydrate actual scripts without cross-tenant content',
      async () => {
        const responses = await Promise.all(
          pages.map((page, i) =>
            page.goto(`http://${hosts[i]}/information/detail/1`)
          )
        );
        for (const response of responses) assert.equal(response.status(), 200);
        await assertArticle(pages[0], 'Tenant A', 'Tenant B');
        await assertArticle(pages[1], 'Tenant B', 'Tenant A');
        assert.deepEqual(pageErrors, []);
        assert.deepEqual(hydrationWarnings, []);
      }
    );
    await check(
      'hydrated rich text is safe and stable after reload',
      async () => {
        for (let i = 0; i < pages.length; i++) {
          await pages[i].reload();
          await assertArticle(
            pages[i],
            i === 0 ? 'Tenant A' : 'Tenant B',
            i === 0 ? 'Tenant B' : 'Tenant A'
          );
          const name = `tenant-${i === 0 ? 'a' : 'b'}.png`;
          await pages[i].screenshot({
            path: resolve(evidence, name),
            fullPage: true,
          });
          screenshotNames.push(name);
        }
        assert.deepEqual(dialogs, []);
        assert.deepEqual(externalRequests, []);
      }
    );
    await check(
      'anonymous collect click reaches real login UI without writes',
      async () => {
        await pages[0]
          .getByRole('button', { name: '收藏', exact: true })
          .click();
        await pages[0].waitForURL(`http://${hosts[0]}/login`);
        await pages[0].getByPlaceholder('账号', { exact: true }).waitFor();
        await pages[0].getByPlaceholder('密码', { exact: true }).waitFor();
        assert.equal(
          clientRequests.filter(
            (request) => !['GET', 'HEAD'].includes(request.method)
          ).length,
          0
        );
        assert.ok(
          !(await pages[0].locator('body').innerText()).includes('Tenant B')
        );
      }
    );
    await check(
      'invalid article identifier does not become a valid tenant article',
      async () => {
        const response = await pages[0].goto(
          `http://${hosts[0]}/information/detail/not-an-id`
        );
        assert.equal(response.status(), 404);
        await pages[0].getByText('文章不存在', { exact: true }).waitFor();
        assert.equal(await pages[0].locator('div.prose').count(), 0);
      }
    );
    await check(
      'missing article renders the browser empty state, not another tenant',
      async () => {
        upstream.state.mode = 'missing';
        try {
          const response = await pages[1].goto(
            `http://${hosts[1]}/information/detail/1`
          );
          assert.equal(response.status(), 404);
          await pages[1].getByText('文章不存在', { exact: true }).waitFor();
          assert.equal(await pages[1].locator('div.prose').count(), 0);
          assert.ok(
            !(await pages[1].locator('body').innerText()).includes(
              'Tenant A article'
            )
          );
        } finally {
          upstream.state.mode = 'normal';
        }
      }
    );
    await check(
      'upstream failure is not a browser article-not-found success',
      async () => {
        upstream.state.mode = 'service-failure';
        try {
          const response = await pages[1].goto(
            `http://${hosts[1]}/information/detail/1`
          );
          assert.ok(response.status() >= 500 && response.status() < 600);
          await pages[1].locator('body').waitFor();
          assert.equal(
            await pages[1].getByText('文章不存在', { exact: true }).count(),
            0
          );
          assert.equal(await pages[1].locator('div.prose').count(), 0);
        } finally {
          upstream.state.mode = 'normal';
        }
        await pages[1].goto(`http://${hosts[1]}/information/detail/1`);
        await assertArticle(pages[1], 'Tenant B', 'Tenant A');
      }
    );
    await check(
      'browser execution completed without script errors or unsafe dialogs',
      () => {
        assert.deepEqual(pageErrors, []);
        assert.deepEqual(hydrationWarnings, []);
        assert.deepEqual(dialogs, []);
        assert.deepEqual(externalRequests, []);
        assert.ok(upstream.requests.length > 0);
        assert.ok(clientRequests.some((request) => request.host === hosts[0]));
        assert.ok(clientRequests.some((request) => request.host === hosts[1]));
      }
    );
  }
);
