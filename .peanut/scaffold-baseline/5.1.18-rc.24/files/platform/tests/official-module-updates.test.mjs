import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { fileURLToPath } from 'node:url';
import { runInNewContext } from 'node:vm';
import axios from 'axios';
import * as peanutClient from '@peanut-admin/client';
import * as peanutVue from '@peanut-admin/vue';
import { transformWithEsbuild } from 'vite';

const updates = '/platformapi/instance-tools/modules/updates';
const refresh = '/platformapi/session/refresh';
const catalog = '/platformapi/developer-center/catalog';
const transportModules = await Promise.all(
  ['tenant-license', 'permission-bundles', 'platform'].map(async (name) => {
    const filename = fileURLToPath(
      new URL(`../src/api/${name}.ts`, import.meta.url)
    );
    const { code } = await transformWithEsbuild(
      readFileSync(filename, 'utf8'),
      filename,
      {
        format: 'cjs',
        define: {
          'import.meta.env.VITE_API_BASE_URL': 'undefined',
          'import.meta.hot': 'undefined',
        },
      }
    );
    return { name, filename, code };
  })
);

// Run the platform transport with its real Axios interceptors and Core
// session code. Only the network adapter and browser storage/lock are fixtures.
function fixture(t, adapter) {
  const storage = new Map();
  Object.defineProperty(globalThis, 'localStorage', {
    configurable: true,
    value: {
      getItem: (key) => storage.get(key) ?? null,
      setItem: (key, value) => storage.set(key, value),
      removeItem: (key) => storage.delete(key),
    },
  });
  Object.defineProperty(navigator, 'locks', {
    configurable: true,
    value: { request: (_name, operation) => operation() },
  });
  t.after(() => {
    delete globalThis.localStorage;
    delete navigator.locks;
  });

  const dependencies = {
    axios: {
      ...axios,
      create: (config) => axios.create({ ...config, adapter }),
    },
    '@peanut-admin/client': peanutClient,
    '@peanut-admin/vue': peanutVue,
  };
  for (const { name, filename, code } of transportModules) {
    const module = { exports: {} };
    runInNewContext(
      code,
      {
        module,
        require(dependency) {
          assert.ok(Object.hasOwn(dependencies, dependency), dependency);
          return dependencies[dependency];
        },
      },
      { filename }
    );
    dependencies[`./${name}`] = module.exports;
  }
  return dependencies['./platform'].api;
}

function envelope(config, data, code = 20000, status = 200) {
  return { config, status, headers: {}, data: { code, msg: '', data } };
}

test('only the official catalog extends the platform request timeout', async (t) => {
  const calls = [];
  const api = fixture(t, async (config) => {
    const { method, url, timeout, params } = config;
    calls.push([method, url, timeout, params?.channel]);
    return envelope(config, { catalog: [] });
  });
  for (const channel of ['stable', 'prerelease']) {
    assert.deepEqual(await api.officialModuleUpdates(channel), { catalog: [] });
  }
  await api.developerCatalog();
  assert.deepEqual(calls, [
    ['get', updates, 60_000, 'stable'],
    ['get', updates, 60_000, 'prerelease'],
    ['get', catalog, 15_000, undefined],
  ]);
});

test('catalog timeouts and other network failures have distinct messages', async (t) => {
  const failures = [
    ['ECONNABORTED', '请求超时，请稍后重试'],
    ['ETIMEDOUT', '请求超时，请稍后重试'],
    ['ERR_NETWORK', '网络请求失败，请检查网络连接后重试'],
  ];
  for (const [code, message] of failures) {
    const api = fixture(t, async (config) => {
      throw new axios.AxiosError('transport error', code, config);
    });
    await assert.rejects(api.officialModuleUpdates('stable'), { code, message });
  }
});

test('concurrent catalog reads share session refresh and retain their timeout on retry', async (t) => {
  const attempts = [];
  const refreshCalls = [];
  const api = fixture(t, async (config) => {
    const { url, timeout, params, headers } = config;
    if (url === '/platformapi/session/login') {
      return envelope(config, { access_token: 'initial-token' });
    }
    if (url === refresh) {
      refreshCalls.push(timeout);
      return envelope(config, { access_token: 'refreshed-token' });
    }
    assert.equal(url, updates);
    attempts.push([params.channel, timeout, headers.Authorization]);
    if (headers.Authorization === 'Bearer initial-token') {
      throw new axios.AxiosError(
        'session expired',
        'ERR_BAD_REQUEST',
        config,
        undefined,
        envelope(config, null, 40100, 401)
      );
    }
    return envelope(config, { channel: params.channel });
  });

  await api.login('operator@example.test', 'synthetic');
  const channels = ['stable', 'prerelease'];
  const snapshots = await Promise.all(
    channels.map((channel) => api.officialModuleUpdates(channel))
  );
  assert.deepEqual(snapshots, channels.map((channel) => ({ channel })));
  assert.deepEqual(refreshCalls, [15_000]);
  for (const channel of channels) {
    assert.deepEqual(attempts.filter(([attempt]) => attempt === channel), [
      [channel, 60_000, 'Bearer initial-token'],
      [channel, 60_000, 'Bearer refreshed-token'],
    ]);
  }
});
