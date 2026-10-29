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
const sourcePath = path.join(root, 'pc/composables/useRequest.ts');
const source = fs.readFileSync(sourcePath, 'utf8');
const parsed = ts.createSourceFile(
  sourcePath,
  source,
  ts.ScriptTarget.Latest,
  true
);
const dependencyRoot = process.env.PC_DEPENDENCY_ROOT;
const coreRoot = process.env.PC_WEB_CORE_ROOT;

function adapter(fetch, server = false) {
  let transport;
  const runtime = { client: !server, server };
  const transformed = ts.transpileModule(source, {
    compilerOptions: {
      module: ts.ModuleKind.CommonJS,
      target: ts.ScriptTarget.ES2022,
    },
    transformers: {
      before: [
        (context) => (node) => {
          const visit = (child) =>
            ts.isMetaProperty(child) &&
            child.keywordToken === ts.SyntaxKind.ImportKeyword
              ? context.factory.createIdentifier('__runtime')
              : ts.visitEachChild(child, visit, context);
          return ts.visitNode(node, visit);
        },
      ],
    },
  }).outputText;
  const exports = {};
  vm.runInNewContext(
    transformed,
    {
      exports,
      __runtime: runtime,
      require(name) {
        if (name === '@peanut-admin/client')
          return {
            createClient: (options) => ({
              request: (request) => options.transport(request),
            }),
          };
        if (name === '@peanut-admin/nuxt')
          return {
            createNuxtClientTransport: (options) => {
              transport = options;
              return async () => undefined;
            },
            createNuxtSsrForwardHeaders: () => ({}),
          };
        throw new Error('Unexpected dependency: ' + name);
      },
      $fetch: fetch,
      useRuntimeConfig: () => ({
        public: { apiBase: 'https://example.test' },
        upstreamOrigin: 'https://upstream.test',
        trustedHosts: 'example.test',
        forwardedProto: 'https',
      }),
      useRequestHeaders: () => ({ host: 'example.test' }),
      useUserStore: () => ({ token: 'synthetic-token', clearSession() {} }),
      navigateTo: async () => undefined,
      ElMessage: { error() {} },
      window: { location: { origin: 'https://example.test' } },
    },
    { filename: sourcePath }
  );
  exports.useRequest();
  assert.equal(typeof transport?.$fetch, 'function');
  return transport.$fetch;
}

test('actual PC source does not silence the fetch boundary with a double assertion', () => {
  let doubleAssertion = false;
  const visit = (node) => {
    if (ts.isAsExpression(node) && ts.isAsExpression(node.expression))
      doubleAssertion = true;
    ts.forEachChild(node, visit);
  };
  visit(parsed);
  assert.equal(doubleAssertion, false);
});

test('actual source typechecks against selected Core and installed Nitro fetch declarations', () => {
  assert.ok(
    dependencyRoot,
    'Set PC_DEPENDENCY_ROOT to an explicitly selected native PC dependency checkout'
  );
  assert.ok(
    coreRoot,
    'Set PC_WEB_CORE_ROOT to the selected Web Core source checkout'
  );
  const nitroTypes = path.join(
    path.resolve(dependencyRoot),
    'node_modules/nitropack/types.d.ts'
  );
  assert.ok(
    fs.existsSync(nitroTypes),
    'Real Nitro declarations must be installed, not substituted'
  );
  const ambientPath = path.join(
    root,
    '.local/tmp/pc-request-options-ambient.d.ts'
  );
  const ambient = `
        declare const $fetch: import(${JSON.stringify(nitroTypes)}).$Fetch;
        declare function useRuntimeConfig(): { public: { apiBase?: string }; trustedHosts?: string; upstreamOrigin?: string; forwardedProto?: string };
        declare function useRequestHeaders(names: string[]): Record<string,string|undefined>;
        declare function useUserStore(): { token?: string; clearSession(): void };
        declare function navigateTo(path: string): Promise<void>;
        declare const ElMessage: { error(message: string): void };
        interface ImportMeta { server: boolean; client: boolean; }
    `;
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
      '@peanut-admin/client': [
        path.join(path.resolve(coreRoot), 'packages/client/src/index.ts'),
      ],
      '@peanut-admin/nuxt': [
        path.join(path.resolve(coreRoot), 'packages/nuxt/src/index.ts'),
      ],
    },
  };
  const host = ts.createCompilerHost(options);
  const originalRead = host.readFile.bind(host);
  const originalExists = host.fileExists.bind(host);
  host.readFile = (name) =>
    name === ambientPath ? ambient : originalRead(name);
  host.fileExists = (name) => name === ambientPath || originalExists(name);
  const program = ts.createProgram([sourcePath, ambientPath], options, host);
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

test('GET query and transport headers remain intact', async () => {
  let received;
  const fetch = adapter(async (url, options) => {
    received = { url, options };
    return { ok: true };
  });
  const response = await fetch('/public', {
    method: 'GET',
    query: { page: 2 },
    headers: { 'x-request-id': 'synthetic' },
  });
  assert.deepEqual(response, { ok: true });
  assert.deepEqual(JSON.parse(JSON.stringify(received)), {
    url: '/public',
    options: {
      method: 'GET',
      query: { page: 2 },
      headers: { 'x-request-id': 'synthetic' },
    },
  });
});

test('POST body, null body and absent options remain supported', async () => {
  const calls = [];
  const fetch = adapter(async (url, options) => {
    calls.push(options);
    return null;
  });
  await fetch('/write', { method: 'POST', body: { title: 'synthetic' } });
  await fetch('/write', { method: 'POST', body: null });
  await fetch('/read');
  assert.deepEqual(JSON.parse(JSON.stringify(calls.slice(0, 2))), [
    { method: 'POST', body: { title: 'synthetic' } },
    { method: 'POST', body: null },
  ]);
  assert.equal(
    calls[2],
    undefined,
    'Absent options must remain absent instead of being replaced with a new object'
  );
});

for (const [name, options, code] of [
  ['invalid method', { method: 'INVALID' }, 'PC_FETCH_METHOD_INVALID'],
  [
    'scalar query',
    { method: 'GET', query: 'invalid' },
    'PC_FETCH_QUERY_INVALID',
  ],
  ['array query', { method: 'GET', query: [] }, 'PC_FETCH_QUERY_INVALID'],
  ['scalar body', { method: 'POST', body: false }, 'PC_FETCH_BODY_INVALID'],
  ['array body', { method: 'POST', body: [] }, 'PC_FETCH_BODY_INVALID'],
]) {
  test(name + ' is rejected before network execution', async () => {
    let calls = 0;
    const fetch = adapter(async () => {
      calls++;
      return {};
    });
    await assert.rejects(fetch('/synthetic', options), new RegExp(code));
    assert.equal(calls, 0);
  });
}

test('HTTP API error payload is retained while transport errors propagate unchanged', async () => {
  const payload = { code: 40300, msg: 'synthetic denial', data: null };
  const failedApi = adapter(async () => {
    throw Object.assign(new Error('HTTP failure'), { data: payload });
  });
  assert.equal(await failedApi('/protected', { method: 'GET' }), payload);
  const network = new Error('synthetic network failure');
  const failedNetwork = adapter(async () => {
    throw network;
  }, true);
  await assert.rejects(
    failedNetwork('/public', { method: 'GET' }),
    (error) => error === network
  );
});
