import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { resolve } from 'node:path';
import { webcrypto } from 'node:crypto';
import vm from 'node:vm';
import test from 'node:test';

const root = resolve(import.meta.dirname, '../../..');
const require = createRequire(import.meta.url);
const ts = require(resolve(root, 'tools/quality/node_modules/typescript'));
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
  window = { addEventListener() {}, location: { reload() {} } }
) {
  const exports = {};
  const source = readFileSync(resolve(root, path), 'utf8').replaceAll(
    'import.meta.env.VITE_API_BASE_URL',
    'undefined'
  );
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
    window,
    require: (name) => {
      assert.ok(Object.hasOwn(bindings, name), name);
      return bindings[name];
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
    axios: controlledAxios,
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
  const b = load('web/src/utils/auth.ts', shared);
  a.advanceSessionGeneration();
  a.setToken('old-token');
  const before = b.getSessionSnapshot();
  a.setToken('rotated-token');
  const refreshed = b.getSessionSnapshot();
  assert.equal(refreshed.generation, before.generation);
  assert.equal(refreshed.token, 'rotated-token');
  a.advanceSessionGeneration();
  a.setToken('new-account-token');
  assert.ok(b.getSessionSnapshot().generation > before.generation);
});

for (const identity of ['session-change', 'token-refresh'])
  for (const code of [20000, 40100]) {
    test(`Platform rejects late ${code} after ${identity} without affecting the current token`, async () => {
      const response = deferred();
      const started = deferred();
      let calls = 0;
      const h = platform(async () => {
        calls++;
        started.resolve();
        return response.promise;
      });
      const work = h.api.tenants();
      const rejection = assert.rejects(work, /session has changed/);
      await started.promise;
      if (identity === 'session-change') replaceSession(h);
      else h.localStorage.setItem('peanut-platform-token', 'new-token');
      response.resolve(envelope({ lists: [{ id: 'old' }] }, code));
      await rejection;
      assert.equal(
        h.localStorage.getItem('peanut-platform-token'),
        'new-token'
      );
      assert.equal(calls, 1);
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
  assert.equal(
    h.localStorage.getItem('peanut-platform-token'),
    'rotated-token'
  );
  assert.equal(
    h.localStorage.getItem('peanut-platform-session'),
    'old-session'
  );
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
