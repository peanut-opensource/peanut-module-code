import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import test from 'node:test';

const root = path.resolve(
  path.dirname(fileURLToPath(import.meta.url)),
  '../..'
);
const require = createRequire(import.meta.url);
const ts = require(path.join(root, 'tools/quality/node_modules/typescript'));
const sourcePath = path.join(root, 'uniapp/src/utils/request.ts');
const source = fs.readFileSync(sourcePath, 'utf8');
const dependencyRoot = process.env.UNIAPP_DEPENDENCY_ROOT;
const coreRoot = process.env.UNIAPP_WEB_CORE_ROOT;
assert.ok(
  dependencyRoot,
  'Set UNIAPP_DEPENDENCY_ROOT to the native dependency checkout'
);
assert.ok(
  coreRoot,
  'Set UNIAPP_WEB_CORE_ROOT to the selected Web Core checkout'
);
const coreFiles = {
  '@peanut-admin/client': path.join(
    path.resolve(coreRoot),
    'packages/client/src/index.ts'
  ),
  '@peanut-admin/uniapp': path.join(
    path.resolve(coreRoot),
    'packages/uniapp/src/index.ts'
  ),
};
for (const file of Object.values(coreFiles))
  assert.ok(fs.existsSync(file), file);

const json = (value) => JSON.parse(JSON.stringify(value));
function runtime({
  reply = { code: 20000, data: { ok: true } },
  configured = 'https://example.test',
  location,
  token = 'synthetic-token',
  fail,
} = {}) {
  const requests = [];
  const toasts = [];
  const navigations = [];
  const store = {
    token,
    logout() {
      this.token = '';
    },
  };
  const context = vm.createContext({
    __env: { VITE_APP_BASE_URL: configured },
    ...(location === undefined ? {} : { location }),
    uni: {
      request(options) {
        requests.push(options);
        if (fail) options.fail(fail);
        else options.success({ data: reply });
      },
      showToast: (options) => toasts.push(options),
      reLaunch: (options) => navigations.push(options),
    },
  });
  const modules = new Map();
  function load(file) {
    if (modules.has(file)) return modules.get(file);
    const output = ts.transpileModule(fs.readFileSync(file, 'utf8'), {
      compilerOptions: {
        module: ts.ModuleKind.CommonJS,
        target: ts.ScriptTarget.ES2022,
      },
      transformers: {
        before: [
          (ctx) => (node) => {
            const visit = (child) =>
              ts.isPropertyAccessExpression(child) &&
              child.name.text === 'env' &&
              ts.isMetaProperty(child.expression)
                ? ctx.factory.createIdentifier('__env')
                : ts.visitEachChild(child, visit, ctx);
            return ts.visitNode(node, visit);
          },
        ],
      },
    }).outputText;
    const exports = {};
    modules.set(file, exports);
    const fn = vm.runInContext(
      '(function(exports, require) {' + output + '\n})',
      context,
      { filename: file }
    );
    fn(exports, (name) => {
      if (name === '@/store/user') return { useUserStore: () => store };
      if (coreFiles[name]) return load(coreFiles[name]);
      throw new Error('Unexpected runtime dependency: ' + name);
    });
    return exports;
  }
  return { http: load(sourcePath).http, requests, toasts, navigations, store };
}

test('request source has no any, double assertions or suppression directives', () => {
  const parsed = ts.createSourceFile(
    sourcePath,
    source,
    ts.ScriptTarget.Latest,
    true
  );
  const violations = [];
  const visit = (node) => {
    if (node.kind === ts.SyntaxKind.AnyKeyword) violations.push('any');
    if (ts.isAsExpression(node) && ts.isAsExpression(node.expression))
      violations.push('double assertion');
    ts.forEachChild(node, visit);
  };
  visit(parsed);
  assert.deepEqual(violations, []);
  assert.doesNotMatch(
    source,
    /@ts-(?:ignore|nocheck)|eslint-disable[^\n]*no-explicit-any/
  );
});

test('request and every current API consumer typecheck with actual UniApp, Vue, Pinia and selected Core declarations', () => {
  const deps = path.resolve(dependencyRoot, 'node_modules');
  const dcloud = path.join(deps, '@dcloudio/types/index.d.ts');
  assert.ok(fs.existsSync(dcloud), 'Real DCloud declarations are required');
  const ambientPath = path.join(
    root,
    '.local/tmp/uniapp-request-boundary-ambient.d.ts'
  );
  const ambient =
    'interface ImportMeta { readonly env: Readonly<Record<string, string | undefined>>; }';
  const options = {
    target: ts.ScriptTarget.ES2022,
    module: ts.ModuleKind.ESNext,
    moduleResolution: ts.ModuleResolutionKind.Bundler,
    strict: true,
    noEmit: true,
    skipLibCheck: true,
    types: [],
    baseUrl: root,
    paths: {
      '@/*': ['uniapp/src/*'],
      ...Object.fromEntries(
        Object.entries(coreFiles).map(([name, file]) => [name, [file]])
      ),
      'pinia': [path.join(deps, 'pinia/dist/pinia.d.ts')],
      'vue': [path.join(deps, 'vue/dist/vue.d.ts')],
    },
  };
  const host = ts.createCompilerHost(options);
  const read = host.readFile.bind(host);
  const exists = host.fileExists.bind(host);
  host.readFile = (name) => (name === ambientPath ? ambient : read(name));
  host.fileExists = (name) => name === ambientPath || exists(name);
  const apiRoot = path.join(root, 'uniapp/src/api');
  const consumers = fs
    .readdirSync(apiRoot)
    .filter((name) => name.endsWith('.ts'))
    .map((name) => path.join(apiRoot, name));
  assert.ok(consumers.length > 0, 'Do not allow an empty consumer check');
  const program = ts.createProgram(
    [sourcePath, ...consumers, dcloud, ambientPath],
    options,
    host
  );
  const diagnostics = ts.getPreEmitDiagnostics(program);
  assert.equal(
    diagnostics.length,
    0,
    ts.formatDiagnosticsWithColorAndContext(diagnostics, {
      getCurrentDirectory: () => root,
      getCanonicalFileName: (name) => name,
      getNewLine: () => '\n',
    })
  );
});

test('GET and POST preserve URL, data, headers and current session token', async () => {
  const r = runtime();
  assert.deepEqual(json(await r.http.get('api/example', { page: 2 })), {
    ok: true,
  });
  r.store.token = 'second-synthetic-token';
  await r.http.post('api/example', { title: 'synthetic' });
  assert.equal(r.requests[0].url, 'https://example.test/api/example');
  assert.equal(r.requests[0].method, 'GET');
  assert.deepEqual(json(r.requests[0].data), { page: 2 });
  assert.equal(r.requests[0].header.authorization, 'Bearer synthetic-token');
  assert.equal(
    r.requests[1].header.authorization,
    'Bearer second-synthetic-token'
  );
  assert.equal(r.requests[1].method, 'POST');
  assert.deepEqual(json(r.requests[1].data), { title: 'synthetic' });
});

test('public request has no authorization header and absent data remains absent', async () => {
  const r = runtime();
  await r.http.get('api/public', undefined, false);
  assert.equal(r.requests[0].header.authorization, undefined);
  assert.equal(r.requests[0].data, undefined);
});

test('H5 origin supplies the unconfigured browser base URL', async () => {
  const r = runtime({
    configured: '',
    location: { origin: 'https://h5.example.test' },
  });
  await r.http.get('api/public', undefined, false);
  assert.equal(r.requests[0].url, 'https://h5.example.test/api/public');
});

for (const [name, location] of [
  ['no browser global', undefined],
  ['null location', null],
  ['numeric origin', { origin: 7 }],
])
  test('missing base remains rejected before network: ' + name, async () => {
    const r = runtime({ configured: '', location });
    // The real Core requires an absolute configured or H5 origin. Never invent a host.
    await assert.rejects(
      r.http.get('api/public', undefined, false),
      (error) => error.kind === 'transport'
    );
    assert.equal(r.requests.length, 0);
  });

test('configured base takes precedence over runtime origin', async () => {
  const r = runtime({ location: { origin: 'https://unused.example.test' } });
  await r.http.get('api/public', undefined, false);
  assert.equal(r.requests[0].url, 'https://example.test/api/public');
});

for (const [name, reply] of [
  ['null', null],
  ['array', []],
  ['string code', { code: '20000', data: {} }],
  ['missing code', { data: {} }],
  ['missing success data', { code: 20000 }],
  ['object message', { code: 40000, msg: { invalid: true } }],
])
  test(
    'malformed response rejects ' + name + ' without logging out',
    async () => {
      const r = runtime({ reply });
      await assert.rejects(
        r.http.get('api/example'),
        (error) => error.code === 'API_RESPONSE_INVALID'
      );
      assert.equal(r.store.token, 'synthetic-token');
      assert.equal(r.navigations.length, 0);
    }
  );

for (const data of [null, [], {}, false, 0, ''])
  test('success preserves data ' + JSON.stringify(data), async () => {
    const r = runtime({ reply: { code: 20000, data } });
    assert.deepEqual(json(await r.http.get('api/example')), data);
  });

test('unauthorized clears the current session and uses the existing login route', async () => {
  const r = runtime({ reply: { code: 40100, msg: 'expired' } });
  await assert.rejects(
    r.http.get('api/example'),
    (error) => error.kind === 'unauthorized'
  );
  assert.equal(r.store.token, '');
  assert.equal(r.navigations[0].url, '/pages/login/login');
});

test('business failure preserves its message without reporting a network success', async () => {
  const r = runtime({ reply: { code: 40000, msg: 'rejected' } });
  await assert.rejects(
    r.http.get('api/example'),
    (error) => error.kind === 'business'
  );
  assert.equal(r.toasts[0].title, 'rejected');
  assert.equal(r.store.token, 'synthetic-token');
});

test('transport failure stays a failure and keeps the session', async () => {
  const r = runtime({ fail: { errMsg: 'synthetic unavailable' } });
  await assert.rejects(
    r.http.get('api/example'),
    (error) => error.kind === 'transport'
  );
  assert.equal(r.toasts.at(-1).title, '网络错误，请稍后重试');
  assert.equal(r.store.token, 'synthetic-token');
});

for (const data of [[], 1, 'invalid', null])
  test(
    'invalid request data rejected before native transport: ' +
      JSON.stringify(data),
    async () => {
      const r = runtime();
      await assert.rejects(r.http.post('api/example', data));
      assert.equal(r.requests.length, 0);
    }
  );
