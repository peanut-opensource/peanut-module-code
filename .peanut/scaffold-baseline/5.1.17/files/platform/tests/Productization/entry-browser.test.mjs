import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import {
  lstatSync,
  mkdirSync,
  readFileSync,
  readdirSync,
  realpathSync,
  writeFileSync,
} from 'node:fs';
import { createServer } from 'node:http';
import { createServer as createPortProbe } from 'node:net';
import { extname, resolve, sep } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { test } from 'node:test';

const root = realpathSync(fileURLToPath(new URL('../../../', import.meta.url)));
const host = 'platform-entry.localhost';

const required = (key) => {
  assert.ok(process.env[key], `${key} is required; no implicit fallback`);
  return process.env[key];
};
const inside = (parent, value) =>
  value === parent || value.startsWith(parent + sep);
const json = (path) => JSON.parse(readFileSync(path, 'utf8'));

function outputDigest(directory) {
  const hash = createHash('sha256');
  const visit = (relative = '') => {
    for (const name of readdirSync(resolve(directory, relative)).sort()) {
      const path = relative ? `${relative}/${name}` : name;
      const absolute = resolve(directory, path);
      const stat = lstatSync(absolute);
      assert.ok(!stat.isSymbolicLink(), 'Build links are not accepted');
      assert.ok(stat.isFile() || stat.isDirectory(), 'Special build entry');
      hash.update(`${path}\0${stat.mode}\0`);
      if (stat.isDirectory()) visit(path);
      else hash.update(readFileSync(absolute));
    }
  };
  visit();
  return hash.digest('hex');
}

async function freePort(port) {
  const probe = createPortProbe();
  await new Promise((done, reject) => {
    probe.once('error', reject);
    probe.listen(port, '127.0.0.1', done);
  });
  await new Promise((done, reject) =>
    probe.close((error) => (error ? reject(error) : done()))
  );
}

const envelope = (code, msg, data = null) =>
  JSON.stringify({ code, msg, data });

function createPlatformFixture(build, port) {
  const requests = [];
  const violations = [];
  const state = {
    loginMode: 'invalid',
    inspectMode: 'invalid-html',
    pendingDelayMs: 700,
  };
  const server = createServer((request, response) => {
    const hostHeader = request.headers.host || '';
    const url = new URL(request.url, `http://${hostHeader || host}`);
    const send = (status, code, msg, data = null) => {
      response.writeHead(status, {
        'content-type': 'application/json; charset=utf-8',
        'cache-control': 'no-store',
      });
      response.end(envelope(code, msg, data));
    };
    if (hostHeader !== `${host}:${port}`) {
      violations.push({
        host: hostHeader,
        method: request.method,
        path: url.pathname,
      });
      return send(421, 42100, 'Synthetic host rejected');
    }
    if (
      url.pathname.startsWith('/platformapi/') ||
      url.pathname.startsWith('/adminapi/')
    ) {
      requests.push({
        method: request.method,
        path: url.pathname + url.search,
        authorizationPresent: Boolean(request.headers.authorization),
        cookiePresent: Boolean(request.headers.cookie),
      });
      if (
        url.pathname === '/platformapi/session/login' &&
        request.method === 'POST'
      ) {
        request.resume();
        if (state.loginMode === 'slow-invalid') {
          return setTimeout(
            () =>
              send(
                200,
                40100,
                'Platform authentication credential is invalid.',
                {
                  error_code: 'PLATFORM_AUTHENTICATION_INVALID',
                }
              ),
            state.pendingDelayMs
          );
        }
        if (state.loginMode === 'unavailable') {
          return send(
            503,
            50300,
            '平台登录服务暂不可用 <img src=x onerror="alert(503)">'
          );
        }
        return send(
          200,
          40100,
          'Platform authentication credential is invalid.',
          {
            error_code: 'PLATFORM_AUTHENTICATION_INVALID',
          }
        );
      }
      if (
        url.pathname === '/platformapi/session/info' &&
        request.method === 'GET'
      ) {
        return send(401, 40100, 'Platform session is invalid.', {
          error_code: 'PLATFORM_SESSION_INVALID',
        });
      }
      if (
        url.pathname === '/adminapi/tenant/owner-invitations/inspect' &&
        request.method === 'GET'
      ) {
        const token = url.searchParams.get('token');
        if (state.inspectMode === 'expired' && token === 'expired-token') {
          return send(
            200,
            41000,
            '邀请已过期 <script>window.__platformInviteExecuted = true</script>'
          );
        }
        return send(
          200,
          40400,
          '邀请不可用 <img src=x onerror="window.__platformInviteExecuted = true">'
        );
      }
      if (
        url.pathname === '/adminapi/tenant/owner-invitations/accept' &&
        request.method === 'POST'
      ) {
        violations.push({
          method: request.method,
          path: url.pathname,
          reason: 'accept',
        });
        request.resume();
        return send(405, 40500, 'Accept is not part of this browser slice');
      }
      violations.push({ method: request.method, path: url.pathname });
      return send(404, 40400, 'Unexpected synthetic API request');
    }
    if (!['GET', 'HEAD'].includes(request.method)) {
      violations.push({ method: request.method, path: url.pathname });
      return send(405, 40500, 'Static method rejected');
    }
    if (!url.pathname.startsWith('/platform/')) {
      violations.push({ method: request.method, path: url.pathname });
      response.writeHead(404);
      response.end();
      return;
    }
    let relative = decodeURIComponent(url.pathname.slice('/platform/'.length));
    if (relative === '') relative = 'index.html';
    let file;
    try {
      file = resolve(build, relative);
    } catch {
      response.writeHead(400);
      response.end();
      return;
    }
    if (!inside(build, file)) {
      response.writeHead(403);
      response.end();
      return;
    }
    try {
      const stat = lstatSync(file);
      if (!stat.isFile()) throw new Error('not a file');
      const mime = {
        '.html': 'text/html; charset=utf-8',
        '.js': 'text/javascript; charset=utf-8',
        '.css': 'text/css; charset=utf-8',
        '.svg': 'image/svg+xml',
        '.png': 'image/png',
        '.ico': 'image/x-icon',
        '.woff2': 'font/woff2',
        '.ttf': 'font/ttf',
      };
      response.writeHead(200, {
        'content-type': mime[extname(file)] || 'application/octet-stream',
        'cache-control': 'no-store',
      });
      response.end(request.method === 'HEAD' ? undefined : readFileSync(file));
    } catch {
      response.writeHead(404);
      response.end();
    }
  });
  return { server, requests, violations, state };
}

test(
  'native platform browser entry and failure-state contracts',
  { timeout: 120000 },
  async (t) => {
    const project = realpathSync(required('MANAGEMENT_BROWSER_PROJECT_ROOT'));
    const build = realpathSync(resolve(project, 'dist'));
    const temporary = realpathSync(required('TMPDIR'));
    assert.ok(inside(resolve(root, '.local/tmp'), temporary));
    const evidence = resolve(required('MANAGEMENT_BROWSER_EVIDENCE_DIR'));
    assert.ok(inside(resolve(root, '.local/evidence'), evidence));
    mkdirSync(evidence, { recursive: true, mode: 0o700 });
    assert.equal(realpathSync(evidence), evidence);
    assert.deepEqual(
      readdirSync(evidence),
      [],
      'Never overwrite previous evidence'
    );

    const before = outputDigest(build);
    assert.equal(before, required('MANAGEMENT_BROWSER_OUTPUT_SHA256'));
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
    const listener = find(
      registry.local_listeners,
      'peanut-platform-entry-fixture'
    );
    const tooling = find(registry.tooling, 'peanut-platform-entry-browser');
    assert.equal(listener.host, '127.0.0.1');
    assert.equal(listener.port, 20494);
    assert.equal(listener.environment, 'development-test');
    assert.equal(listener.owner, 'platform-entry-qualification');
    assert.equal(tooling.owner, listener.owner);
    const lease = execFileSync(
      'bash',
      [
        resolve(root, 'scripts/project-resource-lease'),
        'show',
        '--lease',
        required('MANAGEMENT_BROWSER_LEASE_ID'),
      ],
      { cwd: root, encoding: 'utf8', timeout: 10000 }
    );
    const lines = lease.trim().split('\n');
    const boundary = lines.indexOf('resources');
    assert.ok(boundary > 0);
    const fields = Object.fromEntries(
      lines.slice(0, boundary).map((line) => line.split('\t'))
    );
    const head = execFileSync('git', ['rev-parse', 'HEAD'], {
      cwd: root,
      encoding: 'utf8',
    }).trim();
    assert.equal(fields.owner, listener.owner);
    assert.equal(fields.gate, 'platform-entry-qualification');
    assert.equal(fields.candidate, head);
    assert.equal(fields.worktree, root);
    assert.equal(fields.status, 'ACTIVE');
    assert.ok(Number(fields.expires_at) > Date.now() / 1000 + 150);
    for (const value of [
      `port\t${listener.port}`,
      `listener\t${listener.stable_resource_id}`,
      `tooling\t${tooling.stable_resource_id}`,
      `output-dir\t${evidence}`,
      `tmpdir\t${temporary}`,
    ])
      assert.ok(lines.includes(value), `Lease missing ${value}`);
    await freePort(listener.port);

    const playwrightRoot = realpathSync(
      required('MANAGEMENT_BROWSER_PLAYWRIGHT_ROOT')
    );
    const { version, name } = json(resolve(playwrightRoot, 'package.json'));
    assert.equal(name, 'playwright');
    assert.equal(version, tooling.version);
    const { chromium } = await import(
      pathToFileURL(resolve(playwrightRoot, 'index.mjs')).href
    );
    assert.ok(lstatSync(chromium.executablePath()).isFile());

    const fixture = createPlatformFixture(build, listener.port);
    const results = [];
    const pageErrors = [];
    const dialogs = [];
    const consoleErrors = [];
    const externalRequests = [];
    let browser;
    let browserVersion;
    let current = 'startup';

    t.after(async () => {
      if (browser) await browser.close();
      if (fixture.server.listening) {
        fixture.server.closeAllConnections();
        await new Promise((done, reject) =>
          fixture.server.close((error) => (error ? reject(error) : done()))
        );
      }
      const after = outputDigest(build);
      writeFileSync(
        resolve(evidence, 'requests.json'),
        JSON.stringify(fixture.requests, null, 2) + '\n'
      );
      writeFileSync(
        resolve(evidence, 'summary.json'),
        JSON.stringify(
          {
            status:
              results.length === 6 &&
              pageErrors.length === 0 &&
              dialogs.length === 0 &&
              externalRequests.length === 0 &&
              fixture.violations.length === 0 &&
              before === after
                ? 'passed'
                : 'failed',
            results,
            pageErrors,
            dialogs,
            consoleErrors,
            externalRequests,
            fixtureViolations: fixture.violations,
            code_commit: head,
            platform_root: project,
            output_before: before,
            output_after: after,
            playwright_version: version,
            browser_version: browserVersion,
            scope:
              'Real Chromium and original platform static assets; synthetic platform login and invitation inspect API only, no PHP/MySQL/tenant backend/platform success session',
          },
          null,
          2
        ) + '\n'
      );
      assert.equal(after, before, 'Do not mutate existing native artifacts');
      await freePort(listener.port);
    });

    await new Promise((done, reject) => {
      fixture.server.once('error', reject);
      fixture.server.listen(listener.port, listener.host, done);
    });
    browser = await chromium.launch({
      headless: true,
      chromiumSandbox: true,
      env: { ...process.env, TMPDIR: temporary },
    });
    browserVersion = browser.version();
    const context = await browser.newContext({
      viewport: { width: 1366, height: 900 },
      serviceWorkers: 'block',
      ignoreHTTPSErrors: false,
    });
    await context.route('**/*', async (route) => {
      const url = new URL(route.request().url());
      if (url.origin !== `http://${host}:${listener.port}`) {
        externalRequests.push({ scenario: current, url: url.href });
        await route.abort();
      } else await route.continue();
    });
    const page = await context.newPage();
    page.setDefaultTimeout(10000);
    page.on('pageerror', (error) =>
      pageErrors.push({
        scenario: current,
        message: error.message,
        stack: error.stack,
      })
    );
    page.on('dialog', async (dialog) => {
      dialogs.push({ scenario: current, message: dialog.message() });
      await dialog.dismiss();
    });
    page.on('console', (message) => {
      if (message.type() === 'error')
        consoleErrors.push({ scenario: current, message: message.text() });
    });

    const entry = (suffix = '') =>
      `http://${host}:${listener.port}/platform/${suffix}`;
    const appShell = page.locator('.app-shell');
    const loginButton = page.getByRole('button', { name: '登录实例平台' });
    const fillLogin = async (email = 'operator@example.test') => {
      await page.getByPlaceholder('实例平台操作员邮箱').fill(email);
      await page.getByPlaceholder('密码').fill('synthetic-password-not-logged');
    };
    const check = async (name, action) =>
      t.test(name, async () => {
        current = name;
        try {
          await action();
          results.push(name);
        } catch (error) {
          writeFileSync(
            resolve(
              evidence,
              `failure-${name.replace(/[^a-zA-Z0-9_-]/g, '-')}.html`
            ),
            await page.content()
          );
          throw error;
        }
      });

    await check(
      'anonymous platform login renders without management data requests',
      async () => {
        await page.goto(entry());
        await page.getByRole('heading', { name: '实例平台管理' }).waitFor();
        assert.equal(await appShell.count(), 0);
        assert.equal(fixture.requests.length, 0);
      }
    );

    await check(
      '401 login error is visible and does not open app shell',
      async () => {
        await fillLogin();
        await loginButton.click();
        await page
          .getByText('平台登录凭据无效，请重新登录。', { exact: true })
          .waitFor();
        assert.equal(await appShell.count(), 0);
        assert.equal(
          fixture.requests.filter(
            (request) => request.path === '/platformapi/session/login'
          ).length,
          1
        );
      }
    );

    await check(
      '503 login failure recovers for a later submit without success shell',
      async () => {
        fixture.state.loginMode = 'unavailable';
        await fillLogin('temporary-outage@example.test');
        await loginButton.click();
        await page
          .getByText('平台登录服务暂不可用 <img src=x onerror="alert(503)">', {
            exact: true,
          })
          .waitFor();
        assert.equal(await appShell.count(), 0);
        fixture.state.loginMode = 'invalid';
        await loginButton.click();
        await page
          .getByText('平台登录凭据无效，请重新登录。', { exact: true })
          .waitFor();
        assert.equal(await appShell.count(), 0);
        assert.equal(dialogs.length, 0);
      }
    );

    await check(
      'rapid repeated submit sends one login request while pending',
      async () => {
        fixture.state.loginMode = 'slow-invalid';
        const beforeCount = fixture.requests.length;
        await fillLogin('double-click@example.test');
        // Exercise real keyboard repeats; never force a disabled button.
        const password = page.getByPlaceholder('密码');
        await password.press('Enter');
        await password.press('Enter');
        await page
          .getByText('平台登录凭据无效，请重新登录。', { exact: true })
          .waitFor();
        const delta = fixture.requests
          .slice(beforeCount)
          .filter((request) => request.path === '/platformapi/session/login');
        assert.equal(delta.length, 1);
        assert.equal(await appShell.count(), 0);
        fixture.state.loginMode = 'invalid';
      }
    );

    await check(
      'invalid invitation inspect is read-only and has no accept action',
      async () => {
        await page.goto(entry('?invitation=invalid-token'));
        await page.getByText('邀请不可用', { exact: true }).waitFor();
        await page
          .getByText(
            '邀请不可用 <img src=x onerror="window.__platformInviteExecuted = true">',
            { exact: true }
          )
          .waitFor();
        assert.equal(
          await page.getByRole('button', { name: '接受邀请' }).count(),
          0
        );
        assert.equal(
          await page.evaluate(() =>
            Boolean(globalThis.__platformInviteExecuted)
          ),
          false
        );
        assert.equal(dialogs.length, 0);
      }
    );

    await check(
      'expired invitation inspect shows text error without executable markup',
      async () => {
        fixture.state.inspectMode = 'expired';
        await page.goto(entry('?invitation=expired-token'));
        await page.getByText('邀请不可用', { exact: true }).waitFor();
        await page
          .getByText(
            '邀请已过期 <script>window.__platformInviteExecuted = true</script>',
            { exact: true }
          )
          .waitFor();
        assert.equal(
          await page.getByRole('button', { name: '接受邀请' }).count(),
          0
        );
        assert.equal(
          await page.evaluate(() =>
            Boolean(globalThis.__platformInviteExecuted)
          ),
          false
        );
        assert.equal(
          fixture.requests.filter((request) =>
            request.path.startsWith('/adminapi/tenant/owner-invitations/accept')
          ).length,
          0
        );
      }
    );

    assert.deepEqual(pageErrors, []);
    assert.deepEqual(dialogs, []);
    assert.deepEqual(externalRequests, []);
    assert.deepEqual(fixture.violations, []);
  }
);
