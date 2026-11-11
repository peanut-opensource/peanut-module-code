import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { dirname, resolve, relative, sep } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import vm from 'node:vm';
import test from 'node:test';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const require = createRequire(import.meta.url);
const ts = require(resolve(root, 'tools/quality/node_modules/typescript'));
const source = readFileSync(
  resolve(root, 'pc/composables/useRequest.ts'),
  'utf8'
);

async function publicPackage(name) {
  const packageRoot = process.env.PC_CORE_RUNTIME_ROOT
    ? resolve(process.env.PC_CORE_RUNTIME_ROOT, 'packages', name)
    : resolve(root, 'pc/node_modules/@peanut-admin', name);
  const manifest = JSON.parse(
    readFileSync(resolve(packageRoot, 'package.json'), 'utf8')
  );
  const entry = resolve(packageRoot, manifest.exports['.'].import);
  const within = relative(packageRoot, entry);
  assert.ok(within !== '..' && !within.startsWith('..' + sep));
  return import(pathToFileURL(entry).href);
}
const client = await publicPackage('client');
const nuxt = await publicPackage('nuxt');

function request(fetch, server = false, options = {}) {
  const calls = { cleared: 0, redirects: [], messages: [], network: [] };
  const output = ts.transpileModule(source, {
    compilerOptions: {
      target: ts.ScriptTarget.ES2022,
      module: ts.ModuleKind.CommonJS,
    },
    transformers: {
      before: [
        (context) => (node) => {
          const visit = (child) =>
            ts.isMetaProperty(child)
              ? context.factory.createIdentifier('__runtime')
              : ts.visitEachChild(child, visit, context);
          return ts.visitNode(node, visit);
        },
      ],
    },
  }).outputText;
  const exports = {};
  vm.runInNewContext(output, {
    exports,
    Error,
    URL,
    __runtime: { server, client: !server },
    require(name) {
      if (name === '@peanut-admin/client') return client;
      if (name === '@peanut-admin/nuxt') return nuxt;
      throw new Error('Unexpected dependency: ' + name);
    },
    useRuntimeConfig: () => ({
      public: { apiBase: 'https://site.test' },
      upstreamOrigin: 'https://upstream.test',
      trustedHosts: 'site.test',
      forwardedProto: 'https',
    }),
    useRequestHeaders: () => ({
      host: 'site.test',
      cookie: 'must-not-forward',
    }),
    useUserStore: () => ({
      token: 'synthetic-token',
      clearSession: () => calls.cleared++,
    }),
    navigateTo: async (url) => {
      calls.redirects.push(url);
    },
    ElMessage: { error: (message) => calls.messages.push(message) },
    window: { location: { origin: 'https://site.test' } },
    $fetch: async (url, options) => {
      calls.network.push({ url, options });
      return fetch(url, options);
    },
  });
  return { api: exports.useRequest(options), calls };
}

for (const [name, value] of [
  ['null', null],
  ['array', []],
  ['missing data', { code: 20000, msg: '' }],
  ['string code', { code: '20000', msg: '', data: null }],
  ['object code', { code: {}, msg: '', data: null }],
  ['nonfinite code', { code: Infinity, msg: '', data: null }],
  ['fractional code', { code: 20000.5, msg: '', data: null }],
  ['numeric message', { code: 20000, msg: 5, data: null }],
  ['array message', { code: 20000, msg: [], data: null }],
  ['null message', { code: 20000, msg: null, data: null }],
  [
    'array disguised as envelope',
    Object.assign([], { code: 20000, msg: '', data: null }),
  ],
  ['malformed unauthorized', { code: 40100, msg: [], data: null }],
]) {
  test(`rejects ${name} instead of accepting or changing the session`, async () => {
    const h = request(async () => value);
    await assert.rejects(
      h.api.get('api/read', undefined, false),
      (error) => error.code === 'API_RESPONSE_INVALID'
    );
    assert.equal(h.calls.cleared, 0);
    assert.deepEqual(h.calls.redirects, []);
  });
}

for (const data of [null, [], {}, { id: 7 }]) {
  test(`retains valid data ${JSON.stringify(
    data
  )} without inventing endpoint validation`, async () => {
    const h = request(async () => ({ code: 20000, msg: '', data }));
    assert.equal(await h.api.get('api/read', undefined, false), data);
  });
}

test('preserves the established optional-message success envelope', async () => {
  const h = request(async () => ({ code: 20000, data: null }));
  assert.equal(await h.api.get('api/read', undefined, false), null);
});

test('valid authorization failure clears the client session and redirects once', async () => {
  const h = request(async () => ({
    code: 40100,
    msg: 'Login required',
    data: null,
  }));
  await assert.rejects(
    h.api.get('api/read'),
    (error) => error.kind === 'unauthorized' && error.code === '40100'
  );
  assert.equal(h.calls.cleared, 1);
  assert.deepEqual(h.calls.redirects, ['/login']);
});

test('optional personal extensions clear an expired session without redirecting public reading', async () => {
  const h = request(
    async () => ({ code: 40100, msg: 'Login required', data: null }),
    false,
    { redirectOnUnauthorized: false }
  );
  await assert.rejects(
    h.api.get('api/article/detail', { id: 1 }),
    (error) => error.kind === 'unauthorized'
  );
  assert.equal(h.calls.cleared, 1);
  assert.deepEqual(h.calls.redirects, []);
});

test('valid HTTP business failures are decoded without converting them into transport failures', async () => {
  const h = request(async () => {
    throw Object.assign(new Error('HTTP failure'), {
      data: { code: 40300, msg: 'Denied', data: null },
    });
  });
  await assert.rejects(
    h.api.get('api/read', undefined, false),
    (error) => error.kind === 'business' && error.code === '40300'
  );
});

test('malformed HTTP payloads stay transport failures and cannot fake success', async () => {
  const h = request(async () => {
    throw Object.assign(new Error('HTTP failure'), {
      data: { code: 20000, msg: [], data: 'fake-success' },
    });
  });
  await assert.rejects(
    h.api.get('api/read', undefined, false),
    (error) => error.kind === 'transport'
  );
});

test('SSR public requests keep trusted host context but never forward user credentials', async () => {
  const h = request(async () => ({ code: 20000, msg: '', data: null }), true);
  await h.api.get('api/read', { page: 2 }, false);
  const { url, options } = h.calls.network[0];
  assert.equal(url, 'https://upstream.test/api/read');
  assert.equal(options.headers.host, 'site.test');
  assert.equal(options.headers.cookie, undefined);
  assert.equal(options.headers.Authorization, undefined);
  assert.equal(options.headers.authorization, undefined);
  assert.equal(options.query.page, 2);
  assert.deepEqual(h.calls.messages, []);
});

test('ordinary network errors remain transport errors', async () => {
  const h = request(async () => {
    throw new Error('network failure');
  });
  await assert.rejects(
    h.api.get('api/read', undefined, false),
    (error) => error.kind === 'transport'
  );
});
