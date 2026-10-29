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
const webRequire = createRequire(path.join(root, 'web/package.json'));
const compiler = webRequire('vue/compiler-sfc');
const apiPath = path.join(root, 'web/src/modules/official-notification/api.ts');
const pagePath = path.join(
  root,
  'web/src/modules/official-notification/views/channel/index.vue'
);
const apiSource = fs.readFileSync(apiPath, 'utf8');
const pageSource = fs.readFileSync(pagePath, 'utf8');
const parsed = compiler.parse(pageSource, { filename: pagePath });
assert.deepEqual(parsed.errors, []);
assert.ok(
  parsed.descriptor.scriptSetup,
  'The actual page must have a script setup block.'
);
const pageScript = parsed.descriptor.scriptSetup.content;

const valid = () => ({
  sms_default: 'aliyun',
  sms_aliyun: {
    access_key_id: 'synthetic-id',
    access_key_secret: '******',
    sign_name: 'synthetic',
    status: 1,
  },
  sms_tencent: {
    secret_id: '',
    secret_key: '',
    sdk_app_id: '',
    sign_name: '',
    region: 'ap-guangzhou',
    status: 0,
  },
  status: { sms: true },
});

function loadApi(response, failure) {
  const requests = [];
  const client = {
    async get(url) {
      requests.push({ method: 'GET', url });
      if (failure) throw failure;
      return response;
    },
    async post(url, data) {
      requests.push({ method: 'POST', url, data });
      if (failure) throw failure;
      return response;
    },
  };
  const exports = {};
  const output = ts.transpileModule(apiSource, {
    compilerOptions: {
      target: ts.ScriptTarget.ES2022,
      module: ts.ModuleKind.CommonJS,
      esModuleInterop: true,
    },
  }).outputText;
  vm.runInNewContext(
    output,
    {
      exports,
      require(name) {
        assert.equal(name, 'axios');
        return client;
      },
    },
    { filename: apiPath }
  );
  return { api: exports, requests };
}

function strictCheck(typescript) {
  // Compile the complete API and the unmodified script-setup body against real
  // installed Axios/Vue/Element Plus declarations. This is not template or browser validation.
  const virtualPath = pagePath + '.script.ts';
  const options = {
    target: typescript.ScriptTarget.ES2020,
    module: typescript.ModuleKind.ESNext,
    moduleResolution: typescript.ModuleResolutionKind.NodeJs,
    strict: true,
    noEmit: true,
    skipLibCheck: true,
    esModuleInterop: true,
    baseUrl: path.join(root, 'web'),
    paths: { '@/*': ['src/*'] },
    types: [],
  };
  const host = typescript.createCompilerHost(options);
  const read = host.readFile.bind(host);
  const exists = host.fileExists.bind(host);
  host.readFile = (name) => (name === virtualPath ? pageScript : read(name));
  host.fileExists = (name) => name === virtualPath || exists(name);
  const program = typescript.createProgram(
    [apiPath, virtualPath],
    options,
    host
  );
  const errors = typescript.getPreEmitDiagnostics(program);
  assert.equal(
    errors.length,
    0,
    typescript.formatDiagnosticsWithColorAndContext(errors, {
      getCurrentDirectory: () => root,
      getCanonicalFileName: (name) => name,
      getNewLine: () => '\n',
    })
  );
}

test('API and page do not bypass the response boundary with assertions or suppression', () => {
  for (const source of [apiSource, pageScript]) {
    assert.doesNotMatch(
      source,
      /\bas\s+unknown\s+as\b|\bas\s+any\b|@ts-(?:ignore|nocheck)/
    );
  }
});

test('API and actual page script typecheck with the native Web compiler and the pinned quality compiler', () => {
  strictCheck(require(path.join(root, 'web/node_modules/typescript')));
  strictCheck(ts);
});

for (const provider of ['', 'aliyun', 'tencent']) {
  test(
    'preserves legal provider, flags and redacted secrets: ' +
      JSON.stringify(provider),
    async () => {
      const data = valid();
      data.sms_default = provider;
      const response = {
        code: 20000,
        msg: '',
        data,
        trace: 'synthetic-metadata',
      };
      const { api, requests } = loadApi(response);
      const received = await api.getNoticeChannelDetail();
      assert.equal(received.data, data);
      assert.equal(received.trace, 'synthetic-metadata');
      assert.equal(received.data.sms_aliyun.access_key_secret, '******');
      assert.equal(received.data.sms_tencent.secret_key, '');
      assert.deepEqual(requests, [
        {
          method: 'GET',
          url: '/adminapi/official.notification.channel.detail',
        },
      ]);
    }
  );
}

for (const [name, change] of [
  ['null payload', () => null],
  ['array payload', () => []],
  ['unknown provider', (d) => ({ ...d, sms_default: 'other' })],
  [
    'missing provider',
    (d) => {
      delete d.sms_default;
      return d;
    },
  ],
  ['array provider config', (d) => ({ ...d, sms_aliyun: [] })],
  [
    'missing provider config',
    (d) => {
      delete d.sms_tencent;
      return d;
    },
  ],
  [
    'numeric identifier',
    (d) => {
      d.sms_aliyun.access_key_id = 7;
      return d;
    },
  ],
  [
    'missing secret',
    (d) => {
      delete d.sms_tencent.secret_key;
      return d;
    },
  ],
  [
    'null secret',
    (d) => {
      d.sms_aliyun.access_key_secret = null;
      return d;
    },
  ],
  [
    'string enable flag',
    (d) => {
      d.sms_aliyun.status = '1';
      return d;
    },
  ],
  [
    'unknown enable flag',
    (d) => {
      d.sms_tencent.status = 2;
      return d;
    },
  ],
  [
    'missing region',
    (d) => {
      delete d.sms_tencent.region;
      return d;
    },
  ],
  ['array status', (d) => ({ ...d, status: [] })],
  ['string readiness', (d) => ({ ...d, status: { sms: 'false' } })],
]) {
  test(
    'rejects ' + name + ' before the page consumes configuration',
    async () => {
      const { api } = loadApi({ data: change(valid()) });
      await assert.rejects(
        api.getNoticeChannelDetail(),
        /NOTIFICATION_CHANNEL_RESPONSE_INVALID/
      );
    }
  );
}

test('transport failure remains the same failure', async () => {
  const failure = new Error('synthetic transport failure');
  const { api } = loadApi(undefined, failure);
  await assert.rejects(
    api.getNoticeChannelDetail(),
    (error) => error === failure
  );
});

test('channel saving retains URL, selected section and the redaction sentinel', async () => {
  const { api, requests } = loadApi({ data: null });
  await api.saveNoticeChannel('sms_aliyun', valid().sms_aliyun);
  assert.deepEqual(JSON.parse(JSON.stringify(requests)), [
    {
      method: 'POST',
      url: '/adminapi/official.notification.channel.save',
      data: { section: 'sms_aliyun', ...valid().sms_aliyun },
    },
  ]);
});
