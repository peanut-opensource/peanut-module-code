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

// API data only; page HTML/JS/CSS and all clicks use the unmodified native build.
function createPublicFixture(build, port) {
  const modes = new Map();
  const requests = [];
  const violations = [];
  const hosts = ['mobile-a.localhost', 'mobile-b.localhost'];
  const server = createServer((request, response) => {
    const host = request.headers.host || '';
    const index = hosts.findIndex((name) => host === `${name}:${port}`);
    const path = new URL(request.url, 'http://fixture.invalid');
    const send = (status, code, data) => {
      response.writeHead(status, {
        'content-type': 'application/json',
        'cache-control': 'no-store',
      });
      response.end(
        JSON.stringify({ code, msg: 'synthetic public fixture', data })
      );
    };
    if (index < 0) return send(421, 42100, null);
    if (!['GET', 'HEAD'].includes(request.method)) {
      violations.push({ host, method: request.method, path: path.pathname });
      return send(405, 40500, null);
    }
    const tenant = index === 0 ? 'Mobile A' : 'Mobile B';
    const mode = modes.get(hosts[index]) || 'normal';
    const article = {
      id: 1,
      cid: 1,
      cate_name: 'News',
      title: `${tenant} article`,
      image: '/mobile/static/brand/logo.svg',
      desc: `${tenant} summary`,
      author: tenant,
      click: 1,
      create_time: '2026-09-28 00:00:00',
      collect: false,
      content: `<p>${tenant} 正文 <strong>保留加粗</strong></p><img src="/mobile/static/brand/logo.svg" onload="alert('h5-xss')"><script>alert('h5-xss')</script>`,
    };
    if (path.pathname.startsWith('/api/')) {
      requests.push({
        host,
        path: path.pathname + path.search,
        method: request.method,
        authorizationPresent: Boolean(request.headers.authorization),
        cookiePresent: Boolean(request.headers.cookie),
        mode,
      });
      if (path.pathname === '/api/index/config') {
        if (mode === 'config-failure') return send(503, 50300, null);
        const logo = '/mobile/static/brand/logo.svg';
        return send(200, 20000, {
          domain: host,
          version: 'synthetic',
          website: {
            name: tenant,
            shop_name: tenant,
            web_logo: logo,
            shop_logo: logo,
            pc_logo: logo,
            login_image: logo,
            h5_favicon: logo,
          },
          login: { login_way: [1] },
          web_page: {
            status: mode === 'disabled' ? 0 : 1,
            page_status: 0,
            page_url: '',
          },
          theme: {
            data: {
              themeColorId: 1,
              topTextColor: 'white',
              navigationBarColor: '#ffffff',
              themeColor1: '#2979ff',
              themeColor2: '#1d54c4',
              buttonColor: 'white',
            },
          },
          tabbar: { style: {}, list: [] },
        });
      }
      if (path.pathname === '/api/index/index') {
        if (mode === 'home-failure') return send(503, 50300, null);
        return send(200, 20000, {
          article: [article],
          decorate: {
            id: 1,
            type: 1,
            name: 'Home',
            data: [
              {
                name: 'news',
                title: 'News',
                content: { enabled: 1 },
                styles: {},
              },
            ],
            meta: [
              {
                name: 'page-meta',
                content: { title: `${tenant} home`, text_color: 2 },
                styles: {},
              },
            ],
          },
        });
      }
      if (
        path.pathname === '/api/article/detail' &&
        path.searchParams.get('id') === '1'
      ) {
        if (mode === 'detail-failure') return send(503, 50300, null);
        return send(200, 20000, article);
      }
      violations.push({ host, method: request.method, path: path.pathname });
      return send(404, 40400, null);
    }
    if (!path.pathname.startsWith('/mobile/')) {
      response.writeHead(404);
      response.end();
      return;
    }
    let file;
    try {
      file = resolve(
        build,
        decodeURIComponent(
          path.pathname.slice('/mobile/'.length) || 'index.html'
        )
      );
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
      if (
        !stat.isFile() ||
        stat.isSymbolicLink() ||
        !inside(build, realpathSync(file))
      )
        throw new Error('not a static file');
      const mime = {
        '.html': 'text/html',
        '.js': 'text/javascript',
        '.css': 'text/css',
        '.svg': 'image/svg+xml',
        '.png': 'image/png',
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
  return { server, modes, requests, violations, hosts };
}

test(
  'native H5 anonymous browser, public failure and navigation contracts',
  { timeout: 120000 },
  async (t) => {
    const project = realpathSync(required('H5_BROWSER_PROJECT_ROOT'));
    const build = realpathSync(resolve(project, 'dist/build/h5'));
    const temporary = realpathSync(required('TMPDIR'));
    assert.ok(inside(resolve(root, '.local/tmp'), temporary));
    const evidence = resolve(required('H5_BROWSER_EVIDENCE_DIR'));
    assert.ok(inside(resolve(root, '.local/evidence'), evidence));
    mkdirSync(evidence, { recursive: true, mode: 0o700 });
    assert.equal(realpathSync(evidence), evidence);
    assert.deepEqual(readdirSync(evidence), [], 'Preserve prior evidence');
    const before = outputDigest(build);
    assert.equal(before, required('H5_BROWSER_OUTPUT_SHA256'));
    const registry = JSON.parse(
      readFileSync(resolve(root, 'resources/project-resources.json'))
    ).resources;
    const find = (items, id) => {
      const matches = items.filter((item) => item.stable_resource_id === id);
      assert.equal(matches.length, 1);
      return matches[0];
    };
    const listener = find(
      registry.local_listeners,
      'peanut-h5-browser-fixture'
    );
    const tooling = find(registry.tooling, 'peanut-h5-browser-qualification');
    assert.equal(listener.host, '127.0.0.1');
    assert.equal(listener.environment, 'development-test');
    assert.equal(listener.owner, 'h5-browser-qualification');
    assert.equal(tooling.owner, listener.owner);
    const lease = execFileSync(
      'bash',
      [
        resolve(root, 'scripts/project-resource-lease'),
        'show',
        '--lease',
        required('H5_BROWSER_LEASE_ID'),
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
    assert.equal(fields.gate, 'h5-browser-qualification');
    assert.equal(fields.candidate, head);
    assert.equal(fields.worktree, realpathSync(root));
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
    const playwrightRoot = realpathSync(required('H5_BROWSER_PLAYWRIGHT_ROOT'));
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
    const fixture = createPublicFixture(build, listener.port);
    const diagnosticTasks = [];
    const nativeExceptions = [];
    const pageErrors = [],
      dialogs = [],
      externalRequests = [],
      consoleErrors = [],
      results = [];
    let browser;
    let current = 'startup';
    let browserVersion;
    t.after(async () => {
      await Promise.all(diagnosticTasks);
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
              results.length === 8 &&
              !pageErrors.length &&
              !dialogs.length &&
              !externalRequests.length &&
              !fixture.violations.length &&
              before === after
                ? 'passed'
                : 'failed',
            results,
            pageErrors,
            nativeExceptions,
            dialogs,
            externalRequests,
            consoleErrors,
            fixtureViolations: fixture.violations,
            code_commit: head,
            h5_root: project,
            output_before: before,
            output_after: after,
            playwright_version: version,
            browser_version: browserVersion,
            scope:
              'Real Chromium and original H5 static assets; synthetic read-only public API, no PHP/MySQL/authentication/OAuth/native app',
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
    const allowed = new Set(
      fixture.hosts.map((host) => `http://${host}:${listener.port}`)
    );
    const pages = [];
    for (let i = 0; i < 2; i++) {
      const context = await browser.newContext({
        viewport: { width: 390, height: 844 },
        deviceScaleFactor: 1,
        isMobile: true,
        hasTouch: true,
        serviceWorkers: 'block',
      });
      await context.route('**/*', async (route) => {
        const url = new URL(route.request().url());
        if (!allowed.has(url.origin)) {
          externalRequests.push({ scenario: current, url: url.href });
          await route.abort();
        } else await route.continue();
      });
      const page = await context.newPage();
      // Observe native rejection details; do not replace app error handlers.
      const diagnostic = await context.newCDPSession(page);
      await diagnostic.send('Runtime.enable');
      diagnostic.on('Runtime.exceptionThrown', ({ exceptionDetails }) => {
        const observed = {
          scenario: current,
          text: exceptionDetails.text,
          description: exceptionDetails.exception?.description,
          properties: [],
        };
        nativeExceptions.push(observed);
        if (exceptionDetails.exception?.objectId) {
          diagnosticTasks.push(
            diagnostic
              .send('Runtime.getProperties', {
                objectId: exceptionDetails.exception.objectId,
                ownProperties: true,
              })
              .then(({ result }) => {
                observed.properties = result
                  .filter((property) => property.value)
                  .map(({ name, value }) => ({ name, value: value.value }));
              })
              .catch((error) => {
                observed.inspection_error = error.message;
              })
          );
        }
      });
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
      pages.push(page);
    }
    const url = (i, path = '/pages/index/index') =>
      `http://${fixture.hosts[i]}:${listener.port}/mobile/#${path}`;
    const check = async (name, action) =>
      t.test(name, async () => {
        current = name;
        try {
          await action();
          results.push(name);
        } catch (error) {
          for (const [index, page] of pages.entries()) {
            writeFileSync(
              resolve(evidence, `failure-${results.length}-${index}.html`),
              await page.content()
            );
          }
          throw error;
        }
      });
    const article = async (i) => {
      const name = i === 0 ? 'Mobile A' : 'Mobile B';
      await pages[i]
        .locator('.detail-page .title')
        .getByText(`${name} article`, { exact: true })
        .waitFor();
      await pages[i]
        .locator('.rich-content')
        .getByText(`${name} 正文`, { exact: false })
        .waitFor();
      assert.ok(
        !(await pages[i].locator('body').innerText()).includes(
          `${i === 0 ? 'Mobile B' : 'Mobile A'} article`
        )
      );
    };
    await check(
      'two mobile hosts load native home and navigate to host-local article',
      async () => {
        await Promise.all(pages.map((page, i) => page.goto(url(i))));
        for (const [i, page] of pages.entries()) {
          const name = i === 0 ? 'Mobile A' : 'Mobile B';
          await page
            .locator('.article-title')
            .getByText(`${name} article`, { exact: true })
            .click();
          await article(i);
          await page.screenshot({
            path: resolve(evidence, `mobile-${i + 1}.png`),
            fullPage: true,
          });
        }
      }
    );
    // The native framework schedules shadow preloading after three seconds.
    await pages[0].waitForTimeout(3300);
    await check(
      'native rich text survives refresh without executable markup',
      async () => {
        await pages[0].reload();
        await article(0);
        assert.equal(
          await pages[0]
            .locator(
              '.rich-content script, .rich-content [onerror], .rich-content [onload], .rich-content [onclick]'
            )
            .count(),
          0
        );
        assert.ok(
          (await pages[0].locator('.rich-content').innerText()).includes(
            '保留加粗'
          )
        );
        assert.equal(dialogs.length, 0);
      }
    );
    await check(
      'anonymous collection opens actual login and empty fields do not submit',
      async () => {
        const count = fixture.requests.length;
        await pages[0].locator('.action-item').click();
        await pages[0].locator('.login-page input').nth(0).waitFor();
        await pages[0].locator('.login-page input').nth(1).waitFor();
        await pages[0].locator('.btn-primary').click();
        await pages[0]
          .locator('uni-toast')
          .getByText('请输入账号', { exact: true })
          .waitFor();
        await pages[0]
          .locator('.login-page input')
          .nth(0)
          .fill('synthetic-unsubmitted@example.test');
        await pages[0].locator('.btn-primary').click();
        await pages[0]
          .locator('uni-toast')
          .getByText('请输入密码', { exact: true })
          .waitFor();
        assert.equal(fixture.requests.length, count);
        assert.equal(fixture.violations.length, 0);
      }
    );
    await check(
      'invalid article ID is refused before a detail API request',
      async () => {
        const count = fixture.requests.filter((r) =>
          r.path.startsWith('/api/article/detail')
        ).length;
        await pages[1].goto(
          url(1, '/pages/news_detail/news_detail?id=invalid')
        );
        await pages[1].getByText('资讯编号无效', { exact: true }).waitFor();
        assert.equal(
          await pages[1].locator('.detail-page .content').count(),
          0
        );
        assert.equal(
          fixture.requests.filter((r) =>
            r.path.startsWith('/api/article/detail')
          ).length,
          count
        );
      }
    );
    await check(
      'home failure shows retry and real click restores only its own content',
      async () => {
        fixture.modes.set(fixture.hosts[1], 'home-failure');
        await pages[1].goto(url(1));
        await pages[1]
          .getByText('首页加载失败，请重试', { exact: true })
          .waitFor();
        fixture.modes.set(fixture.hosts[1], 'normal');
        await pages[1].getByText('重新加载', { exact: true }).click();
        await pages[1]
          .locator('.article-title')
          .getByText('Mobile B article', { exact: true })
          .waitFor();
        assert.ok(
          !(await pages[1].locator('body').innerText()).includes(
            'Mobile A article'
          )
        );
      }
    );
    await check(
      'public configuration failure leaves the application fail-closed',
      async () => {
        fixture.modes.set(fixture.hosts[1], 'config-failure');
        await pages[1].goto(url(1));
        await pages[1].reload();
        await pages[1].waitForURL('about:blank');
        assert.equal((await pages[1].locator('body').innerText()).trim(), '');
      }
    );
    await check(
      'disabled H5 configuration does not render a usable tenant page',
      async () => {
        fixture.modes.set(fixture.hosts[1], 'disabled');
        await pages[1].goto(url(1));
        await pages[1].waitForURL('about:blank');
        assert.equal((await pages[1].locator('body').innerText()).trim(), '');
        fixture.modes.set(fixture.hosts[1], 'normal');
      }
    );
    await check(
      'no script dialogs, external traffic, private API or business writes',
      async () => {
        assert.deepEqual(pageErrors, []);
        assert.deepEqual(dialogs, []);
        assert.deepEqual(externalRequests, []);
        assert.deepEqual(fixture.violations, []);
        assert.ok(fixture.requests.length > 0);
        assert.ok(
          fixture.requests.every(
            (r) =>
              r.method === 'GET' && !r.authorizationPresent && !r.cookiePresent
          )
        );
      }
    );
  }
);
