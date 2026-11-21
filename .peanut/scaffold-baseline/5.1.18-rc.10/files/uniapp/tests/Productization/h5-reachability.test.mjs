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
const sanitize = (value) => value.replace(/[^a-zA-Z0-9_.-]/g, '-');

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

function longArticleContent(hostName) {
  const blocks = Array.from(
    { length: 36 },
    (_, index) =>
      `<p>Reachability paragraph ${String(index + 1).padStart(
        2,
        '0'
      )} for ${hostName}. This line is intentionally long enough to exercise wrapping without becoming a navigation or authentication case.</p>`
  ).join('');
  return `${blocks}
    <p><img src="/mobile/static/brand/logo.svg" style="width:1600px;height:180px" alt="wide fixture"></p>
    <p><span data-tail-marker="h5-tail-${hostName}">H5_REACHABILITY_TAIL_${hostName}</span></p>`;
}

function createReachabilityFixture(build, port) {
  const requests = [];
  const violations = [];
  const hosts = ['mobile-a.localhost', 'mobile-b.localhost'];
  const articleFor = (tenant) => ({
    id: 1001,
    cid: 1,
    cate_name: 'News',
    title: `${tenant} reachability article`,
    image: '/mobile/static/brand/logo.svg',
    desc: `${tenant} long reachability summary`,
    author: tenant,
    click: 7,
    create_time: '2026-09-28 00:00:00',
    collect: false,
    content: longArticleContent(tenant.replaceAll(' ', '_')),
  });
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
        JSON.stringify({ code, msg: 'synthetic reachability fixture', data })
      );
    };
    if (index < 0) return send(421, 42100, null);
    if (!['GET', 'HEAD'].includes(request.method)) {
      violations.push({ host, method: request.method, path: path.pathname });
      return send(405, 40500, null);
    }
    const tenant = index === 0 ? 'Mobile A' : 'Mobile B';
    if (path.pathname.startsWith('/api/')) {
      requests.push({
        host,
        method: request.method,
        path: path.pathname + path.search,
        authorizationPresent: Boolean(request.headers.authorization),
        cookiePresent: Boolean(request.headers.cookie),
      });
      if (path.pathname === '/api/index/config') {
        const logo = '/mobile/static/brand/logo.svg';
        return send(200, 20000, {
          domain: host,
          version: 'synthetic-reachability',
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
          web_page: { status: 1, page_status: 0, page_url: '' },
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
      if (
        path.pathname === '/api/article/detail' &&
        path.searchParams.get('id') === '1001'
      ) {
        return send(200, 20000, articleFor(tenant));
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
        '.json': 'application/json',
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
  return { server, requests, violations, hosts };
}

test(
  'native H5 long article tail remains reachable above fixed action bar',
  { timeout: 120000 },
  async (t) => {
    const project = realpathSync(required('H5_BROWSER_PROJECT_ROOT'));
    const build = realpathSync(resolve(project, 'dist/build/h5'));
    const temporary = realpathSync(required('TMPDIR'));
    assert.ok(inside(resolve(root, '.local/tmp'), temporary));
    const evidenceRoot = resolve(required('H5_BROWSER_EVIDENCE_DIR'));
    assert.ok(inside(resolve(root, '.local/evidence'), evidenceRoot));
    mkdirSync(evidenceRoot, { recursive: true, mode: 0o700 });
    assert.equal(realpathSync(evidenceRoot), evidenceRoot);
    const leaseId = required('H5_BROWSER_LEASE_ID');
    const evidence = resolve(evidenceRoot, `browser-${sanitize(leaseId)}`);
    assert.ok(inside(evidenceRoot, evidence));
    mkdirSync(evidence, { recursive: true, mode: 0o700 });
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
    assert.equal(listener.port, 20493);
    assert.equal(listener.environment, 'development-test');
    assert.equal(listener.owner, 'h5-browser-qualification');
    assert.equal(tooling.owner, listener.owner);
    const lease = execFileSync(
      'bash',
      [
        resolve(root, 'scripts/project-resource-lease'),
        'show',
        '--lease',
        leaseId,
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
      `output-dir\t${evidenceRoot}`,
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

    const fixture = createReachabilityFixture(build, listener.port);
    const pageErrors = [];
    const dialogs = [];
    const externalRequests = [];
    const consoleErrors = [];
    const results = [];
    const metrics = [];
    let browser;
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
              results.length === 4 &&
              !pageErrors.length &&
              !dialogs.length &&
              !externalRequests.length &&
              !fixture.violations.length &&
              before === after
                ? 'passed'
                : 'failed',
            results,
            metrics,
            pageErrors,
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
              'Real Chromium geometry for native H5 long article; synthetic read-only public API, no PHP/MySQL/authentication/OAuth/native app',
          },
          null,
          2
        ) + '\n'
      );
      assert.equal(after, before, 'Do not mutate existing native artifacts');
      assert.deepEqual(pageErrors, [], 'Unexpected page errors');
      assert.deepEqual(dialogs, [], 'Unexpected dialogs');
      assert.deepEqual(externalRequests, [], 'Unexpected external requests');
      assert.deepEqual(fixture.violations, [], 'Unexpected fixture requests');
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

    const detailUrl = (path = '/pages/news_detail/news_detail?id=1001') =>
      `http://${fixture.hosts[0]}:${listener.port}/mobile/#${path}`;
    const check = async (name, action) =>
      t.test(name, async () => {
        current = name;
        try {
          await action();
          results.push(name);
        } catch (error) {
          writeFileSync(
            resolve(evidence, `failure-${sanitize(name)}.html`),
            await page.content()
          );
          throw error;
        }
      });
    const openArticle = async () => {
      await page.goto(detailUrl());
      await page
        .locator('.detail-page .title')
        .getByText('Mobile A reachability article', { exact: true })
        .waitFor();
      await page
        .locator('.rich-content')
        .getByText('H5_REACHABILITY_TAIL_Mobile_A', { exact: true })
        .waitFor();
    };
    const assertReachable = async (label) => {
      const data = await page.evaluate(async (scenario) => {
        window.scrollTo(0, document.documentElement.scrollHeight);
        await new Promise((resolveFrame) =>
          requestAnimationFrame(() => requestAnimationFrame(resolveFrame))
        );
        // Native rich-text removes data-* attributes; locate rendered text.
        const tail = Array.from(
          document.querySelectorAll('.rich-content span')
        ).find(
          (element) => element.textContent === 'H5_REACHABILITY_TAIL_Mobile_A'
        );
        const bar = document.querySelector('.action-bar');
        const action = document.querySelector('.action-item');
        if (!tail || !bar || !action) {
          return {
            scenario,
            missing: { tail: !tail, bar: !bar, action: !action },
          };
        }
        const tailRect = tail.getBoundingClientRect();
        const barRect = bar.getBoundingClientRect();
        const actionRect = action.getBoundingClientRect();
        const tailCenterX = tailRect.left + tailRect.width / 2;
        const tailCenterY = tailRect.top + tailRect.height / 2;
        const actionCenterX = actionRect.left + actionRect.width / 2;
        const actionCenterY = actionRect.top + actionRect.height / 2;
        const atTail = document.elementFromPoint(tailCenterX, tailCenterY);
        const atAction = document.elementFromPoint(
          actionCenterX,
          actionCenterY
        );
        return {
          scenario,
          viewport: {
            width: window.innerWidth,
            height: window.innerHeight,
          },
          scroll: {
            x: window.scrollX,
            y: window.scrollY,
            width: document.documentElement.scrollWidth,
            clientWidth: document.documentElement.clientWidth,
            height: document.documentElement.scrollHeight,
            clientHeight: document.documentElement.clientHeight,
          },
          tailRect: {
            top: tailRect.top,
            bottom: tailRect.bottom,
            left: tailRect.left,
            right: tailRect.right,
          },
          barRect: {
            top: barRect.top,
            bottom: barRect.bottom,
            height: barRect.height,
          },
          actionRect: {
            top: actionRect.top,
            bottom: actionRect.bottom,
            left: actionRect.left,
            right: actionRect.right,
          },
          tailHit: atTail === tail || Boolean(atTail && tail.contains(atTail)),
          actionHit:
            atAction === action ||
            Boolean(atAction && action.contains(atAction)),
          actionPointerEvents: getComputedStyle(action).pointerEvents,
          bodyText: document.body.innerText,
        };
      }, label);
      metrics.push(data);
      assert.ok(!data.missing, `Missing geometry target for ${label}`);
      assert.ok(
        data.scroll.width <= data.scroll.clientWidth + 1,
        `Horizontal overflow for ${label}: ${data.scroll.width} > ${data.scroll.clientWidth}`
      );
      assert.ok(
        data.tailRect.bottom <= data.barRect.top - 1,
        `Tail is hidden by action bar for ${label}`
      );
      assert.ok(data.tailHit, `Tail marker is not hittable for ${label}`);
      assert.ok(data.actionHit, `Action bar item is not hittable for ${label}`);
      assert.equal(data.actionPointerEvents, 'auto');
      assert.ok(data.bodyText.includes('H5_REACHABILITY_TAIL_Mobile_A'));
    };

    await check(
      'portrait viewport reaches tail above fixed action bar',
      async () => {
        await page.setViewportSize({ width: 390, height: 844 });
        await openArticle();
        await assertReachable('portrait-390x844');
        await page.screenshot({
          path: resolve(evidence, 'portrait-tail.png'),
          fullPage: false,
        });
      }
    );
    await check('rotated landscape viewport still reaches tail', async () => {
      await page.setViewportSize({ width: 844, height: 390 });
      await page.reload();
      await page
        .locator('.rich-content')
        .getByText('H5_REACHABILITY_TAIL_Mobile_A', { exact: true })
        .waitFor();
      await assertReachable('landscape-844x390');
      await page.screenshot({
        path: resolve(evidence, 'landscape-tail.png'),
        fullPage: false,
      });
    });
    await check(
      'tablet viewport still keeps fixed action available',
      async () => {
        await page.setViewportSize({ width: 768, height: 1024 });
        await page.reload();
        await page
          .locator('.detail-page .title')
          .getByText('Mobile A reachability article', { exact: true })
          .waitFor();
        await assertReachable('tablet-768x1024');
      }
    );
    await check(
      'anonymous fixed action only navigates locally to login',
      async () => {
        const requestCount = fixture.requests.length;
        await page.locator('.action-item').click();
        await page.locator('.login-page input').first().waitFor();
        assert.match(page.url(), /\/pages\/login\/login/);
        assert.equal(fixture.requests.length, requestCount);
      }
    );
  }
);
