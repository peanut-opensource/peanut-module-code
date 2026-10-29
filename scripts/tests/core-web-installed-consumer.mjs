// Copy this probe into a task-owned native npm consumer; run there after installing Core archives.
// Public package exports are used, not maintainer source paths. Transport callbacks are synthetic.
import assert from 'node:assert/strict';
import { readFileSync, realpathSync } from 'node:fs';
import { dirname, join, sep } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { createClient } from '@peanut-admin/client';
import {
  createNuxtClientTransport,
  createNuxtSsrForwardHeaders,
} from '@peanut-admin/nuxt';
import { createUniAppClientTransport } from '@peanut-admin/uniapp';

const consumerRoot = realpathSync(dirname(fileURLToPath(import.meta.url)));
const versionExports = {
  'vue': 'PEANUT_ADMIN_VUE_VERSION',
  'ui-vue': 'PEANUT_ADMIN_UI_VUE_VERSION',
  'testing': 'WEB_TESTING_VERSION',
};

// The caller fixes archive provenance. This probe additionally rejects source
// links and imports every installed public entry through native Node ESM.
for (const suffix of ['client', 'vue', 'ui-vue', 'nuxt', 'uniapp', 'testing']) {
  test(`installed ${suffix} exposes a contained native ESM entry`, async () => {
    const name = `@peanut-admin/${suffix}`;
    const entry = realpathSync(fileURLToPath(import.meta.resolve(name)));
    assert.ok(entry.startsWith(consumerRoot + sep));
    const manifest = JSON.parse(
      readFileSync(join(dirname(dirname(entry)), 'package.json'), 'utf8')
    );
    assert.equal(manifest.name, name);
    assert.equal(manifest.exports['.'].import, './dist/index.js');
    const api = await import(name);
    assert.ok(Object.keys(api).length > 0);
    if (versionExports[suffix]) {
      assert.equal(api[versionExports[suffix]], manifest.version);
    }
  });
}

const decoder = (response) =>
  response.denied
    ? { kind: 'unauthorized', code: 'FIXTURE_UNAUTHORIZED' }
    : { kind: 'success', data: response };

function scopedClient(identity) {
  let token = `fixture-${identity}`;
  let cleared = 0;
  const forwarded = createNuxtSsrForwardHeaders({
    requestHost: `${identity}.example.test`,
    cookie: `fixture_session=${identity}`,
    forwardedProto: 'https',
    trustedHosts: ['*.example.test'],
  });
  const transport = createNuxtClientTransport({
    baseUrl: 'https://api.example.invalid',
    forwardHeaders: forwarded,
    $fetch: async (url, options) => ({
      url,
      ...options,
      denied: url.endsWith('/denied'),
    }),
  });
  const client = createClient({
    transport,
    decoder,
    session: {
      accessToken: () => token,
      clear: () => {
        token = null;
        cleared++;
      },
    },
  });
  return { client, cleared: () => cleared, token: () => token };
}

test('installed client and Nuxt adapter keep request identities separate', async () => {
  assert.equal(typeof globalThis.window, 'undefined');
  const a = scopedClient('a'),
    b = scopedClient('b');
  const [one, two] = await Promise.all([
    a.client.request({
      path: '/api/profile',
      headers: { Authorization: 'forged', host: 'forged.example.test' },
    }),
    b.client.request({ path: '/api/profile' }),
  ]);
  assert.equal(one.headers.authorization, 'Bearer fixture-a');
  assert.equal(two.headers.authorization, 'Bearer fixture-b');
  assert.equal(one.headers.host, 'a.example.test');
  assert.equal(two.headers.host, 'b.example.test');
  assert.equal(one.headers.cookie, 'fixture_session=a');
  assert.equal(two.headers.cookie, 'fixture_session=b');
  assert.equal(one.url, 'https://api.example.invalid/api/profile');
  const anonymous = await a.client.request({
    path: '/api/news',
    auth: false,
    headers: { authorization: 'forged' },
  });
  assert.equal(anonymous.headers.authorization, undefined);
});

test('installed client rejects unauthorized responses and only clears its own session', async () => {
  const a = scopedClient('a'),
    b = scopedClient('b');
  await assert.rejects(
    a.client.request({ path: '/denied' }),
    (error) => error.kind === 'unauthorized'
  );
  assert.equal(a.cleared(), 1);
  assert.equal(a.token(), null);
  assert.equal(b.cleared(), 0);
  assert.equal(b.token(), 'fixture-b');
  const reply = await b.client.request({ path: '/api/profile' });
  assert.equal(reply.headers.authorization, 'Bearer fixture-b');
});

test('installed SSR adapter refuses untrusted hosts and cookie header injection', () => {
  assert.throws(() =>
    createNuxtSsrForwardHeaders({
      requestHost: 'outside.invalid',
      forwardedProto: 'https',
      trustedHosts: ['*.example.test'],
    })
  );
  assert.throws(() =>
    createNuxtSsrForwardHeaders({
      requestHost: 'a.example.test',
      cookie: 'a=1\r\nInjected: yes',
      forwardedProto: 'https',
    })
  );
});

test('installed UniApp adapter executes without a global uni object', async () => {
  assert.equal(typeof globalThis.uni, 'undefined');
  const client = createClient({
    session: { accessToken: () => 'fixture-mobile', clear: () => {} },
    decoder,
    transport: createUniAppClientTransport({
      baseUrl: 'https://api.example.invalid',
      request: (options) =>
        options.success({
          data: {
            url: options.url,
            method: options.method,
            headers: options.header,
          },
        }),
    }),
  });
  const reply = await client.request({ path: '/api/profile', method: 'POST' });
  assert.equal(reply.url, 'https://api.example.invalid/api/profile');
  assert.equal(reply.method, 'POST');
  assert.equal(reply.headers.authorization, 'Bearer fixture-mobile');
});
