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
const sourcePath = path.join(root, 'web/src/api/installation.ts');
const source = fs.readFileSync(sourcePath, 'utf8');
const parsed = ts.createSourceFile(
  sourcePath,
  source,
  ts.ScriptTarget.Latest,
  true
);

// Execute the complete checked-in client. Only Axios/network responses are substituted;
// no installer, database, shared interceptor or backend authentication is invoked.
function client(body, failure) {
  const calls = [];
  const transport = {};
  for (const method of ['get', 'post']) {
    transport[method] = async (...args) => {
      calls.push({ method, args });
      if (failure) throw failure;
      return { data: body };
    };
  }
  const exports = {};
  const output = ts.transpileModule(source, {
    compilerOptions: {
      module: ts.ModuleKind.CommonJS,
      target: ts.ScriptTarget.ES2022,
      esModuleInterop: true,
    },
    transformers: {
      before: [
        (context) => (node) => {
          const visit = (child) =>
            ts.isMetaProperty(child) &&
            child.keywordToken === ts.SyntaxKind.ImportKeyword
              ? context.factory.createIdentifier('__meta')
              : ts.visitEachChild(child, visit, context);
          return ts.visitNode(node, visit);
        },
      ],
    },
  }).outputText;
  vm.runInNewContext(
    output,
    {
      exports,
      __meta: { env: {} },
      require(name) {
        assert.equal(
          name,
          'axios',
          'The isolated client must not load a shared session/interceptor'
        );
        return { create: () => transport };
      },
    },
    { filename: sourcePath }
  );
  return { api: exports, calls };
}

const status = () => ({
  state: 'uninstalled',
  mode: 'guided',
  deployment_mode: 'standalone',
  preflight: null,
});
const envelope = (data) => ({ code: 20000, msg: 'ok', data });
const payload = {
  admin_email: 'admin@example.test',
  admin_password: 'synthetic',
  platform_email: 'platform@example.test',
  platform_password: 'synthetic',
  official_modules: ['official.article'],
};
const plain = (value) => JSON.parse(JSON.stringify(value));

test('source rejects double assertions instead of suppressing the response boundary', () => {
  let invalid = false;
  const visit = (node) => {
    if (ts.isAsExpression(node) && ts.isAsExpression(node.expression))
      invalid = true;
    ts.forEachChild(node, visit);
  };
  visit(parsed);
  assert.equal(invalid, false);
});

test('the entire client typechecks strictly against real installed Axios declarations', () => {
  const declaration = path.join(root, 'web/node_modules/axios/index.d.ts');
  assert.ok(
    fs.existsSync(declaration),
    'Install the selected web dependencies; do not synthesize Axios types'
  );
  const ambientPath = path.join(
    root,
    '.local/tmp/installation-response-ambient.d.ts'
  );
  const ambient =
    'interface ImportMeta { readonly env: { readonly VITE_API_BASE_URL?: string }; }';
  const options = {
    target: ts.ScriptTarget.ES2022,
    module: ts.ModuleKind.ESNext,
    moduleResolution: ts.ModuleResolutionKind.Bundler,
    strict: true,
    noEmit: true,
    skipLibCheck: true,
    esModuleInterop: true,
    types: [],
  };
  const host = ts.createCompilerHost(options);
  const read = host.readFile.bind(host);
  const exists = host.fileExists.bind(host);
  host.readFile = (name) => (name === ambientPath ? ambient : read(name));
  host.fileExists = (name) => name === ambientPath || exists(name);
  const diagnostics = ts.getPreEmitDiagnostics(
    ts.createProgram([sourcePath, ambientPath], options, host)
  );
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

test('all declared installation states and modes retain their exact values', async () => {
  for (const state of ['uninstalled', 'installed', 'blocked']) {
    for (const mode of ['guided', 'automatic']) {
      for (const deployment_mode of ['standalone', 'multi-tenant']) {
        const value = { state, mode, deployment_mode, preflight: null };
        const { api, calls } = client(envelope(value));
        assert.deepEqual(plain(await api.getInstallationStatus()), value);
        assert.deepEqual(calls, [
          { method: 'get', args: ['/installapi/status'] },
        ]);
      }
    }
  }
});

test('validated preflight checks, module choices, retry flags and health retain their values', async () => {
  const value = {
    ...status(),
    preflight: {
      status: 'blocked',
      checks: [
        {
          id: 'database',
          status: 'failed',
          code: 'UNAVAILABLE',
          remediation: 'Configure the connection',
        },
      ],
      modules: [
        'official.file',
        {
          key: 'official.article',
          name: 'Article',
          selected: true,
          required: false,
        },
      ],
      official_modules: [],
      extension: { preserved: true },
    },
    official_modules: [
      {
        key: 'official.file',
        default: true,
        label: 'Files',
        description: 'Media',
      },
    ],
    retryable: false,
    code: 'ENVIRONMENT_UNAVAILABLE',
    health: { ready: false },
  };
  assert.deepEqual(
    plain(await client(envelope(value)).api.getInstallationStatus()),
    value
  );
});

test('an omitted preflight remains the documented null default', async () => {
  const value = status();
  delete value.preflight;
  assert.equal(
    (await client(envelope(value)).api.getInstallationStatus()).preflight,
    null
  );
});

for (const [name, patch] of [
  ['coercible state object', { state: { toString: () => 'installed' } }],
  ['coercible state array', { state: ['installed'] }],
  ['coercible mode array', { mode: ['guided'] }],
  ['coercible deployment array', { deployment_mode: ['standalone'] }],
  ['array preflight', { preflight: [] }],
  ['numeric preflight status', { preflight: { status: 1 } }],
  ['non-array checks', { preflight: { checks: {} } }],
  ['invalid check field', { preflight: { checks: [{ remediation: 1 }] } }],
  ['array check', { preflight: { checks: [[]] } }],
  ['invalid preflight module', { preflight: { modules: [null] } }],
  [
    'invalid preflight official modules',
    { preflight: { official_modules: [{ key: false }] } },
  ],
  ['non-array module choices', { official_modules: {} }],
  ['missing module key', { official_modules: [{ label: 'Missing key' }] }],
  ['numeric module key', { official_modules: [{ key: 10 }] }],
  [
    'string selected flag',
    { official_modules: [{ key: 'official.file', selected: 'false' }] },
  ],
  [
    'invalid optional module label',
    { official_modules: [{ key: 'official.file', label: [] }] },
  ],
  ['string retry flag', { retryable: 'false' }],
  ['numeric error code', { code: 10 }],
  ['array health', { health: [] }],
]) {
  test(`status rejects ${name}`, async () => {
    await assert.rejects(
      client(envelope({ ...status(), ...patch })).api.getInstallationStatus(),
      /invalid/i
    );
  });
}

for (const [name, body] of [
  ['null envelope', null],
  ['missing data', { code: 20000 }],
  ['string success code', { code: '20000', data: status() }],
]) {
  test(`rejects ${name}`, async () => {
    await assert.rejects(client(body).api.getInstallationStatus());
  });
}

test('application errors and transport failures remain failures', async () => {
  await assert.rejects(
    client({
      code: 50000,
      msg: 'synthetic server failure',
      data: null,
    }).api.getInstallationStatus(),
    /synthetic server failure/
  );
  const failure = new Error('synthetic transport failure');
  await assert.rejects(
    client(null, failure).api.getInstallationStatus(),
    (error) => error === failure
  );
});

test('execute keeps the one-time token and explicit write payload', async () => {
  const value = { state: 'installed', health: { ready: true } };
  const { api, calls } = client(envelope(value));
  const result = await api.executeInstallation(' synthetic-token ', {
    ...payload,
    tenant_id: 999,
  });
  assert.deepEqual(plain(result), value);
  assert.deepEqual(plain(calls), [
    {
      method: 'post',
      args: [
        '/installapi/execute',
        payload,
        { headers: { Authorization: 'Bearer synthetic-token' } },
      ],
    },
  ]);
});

test('a blank token never submits an installation request', async () => {
  const { api, calls } = client(envelope({ state: 'installed', health: {} }));
  await assert.rejects(api.executeInstallation(' ', payload), /setup token/);
  assert.equal(calls.length, 0);
});

for (const [name, value] of [
  ['array health', { state: 'installed', health: [] }],
  ['null health', { state: 'installed', health: null }],
  ['missing health', { state: 'installed' }],
  ['wrong state', { state: 'uninstalled', health: {} }],
]) {
  test(`execute rejects ${name}`, async () => {
    await assert.rejects(
      client(envelope(value)).api.executeInstallation(
        'synthetic-token',
        payload
      )
    );
  });
}
