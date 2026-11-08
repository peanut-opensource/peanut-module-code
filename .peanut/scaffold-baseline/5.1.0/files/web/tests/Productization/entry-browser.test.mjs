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

const root = fileURLToPath(new URL('../../../', import.meta.url));
const ADMIN_HOST = 'admin-entry.localhost';
const TOOLING_ID = 'peanut-admin-entry-browser';
const LISTENER_ID = 'peanut-admin-entry-fixture';
const OWNER = 'admin-entry-qualification';
const EXPECTED_DIGEST =
  '2bfa90699f245b56893993f9b54e2d4b5f7cba714dddbffbff8f40b4dad3cc88';

const required = (key) => {
  assert.ok(process.env[key], `${key} is required; no implicit fallback`);
  return process.env[key];
};

const inside = (parent, path) =>
  path === parent || path.startsWith(parent + sep);

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

function envelope(response, status, code, data, msg = 'synthetic fixture') {
  response.writeHead(status, {
    'content-type': 'application/json',
    'cache-control': 'no-store',
  });
  response.end(JSON.stringify({ code, msg, data }));
}

function installedStatus() {
  return {
    state: 'installed',
    mode: 'guided',
    deployment_mode: 'multi-tenant',
    preflight: { status: 'ready', checks: [] },
    health: { status: 'ok' },
  };
}

function uninstalledStatus() {
  return {
    state: 'uninstalled',
    mode: 'guided',
    deployment_mode: 'multi-tenant',
    preflight: {
      status: 'ready',
      checks: [],
      official_modules: [
        {
          key: 'official.article',
          label: 'Official Article',
          required: true,
          default: true,
        },
      ],
    },
    official_modules: [
      {
        key: 'official.article',
        label: 'Official Article',
        required: true,
        default: true,
      },
    ],
  };
}

function automaticStatus() {
  return {
    state: 'uninstalled',
    mode: 'automatic',
    deployment_mode: 'multi-tenant',
    preflight: { status: 'ready', checks: [] },
  };
}

function publicBrand() {
  const logo = '/admin/brand/logo.svg';
  return {
    tenantName: 'Synthetic Tenant',
    demo: { enabled: false, email: '', password: '' },
    website: {
      name: 'Peanut Module Code',
      web_favicon: '/admin/brand/favicon.svg',
      web_logo: logo,
      login_image: '/admin/brand/login-background.svg',
      shop_name: 'Synthetic Shop',
      shop_logo: logo,
      pc_logo: logo,
      pc_title: 'Peanut Module Code',
      pc_ico: '/admin/brand/favicon.svg',
      pc_desc: 'Synthetic management entry',
      pc_keywords: 'peanut',
      h5_favicon: '/admin/brand/favicon.svg',
      slogan: 'Synthetic login fixture',
      copyright: 'Synthetic',
      official_url: '',
      github_url: '',
    },
  };
}

function createAdminFixture(build, port) {
  const state = { mode: 'installed' };
  const requests = [];
  const loginAttempts = [];
  const violations = [];
  const server = createServer((request, response) => {
    const host = request.headers.host || '';
    const path = new URL(request.url, 'http://fixture.invalid');
    const record = {
      host,
      method: request.method,
      path: path.pathname + path.search,
      authorizationPresent: Boolean(request.headers.authorization),
      cookiePresent: Boolean(request.headers.cookie),
      mode: state.mode,
    };
    if (host !== `${ADMIN_HOST}:${port}`) {
      violations.push({ ...record, reason: 'wrong-host' });
      return envelope(response, 421, 42100, null);
    }
    const collectBody = async () => {
      const chunks = [];
      for await (const chunk of request) chunks.push(chunk);
      return Buffer.concat(chunks).toString('utf8');
    };
    const sendStatus = () => {
      if (state.mode === 'status-503') {
        return envelope(
          response,
          503,
          50300,
          null,
          'synthetic status unavailable'
        );
      }
      if (state.mode === 'damaged') {
        return envelope(response, 200, 20000, {
          state: ['installed'],
          mode: 'guided',
          deployment_mode: 'multi-tenant',
          preflight: null,
        });
      }
      if (state.mode === 'uninstalled') {
        return envelope(response, 200, 20000, uninstalledStatus());
      }
      if (state.mode === 'automatic') {
        return envelope(response, 200, 20000, automaticStatus());
      }
      return envelope(response, 200, 20000, installedStatus());
    };
    if (path.pathname.startsWith('/installapi/')) {
      requests.push(record);
      if (request.method !== 'GET' || path.pathname !== '/installapi/status') {
        violations.push({ ...record, reason: 'unexpected-install-api' });
        return envelope(response, 405, 40500, null);
      }
      return sendStatus();
    }
    if (path.pathname === '/api/index/config') {
      requests.push(record);
      if (request.method !== 'GET') {
        violations.push({ ...record, reason: 'unexpected-brand-method' });
        return envelope(response, 405, 40500, null);
      }
      return envelope(response, 200, 20000, publicBrand());
    }
    if (path.pathname === '/adminapi/tenant/session/login') {
      requests.push(record);
      if (request.method !== 'POST') {
        violations.push({ ...record, reason: 'unexpected-login-method' });
        return envelope(response, 405, 40500, null);
      }
      collectBody()
        .then((body) => {
          const parsed = JSON.parse(body || '{}');
          loginAttempts.push({
            emailPresent: typeof parsed.email === 'string',
            passwordPresent: typeof parsed.password === 'string',
            tenantIdPresent: parsed.tenantId !== undefined,
          });
          setTimeout(
            () =>
              envelope(
                response,
                200,
                40100,
                null,
                'Synthetic invalid credentials'
              ),
            700
          );
        })
        .catch(() => envelope(response, 400, 40000, null));
      return undefined;
    }
    if (path.pathname.startsWith('/adminapi/')) {
      requests.push(record);
      violations.push({ ...record, reason: 'unexpected-private-api' });
      return envelope(response, 403, 40300, null);
    }
    if (!['GET', 'HEAD'].includes(request.method)) {
      violations.push({ ...record, reason: 'unexpected-static-method' });
      response.writeHead(405);
      response.end();
      return undefined;
    }
    if (!path.pathname.startsWith('/admin/')) {
      violations.push({ ...record, reason: 'outside-admin-base' });
      response.writeHead(404);
      response.end();
      return undefined;
    }
    let relativePath = path.pathname.slice('/admin/'.length);
    if (!relativePath || !extname(relativePath)) {
      relativePath = 'index.html';
    }
    let file;
    try {
      file = resolve(build, decodeURIComponent(relativePath));
    } catch {
      response.writeHead(400);
      response.end();
      return undefined;
    }
    if (!inside(build, file)) {
      response.writeHead(403);
      response.end();
      return undefined;
    }
    try {
      const stat = lstatSync(file);
      if (!stat.isFile() || stat.isSymbolicLink()) {
        throw new Error('not a static file');
      }
      const mime = {
        '.html': 'text/html',
        '.js': 'text/javascript',
        '.css': 'text/css',
        '.svg': 'image/svg+xml',
        '.png': 'image/png',
        '.woff2': 'font/woff2',
        '.ttf': 'font/ttf',
        '.gz': 'application/gzip',
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
    return undefined;
  });
  return { server, state, requests, loginAttempts, violations };
}

async function waitForLogin(page) {
  await page.getByText('管理员登录', { exact: true }).waitFor();
  await page.getByPlaceholder('账号邮箱').waitFor();
  await page.getByPlaceholder('密码').waitFor();
}

async function waitForInstallationForm(page) {
  await page.getByRole('heading', { name: /^安装 / }).waitFor();
  await page.getByText('一次性安装令牌', { exact: true }).waitFor();
  await page.getByText('开始安装', { exact: true }).waitFor();
}

async function waitForBlockedInstallation(page, text) {
  await page.getByText('安装暂不可用', { exact: true }).waitFor();
  await page.getByText(text, { exact: false }).waitFor();
  await page.getByText('重新检查', { exact: true }).waitFor();
}

test(
  'native admin browser entry, installation guard and login failure contracts',
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
    assert.deepEqual(readdirSync(evidence), [], 'Preserve prior evidence');

    const expected = required('MANAGEMENT_BROWSER_OUTPUT_SHA256');
    assert.equal(expected, EXPECTED_DIGEST);
    const before = outputDigest(build);
    assert.equal(before, expected);

    const registry = JSON.parse(
      readFileSync(resolve(root, 'resources/project-resources.json'))
    ).resources;
    const find = (items, id) => {
      const matches = items.filter((item) => item.stable_resource_id === id);
      assert.equal(matches.length, 1, `resource ${id} must be unique`);
      return matches[0];
    };
    const listener = find(registry.local_listeners, LISTENER_ID);
    const tooling = find(registry.tooling, TOOLING_ID);
    assert.equal(listener.host, '127.0.0.1');
    assert.equal(listener.port, 20495);
    assert.equal(listener.environment, 'development-test');
    assert.equal(listener.owner, OWNER);
    assert.equal(tooling.owner, OWNER);

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
    assert.equal(fields.owner, OWNER);
    assert.equal(fields.gate, OWNER);
    assert.equal(fields.candidate, head);
    assert.equal(fields.worktree, realpathSync(root));
    assert.ok(Number(fields.expires_at) > Date.now() / 1000 + 150);
    for (const value of [
      `port\t${listener.port}`,
      `listener\t${listener.stable_resource_id}`,
      `tooling\t${tooling.stable_resource_id}`,
      `output-dir\t${evidence}`,
      `tmpdir\t${temporary}`,
    ]) {
      assert.ok(lines.includes(value), `Lease missing ${value}`);
    }

    await freePort(listener.port);
    const playwrightRoot = realpathSync(
      required('MANAGEMENT_BROWSER_PLAYWRIGHT_ROOT')
    );
    const version = JSON.parse(
      readFileSync(resolve(playwrightRoot, 'package.json'))
    ).version;
    assert.equal(version, tooling.version);
    const { chromium } = await import(
      pathToFileURL(resolve(playwrightRoot, 'index.mjs')).href
    );
    assert.ok(
      lstatSync(chromium.executablePath()).isFile(),
      'No implicit browser download'
    );

    const fixture = createAdminFixture(build, listener.port);
    const results = [];
    const pageErrors = [];
    const consoleErrors = [];
    const dialogs = [];
    const externalRequests = [];
    let browser;
    let context;
    let page;
    let current = 'startup';
    let browserVersion;

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
              results.length === 7 &&
              before === after &&
              !pageErrors.length &&
              !dialogs.length &&
              !externalRequests.length &&
              !fixture.violations.length
                ? 'passed'
                : 'failed',
            results,
            pageErrors,
            consoleErrors,
            dialogs,
            externalRequests,
            fixtureViolations: fixture.violations,
            loginAttempts: fixture.loginAttempts,
            code_commit: head,
            web_root: project,
            output_before: before,
            output_after: after,
            playwright_version: version,
            browser_version: browserVersion,
            scope:
              'Real Chromium and original admin static assets; synthetic install status, public brand and invalid login API only; no PHP/MySQL/session success/install execution',
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
    context = await browser.newContext({
      viewport: { width: 1440, height: 900 },
      deviceScaleFactor: 1,
      serviceWorkers: 'block',
    });
    const allowedOrigin = `http://${ADMIN_HOST}:${listener.port}`;
    await context.route('**/*', async (route) => {
      const url = new URL(route.request().url());
      if (url.origin !== allowedOrigin) {
        externalRequests.push({ scenario: current, url: url.href });
        await route.abort();
      } else {
        await route.continue();
      }
    });
    page = await context.newPage();
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
      if (message.type() === 'error') {
        consoleErrors.push({ scenario: current, message: message.text() });
      }
    });

    const url = (path = '') => `${allowedOrigin}/admin/${path}`;
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
          await page.screenshot({
            path: resolve(
              evidence,
              `failure-${name.replace(/[^a-zA-Z0-9_-]/g, '-')}.png`
            ),
            fullPage: true,
          });
          throw error;
        }
      });

    await check(
      'installed status opens the real login UI and not protected content',
      async () => {
        fixture.state.mode = 'installed';
        await page.goto(url());
        await waitForLogin(page);
        const body = await page.locator('body').innerText();
        assert.match(body, /Synthetic login fixture/);
        assert.doesNotMatch(body, /工作台|系统管理|网站设置/);
        assert.equal(
          fixture.requests.some((request) =>
            request.path.startsWith('/adminapi/user/info')
          ),
          false
        );
      }
    );

    await check(
      'empty login fields stay client-side and submit no login request',
      async () => {
        fixture.state.mode = 'installed';
        await page.goto(url('login'));
        await waitForLogin(page);
        const beforeCount = fixture.loginAttempts.length;
        await page.getByRole('button', { name: '登录' }).click();
        await page.getByText('账号邮箱不能为空', { exact: true }).waitFor();
        assert.equal(fixture.loginAttempts.length, beforeCount);
      }
    );

    await check(
      'invalid login reports failure and suppresses duplicate pending submit',
      async () => {
        fixture.state.mode = 'installed';
        await page.goto(url('login'));
        await waitForLogin(page);
        const beforeCount = fixture.loginAttempts.length;
        await page.getByPlaceholder('账号邮箱').fill('admin@example.test');
        await page.getByPlaceholder('密码').fill('wrong-password');
        const button = page.getByRole('button', { name: '登录' });
        await page.getByPlaceholder('密码').press('Enter');
        await page.getByPlaceholder('密码').press('Enter');
        await page
          .getByText('Synthetic invalid credentials', { exact: true })
          .waitFor();
        assert.equal(fixture.loginAttempts.length, beforeCount + 1);
        assert.equal(
          page.url().startsWith(`${allowedOrigin}/admin/login`),
          true
        );
      }
    );

    await check(
      'guided uninstalled state opens the real installation page only',
      async () => {
        fixture.state.mode = 'uninstalled';
        await page.goto(url());
        await waitForInstallationForm(page);
        assert.equal(
          await page.getByRole('heading', { name: /^安装 / }).count(),
          1
        );
        assert.equal(
          await page.getByRole('button', { name: '开始安装' }).count(),
          1
        );
      }
    );

    await check(
      'automatic deployment and installed installation route do not expose executable install',
      async () => {
        fixture.state.mode = 'automatic';
        await page.goto(url('installation'));
        await waitForLogin(page);
        assert.equal(
          await page.getByRole('button', { name: '开始安装' }).count(),
          0
        );
        fixture.state.mode = 'installed';
        await page.goto(url('installation'));
        await waitForLogin(page);
        assert.equal(
          await page.getByRole('button', { name: '开始安装' }).count(),
          0
        );
      }
    );

    await check(
      'status 503 and damaged status fail closed, then retry restores the right page',
      async () => {
        fixture.state.mode = 'status-503';
        await page.goto(url());
        await waitForBlockedInstallation(page, '无法读取安装状态。');
        fixture.state.mode = 'uninstalled';
        await page.getByRole('button', { name: '重新检查' }).click();
        await waitForInstallationForm(page);
        fixture.state.mode = 'damaged';
        await page.goto(url('installation'));
        await waitForBlockedInstallation(page, '无法读取安装状态。');
        fixture.state.mode = 'installed';
        await page.getByRole('button', { name: '重新检查' }).click();
        await waitForLogin(page);
      }
    );

    await check(
      'protected route redirects locally and external redirect never leaves the fixture',
      async () => {
        fixture.state.mode = 'installed';
        await page.goto(
          url('dashboard/workplace?redirect=https%3A%2F%2Fevil.example%2Fsteal')
        );
        await waitForLogin(page);
        assert.equal(
          page.url().startsWith(`${allowedOrigin}/admin/login`),
          true
        );
        assert.equal(page.url().includes('evil.example'), false);
      }
    );

    assert.deepEqual(pageErrors, []);
    assert.deepEqual(dialogs, []);
    assert.deepEqual(externalRequests, []);
    assert.deepEqual(fixture.violations, []);
    assert.ok(fixture.requests.length > 0);
    assert.ok(
      fixture.requests.every(
        (request) =>
          !request.authorizationPresent ||
          request.path === '/adminapi/tenant/session/login'
      )
    );
    assert.ok(fixture.requests.every((request) => !request.cookiePresent));
  }
);
