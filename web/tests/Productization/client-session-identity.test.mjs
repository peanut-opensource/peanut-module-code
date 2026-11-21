import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { dirname, resolve } from 'node:path';
import { webcrypto } from 'node:crypto';
import vm from 'node:vm';
import test from 'node:test';
import {
  createMemoryRefreshCoordinator,
  createSessionRecovery,
  createSessionFetch,
} from '../../node_modules/@peanut-admin/client/dist/index.js';

const root = resolve(import.meta.dirname, '../../..');
const ts = createRequire(resolve(root, 'web/package.json'))('typescript');
const axios = createRequire(resolve(root, 'platform/package.json'))('axios');
const storage = () => {
  const data = new Map();
  return {
    getItem: (key) => data.get(key) ?? null,
    setItem: (key, value) => data.set(key, value),
    removeItem: (key) => data.delete(key),
  };
};
const deferred = () => {
  let resolve;
  const promise = new Promise((done) => {
    resolve = done;
  });
  return { promise, resolve };
};
function load(
  path,
  localStorage,
  bindings = {},
  window = { addEventListener() {}, removeEventListener() {}, location: { reload() {} } }
) {
  const exports = {};
  const source = readFileSync(resolve(root, path), 'utf8').replaceAll(
    'import.meta.env.VITE_API_BASE_URL',
    'undefined'
  ).replaceAll('import.meta.hot', 'undefined');
  const output = ts.transpileModule(source, {
    compilerOptions: {
      module: ts.ModuleKind.CommonJS,
      target: ts.ScriptTarget.ES2022,
    },
  }).outputText;
  vm.runInNewContext(output, {
    exports,
    localStorage,
    crypto: webcrypto,
    window: { ...window, crypto: webcrypto, localStorage },
    navigator: { locks: { request: async (_name, run) => run() } },
    URL,
    require: (name) => {
      if (Object.hasOwn(bindings, name)) return bindings[name];
      if (name.startsWith('.')) return load(resolve(dirname(path), name + '.ts'), localStorage);
      assert.fail(`Unexpected import: ${name}`);
    },
  });
  return exports;
}
function platform(transport) {
  const localStorage = storage();
  localStorage.setItem('peanut-platform-token', 'old-token');
  localStorage.setItem('peanut-platform-session', 'old-session');
  const controlledAxios = {
    isAxiosError: axios.isAxiosError,
    isCancel: axios.isCancel,
    create: (options) => {
      const client = axios.create(options);
      client.defaults.adapter = async (config) => ({
        data: await transport(config),
        status: 200,
        statusText: 'OK',
        headers: {},
        config,
      });
      return client;
    },
  };
  controlledAxios.default = controlledAxios;
  const api = load('platform/src/api/platform.ts', localStorage, {
    'axios': controlledAxios,
    '@peanut-admin/vue': { createMemoryRefreshCoordinator },
    './tenant-license': load(
      'platform/src/api/tenant-license.ts',
      localStorage
    ),
    './permission-bundles': load(
      'platform/src/api/permission-bundles.ts',
      localStorage
    ),
  });
  return { ...api, localStorage };
}
const envelope = (data, code = 20000) => ({
  code,
  msg: code === 20000 ? 'ok' : 'expired',
  data,
});
function replaceSession(h) {
  h.localStorage.setItem('peanut-platform-token', 'new-token');
  h.localStorage.setItem('peanut-platform-session', 'new-session');
}

test('Web tabs preserve refresh identity and invalidate a login/tenant transition', () => {
  const shared = storage();
  const a = load('web/src/utils/auth.ts', shared);
  a.setToken('old-token', 'tenant-switched');
  const b = load('web/src/utils/auth.ts', shared);
  const before = b.getSessionSnapshot();
  a.setToken('rotated-token', 'session-refreshed');
  const refreshed = b.getSessionSnapshot();
  assert.equal(refreshed.generation, before.generation);
  assert.equal(refreshed.token, 'rotated-token');
  a.setToken('new-account-token', 'tenant-switched');
  assert.ok(b.getSessionSnapshot().generation > before.generation);
  assert.equal(b.getToken(), null);
});

function tenantHost({ refresh, status = 401, code = 40100 } = {}) {
  const localStorage = storage();
  const auth = load('web/src/utils/auth.ts', localStorage);
  auth.setToken('pa_tat_old', 'tenant-switched');
  const client = axios.create();
  client.isAxiosError = axios.isAxiosError;
  client.isCancel = axios.isCancel;
  client.default = client;
  let cleanups = 0;
  let redirects = 0;
  const router = {
    currentRoute: { value: { fullPath: '/customer?page=2', name: 'customer' } },
    replace: async (to) => {
      redirects++;
      assert.equal(to.name, 'login');
      assert.equal(to.query.redirect, '/customer?page=2');
      router.currentRoute.value.name = 'login';
    },
  };
  const isTenantAccessToken = (token) => token?.startsWith('pa_tat_') === true;
  const recovery = load('web/src/api/tenant-session-refresh.ts', localStorage, {
    'axios': client,
    '@peanut-admin/client': { createSessionRecovery },
    '@peanut-admin/vue': { isTenantAccessToken },
    '@/utils/auth': auth,
    '@/api/tenant-session': {
      queueSessionCookieRequest: async (run) => run(),
      refreshTenantSession:
        refresh ??
        (async () => {
          throw {
            isAxiosError: true,
            response: { status: 401, data: { code: 40100 } },
          };
        }),
    },
    '@/store': {
      useUserStore: () => ({
        logoutCallBack: async (generation, token) => {
          const current = auth.getSessionSnapshot();
          if (current.generation !== generation || current.token !== token)
            return false;
          cleanups++;
          auth.clearToken();
          return true;
        },
      }),
    },
    '@/router': { default: router },
  });
  const messages = [];
  load('web/src/api/interceptor.ts', localStorage, {
    'axios': client,
    'element-plus': {
      ElMessage: { error: (message) => messages.push(message) },
    },
    '@/utils/auth': auth,
    '@/utils/request-trace': load('web/src/utils/request-trace.ts', localStorage),
    '@/locale': { default: { global: { t: (key) => key } } },
    '@peanut-admin/vue': { isTenantAccessToken },
    '@/api/tenant-session-refresh': recovery,
  });
  client.defaults.adapter = async (config) => ({
    data: envelope(null, code),
    status,
    statusText: 'rejected',
    headers: {},
    config,
  });
  const fetched = load(
    'web/src/api/tenant-session-fetch.ts',
    localStorage,
    {
      'element-plus': { ElMessage: { error: (message) => messages.push(message) } },
      '@/utils/auth': auth,
      '@/utils/request-trace': load('web/src/utils/request-trace.ts', localStorage),
      '@peanut-admin/client': {
        createSessionFetch: (options) =>
          createSessionFetch({
            ...options,
            fetch: async () =>
              new Response(JSON.stringify(envelope(null, code)), { status }),
          }),
      },
      '@/api/tenant-session-refresh': recovery,
    },
    { location: { href: 'https://example.test/admin/customer' } }
  );
  return {
    client,
    auth,
    recovery,
    fetched,
    counts: () => ({ cleanups, redirects }),
    messages,
  };
}

test('Web profile read failures abort navigation without remote logout or local cleanup', async () => {
  const shared = storage();
  const auth = load('web/src/utils/auth.ts', shared);
  auth.setToken('pa_tat_current', 'tenant-switched');
  let guard;
  let logoutCalls = 0;
  const { default: setup } = load(
    'web/src/router/guard/userLoginInfo.ts',
    shared,
    {
      'nprogress': { default: { start() {}, done() {} } },
      '@/store': {
        useBrandStore: () => ({ load: async () => {}, loginEnabled: true }),
        useUserStore: () => ({
          role: null,
          info: async () => {
            throw new Error('network/forbidden');
          },
          logout: async () => {
            logoutCalls++;
          },
        }),
      },
      '@/utils/auth': auth,
      '../constants': { WHITE_LIST: [{ name: 'login' }] },
    }
  );
  setup({
    beforeEach: (callback) => {
      guard = callback;
    },
  });
  const outcome = await guard(
    { name: 'customer', fullPath: '/customer', query: {} },
    {}
  );
  assert.equal(outcome, false);
  assert.equal(logoutCalls, 0);
  assert.equal(auth.getToken(), 'pa_tat_current');
});

test('Web API expiry clears and navigates immediately without a confirmation or remote logout', async () => {
  const h = tenantHost();
  await assert.rejects(h.client.get('/adminapi/customer/lists'));
  assert.equal(h.auth.getToken(), null);
  assert.deepEqual(h.counts(), { cleanups: 1, redirects: 1 });
});

test('Axios and fetch share one local invalidation for the same failed session', async () => {
  const h = tenantHost();
  await Promise.allSettled([
    h.client.get('/adminapi/customer/lists'),
    h.fetched.tenantSessionFetch(
      new Request('https://example.test/adminapi/api/v1/items')
    ),
  ]);
  assert.deepEqual(h.counts(), { cleanups: 1, redirects: 1 });
  assert.equal(typeof h.fetched.tenantSessionFetch, 'function');
});

test('Web refresh network failure and forbidden API response do not log out', async () => {
  const h = tenantHost({
    refresh: async () => {
      throw new Error('network offline');
    },
  });
  await assert.rejects(
    h.client.get('/adminapi/customer/lists'),
    /network offline/
  );
  assert.equal(h.auth.getToken(), 'pa_tat_old');
  assert.deepEqual(h.counts(), { cleanups: 0, redirects: 0 });
  const forbidden = tenantHost({ status: 403, code: 40300 });
  await assert.rejects(forbidden.client.get('/adminapi/customer/lists'));
  assert.equal(forbidden.auth.getToken(), 'pa_tat_old');
  assert.deepEqual(forbidden.counts(), { cleanups: 0, redirects: 0 });
});

for (const identity of ['session-change', 'token-refresh'])
  for (const code of [20000, 40100]) {
    test(`Platform handles late ${code} after ${identity} without clearing the current token`, async () => {
      const response = deferred();
      const started = deferred();
      let calls = 0;
      const canReplay = identity === 'token-refresh' && code === 40100;
      const h = platform(async (config) => {
        calls++;
        if (calls > 1) {
          assert.equal(config.headers.Authorization, 'Bearer new-token');
          return envelope({ lists: ['current'] });
        }
        started.resolve();
        return response.promise;
      });
      const work = h.api.tenants();
      const result = canReplay ? work : assert.rejects(work, /session has changed/);
      await started.promise;
      if (identity === 'session-change') replaceSession(h);
      else h.localStorage.setItem('peanut-platform-token', 'new-token');
      response.resolve(envelope({ lists: [{ id: 'old' }] }, code));
      const page = await result;
      if (canReplay) assert.deepEqual(Array.from(page.lists), ['current']);
      assert.equal(h.localStorage.getItem('peanut-platform-token'), 'new-token');
      assert.equal(calls, canReplay ? 2 : 1);
    });
  }

test('Platform rejects a late refresh token after another tab logs in', async () => {
  const refresh = deferred();
  const started = deferred();
  const h = platform(async (config) => {
    if (config.url === '/platformapi/session/refresh') {
      started.resolve();
      return refresh.promise;
    }
    return envelope(null, 40100);
  });
  const work = h.api.tenants();
  const rejection = assert.rejects(work, /session has changed/);
  await started.promise;
  replaceSession(h);
  refresh.resolve(envelope({ access_token: 'stale-refresh-token' }));
  await rejection;
  assert.equal(h.localStorage.getItem('peanut-platform-token'), 'new-token');
});

test('Platform old logout await does not clear a new login', async () => {
  const response = deferred();
  const started = deferred();
  const h = platform(async () => {
    started.resolve();
    return response.promise;
  });
  const work = h.api.logout();
  const rejection = assert.rejects(work, /session has changed/);
  await started.promise;
  replaceSession(h);
  response.resolve(envelope(null));
  await rejection;
  assert.equal(h.localStorage.getItem('peanut-platform-token'), 'new-token');
});

test('Platform refresh updates only its own token and replays the actual request', async () => {
  const calls = [];
  const h = platform(async (config) => {
    calls.push(config.url);
    if (config.url === '/platformapi/session/refresh')
      return envelope({ access_token: 'rotated-token' });
    return config.headers.Authorization === 'Bearer rotated-token'
      ? envelope({ lists: ['current'] })
      : envelope(null, 40100);
  });
  assert.deepEqual(Array.from((await h.api.tenants()).lists), ['current']);
  assert.equal(h.localStorage.getItem('peanut-platform-token'), null);
  assert.deepEqual(JSON.parse(h.localStorage.getItem('peanut-platform-session')), {
    identity: 'old-session',
    token: 'rotated-token',
  });
  assert.deepEqual(calls, [
    '/platformapi/tenants',
    '/platformapi/session/refresh',
    '/platformapi/tenants',
  ]);
});

test('Platform logout returns only the snapshot it cleared', async () => {
  const h = platform(async () => envelope(null));
  const cleared = await h.api.logout();
  assert.ok(cleared);
  assert.equal(cleared.token, null);
  assert.equal(h.isPlatformSessionCurrent(cleared), true);
});
