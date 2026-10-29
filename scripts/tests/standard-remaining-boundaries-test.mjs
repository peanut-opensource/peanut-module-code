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
const vue = webRequire('vue');
const sfc = webRequire('vue/compiler-sfc');
const core = process.env.WEB_CORE_SOURCE_ROOT;
const deps = process.env.UNIAPP_DEPENDENCY_ROOT;
assert.ok(
  core && deps,
  'Explicit Web Core and native UniApp dependency roots are required'
);
const read = (p) => fs.readFileSync(path.join(root, p), 'utf8');
const pageScript = (p) => {
  const parsed = sfc.parse(read(p), { filename: p });
  assert.deepEqual(parsed.errors, []);
  assert.ok(parsed.descriptor.scriptSetup);
  return parsed.descriptor.scriptSetup.content;
};
const json = (value) => JSON.parse(JSON.stringify(value));
function execute(source, bindings = {}, globals = {}) {
  const exports = {};
  const output = ts.transpileModule(source, {
    compilerOptions: {
      target: ts.ScriptTarget.ES2022,
      module: ts.ModuleKind.CommonJS,
      esModuleInterop: true,
    },
    transformers: {
      before: [
        (ctx) => (node) => {
          const visit = (child) =>
            ts.isMetaProperty(child)
              ? ctx.factory.createIdentifier('__meta')
              : ts.visitEachChild(child, visit, ctx);
          return ts.visitNode(node, visit);
        },
      ],
    },
  }).outputText;
  vm.runInNewContext(output, {
    exports,
    Error,
    console,
    ...globals,
    require(name) {
      assert.ok(
        Object.hasOwn(bindings, name),
        'Unexpected dependency: ' + name
      );
      return bindings[name];
    },
  });
  return exports;
}
const mobile = 'web/src/views/decoration/mobile/index.vue';
const desktop = 'web/src/views/decoration/pc/index.vue';
const profile = 'uniapp/src/pages/user_data/user_data.vue';
const recharge = 'uniapp/src/packages/pages/recharge/recharge.vue';
const paymentFile = 'uniapp/src/utils/native-payment.ts';
const decoder = execute(read('web/src/api/decoration-model.ts'));
const sample = () => ({
  id: 8,
  type: 1,
  name: 'synthetic',
  update_time: 0,
  data: [
    { title: 'fixed', name: 'search', disabled: 1, content: [], styles: [] },
    {
      title: 'banner',
      name: 'banner',
      content: {
        enabled: 1,
        style: 1,
        data: [
          {
            image: '',
            name: '',
            link: {
              target_type: 'mini_program',
              target: '/pages/test',
              query: [],
            },
            is_show: 1,
          },
        ],
      },
      styles: { width: 12, height: '20px' },
    },
  ],
  meta: [
    {
      title: 'meta',
      name: 'page-meta',
      content: { title: 'title', bg_color: '#fff' },
      styles: [],
    },
  ],
  extension: { keep: true },
});

test('all five audited files and the new adapters reject any, nested assertions and suppressions', () => {
  const sources = [
    read('web/src/router/routes/index.ts'),
    ...[mobile, desktop, profile, recharge].map(pageScript),
    read(paymentFile),
    read('web/src/api/decoration-model.ts'),
  ];
  for (const source of sources) {
    assert.doesNotMatch(source, /@ts-(?:ignore|nocheck)/);
    const tree = ts.createSourceFile(
      'actual.ts',
      source,
      ts.ScriptTarget.Latest,
      true
    );
    const visit = (node) => {
      assert.notEqual(node.kind, ts.SyntaxKind.AnyKeyword);
      if (ts.isAsExpression(node))
        assert.equal(ts.isAsExpression(node.expression), false);
      ts.forEachChild(node, visit);
    };
    visit(tree);
  }
});

test('decoration accepts PHP empty object slots and preserves editable data, numeric styles and extensions', () => {
  const result = decoder.readDecorationPage(sample());
  assert.deepEqual(json(result.data[0].content), {});
  assert.deepEqual(json(result.data[0].styles), {});
  assert.deepEqual(json(result.data[1].content.data[0].link.query), {});
  assert.equal(result.data[1].styles.width, 12);
  assert.equal(result.extension.keep, true);
  result.data[1].content.data[0].name = 'changed';
  result.data.reverse();
  assert.equal(result.data[0].content.data[0].name, 'changed');
});
test('theme object and empty meta remain supported', () => {
  const result = decoder.readDecorationPage({
    ...sample(),
    type: 5,
    data: { themeColorId: 3 },
    meta: [],
  });
  assert.deepEqual(json(result.data), { themeColorId: 3 });
  assert.deepEqual(json(result.meta), []);
});
for (const [name, change] of [
  ['null page', () => null],
  ['string id', (d) => ({ ...d, id: '8' })],
  ['null data', (d) => ({ ...d, data: null })],
  ['invalid component', (d) => ({ ...d, data: [null] })],
  [
    'nonempty object-slot array',
    (d) => {
      d.data[0].content = [1];
      return d;
    },
  ],
  [
    'wrong content value',
    (d) => {
      d.data[1].content.enabled = 'yes';
      return d;
    },
  ],
  [
    'invalid item',
    (d) => {
      d.data[1].content.data = [null];
      return d;
    },
  ],
  [
    'invalid link',
    (d) => {
      d.data[1].content.data[0].link.target_type = 'unknown';
      return d;
    },
  ],
  [
    'invalid query',
    (d) => {
      d.data[1].content.data[0].link.query = { env_version: 'other' };
      return d;
    },
  ],
  [
    'object style',
    (d) => {
      d.data[1].styles.width = {};
      return d;
    },
  ],
])
  test('decoration rejects ' + name, () =>
    assert.throws(
      () => decoder.readDecorationPage(change(sample())),
      /DECORATION_RESPONSE_INVALID/
    )
  );

for (const client of ['Mobile', 'Pc'])
  test(
    client +
      ' API validates before publishing data and preserves save payload and transport failures',
    async () => {
      const calls = [];
      const failure = new Error('synthetic transport failure');
      let reply = { data: sample(), trace: 'keep' };
      const api = execute(read('web/src/api/decoration.ts'), {
        './decoration-model': decoder,
        'axios': {
          async get(url, options) {
            calls.push({ url, options });
            if (reply === failure) throw failure;
            return reply;
          },
          async post(url, data) {
            calls.push({ url, data });
            return { data: null };
          },
        },
      });
      const received = await api['get' + client + 'DecorationDetail'](8);
      assert.equal(received.trace, 'keep');
      assert.equal(calls[0].options.params.id, 8);
      const payload = {
        id: 8,
        type: 1,
        data: received.data.data,
        meta: received.data.meta,
      };
      await api['save' + client + 'Decoration'](payload);
      assert.equal(calls[1].data, payload);
      reply = { data: null };
      await assert.rejects(
        api['get' + client + 'DecorationDetail'](8),
        /DECORATION_RESPONSE_INVALID/
      );
      reply = failure;
      await assert.rejects(
        api['get' + client + 'DecorationDetail'](8),
        (e) => e === failure
      );
    }
  );

test('actual editor scripts retain in-place item mutations and submit the edited page', async () => {
  for (const isMobile of [true, false]) {
    const saved = [];
    const value = decoder.readDecorationPage(sample());
    if (!isMobile) {
      value.type = 4;
      value.data = [value.data[1]];
      value.data[0].name = 'pc-banner';
    }
    const api = { getDecorationArticleOptions: async () => ({ data: [] }) };
    for (const prefix of ['Mobile', 'Pc']) {
      api['get' + prefix + 'DecorationLists'] = async () => ({
        data: [{ id: 8, type: value.type }],
      });
      api['get' + prefix + 'DecorationDetail'] = async () => ({ data: value });
      api['save' + prefix + 'Decoration'] = async (payload) =>
        saved.push(payload);
    }
    const body =
      pageScript(isMobile ? mobile : desktop) +
      (isMobile
        ? '\nexport { page, components, addItem, handleSubmit };'
        : '\nexport { page, items, addItem, handleSubmit };');
    const page = execute(body, {
      vue,
      'element-plus': { ElMessage: { success() {} } },
      '@/components/file-picker/index.vue': {},
      '@/api/decoration': api,
    });
    await new Promise(setImmediate);
    if (isMobile) page.addItem(page.components.value[1]);
    else page.addItem();
    await page.handleSubmit();
    assert.equal(saved.length, 1);
    assert.equal(saved[0].data, page.page.data);
    const banner = saved[0].data.find((item) => item.name.endsWith('banner'));
    assert.equal(banner.content.data.length, 2);
  }
});

test('picker handlers accept native values and reject malformed events without changing state', () => {
  const page = execute(
    pageScript(profile) +
      '\nexport { form, sexIndex, onSexChange, onBirthdayChange };',
    {
      'vue': { ...vue, onMounted() {} },
      '@/store/user': { useUserStore: () => ({}) },
      '@/api/user': {},
    }
  );
  for (const value of [0, 1, 2, '0', '1', '2']) {
    page.onSexChange({ detail: { value } });
    assert.equal(page.sexIndex.value, Number(value));
  }
  page.onBirthdayChange({ detail: { value: '2000-02-29' } });
  assert.equal(page.form.value.birthday, '2000-02-29');
  for (const value of [null, [], false, {}, 3, ''])
    assert.throws(() => page.onSexChange({ detail: { value } }), /PICKER/);
  assert.throws(
    () => page.onBirthdayChange({ detail: { value: 3 } }),
    /PICKER/
  );
  assert.throws(() => page.onBirthdayChange(null), /PICKER/);
  assert.equal(page.sexIndex.value, 2);
  assert.equal(page.form.value.birthday, '2000-02-29');
});

function sdk(failure) {
  const calls = [];
  const api = execute(
    read(paymentFile),
    {},
    {
      uni: {
        requestPayment(options) {
          calls.push(options);
          if (failure) options.fail(failure);
          else options.success({});
        },
      },
    }
  );
  return { calls, request: api.requestNativePayment };
}
const signed = () => ({
  appId: 'synthetic',
  timeStamp: '1',
  nonceStr: 'nonce',
  package: 'prepay_id=synthetic',
  signType: 'RSA',
  paySign: 'signature',
});
test('native adapter maps the channel identifier and preserves SDK signed fields and APP order', async () => {
  const native = sdk();
  const payload = signed();
  await native.request({ channel: 'wechat', scene: 'JSAPI', payload });
  assert.equal(native.calls[0].provider, 'wxpay');
  for (const [key, value] of Object.entries(payload))
    assert.equal(native.calls[0][key], value);
  await native.request({ channel: 'wechat', scene: 'APP', payload });
  assert.equal(native.calls[1].orderInfo, payload);
  await native.request({
    channel: 'alipay',
    scene: 'APP',
    payload: { order_string: 'signed-order' },
  });
  assert.equal(native.calls[2].orderInfo, 'signed-order');
  assert.equal(native.calls[2].provider, 'alipay');
});
test('unsupported or malformed native requests never reach the SDK; failure remains failure', async () => {
  for (const payment of [
    { channel: 'other', scene: 'APP', payload: {} },
    { channel: 'wechat', scene: 'NATIVE', payload: {} },
    {
      channel: 'wechat',
      scene: 'JSAPI',
      payload: { ...signed(), paySign: {} },
    },
    { channel: 'alipay', scene: 'APP', payload: {} },
  ]) {
    const native = sdk();
    await assert.rejects(native.request(payment));
    assert.equal(native.calls.length, 0);
  }
  const failure = new Error('synthetic cancel');
  await assert.rejects(
    sdk(failure).request({
      channel: 'wechat',
      scene: 'JSAPI',
      payload: signed(),
    }),
    (e) => e === failure
  );
});

test('the menu projection preserves trusted route components, redirects and access metadata', () => {
  const component = () => Promise.resolve({ default: {} });
  const route = {
    path: '/system',
    name: 'system',
    component,
    redirect: '/system/settings',
    meta: { requiresAuth: true },
    children: [],
  };
  const result = execute(read('web/src/router/app-menus/index.ts'), {
    '../routes': { appRoutes: [route], appExternalRoutes: [] },
  }).default;
  assert.equal(result.length, 1);
  assert.notEqual(result[0], route);
  assert.equal(result[0].component, component);
  assert.equal(result[0].redirect, route.redirect);
  assert.equal(result[0].meta, route.meta);
  assert.equal(result[0].children, route.children);
});

test('route collection preserves source order and native deployment filtering without normalized casts', () => {
  const policy = execute(
    fs.readFileSync(
      path.join(core, 'packages/ui-vue/src/deployment-mode.ts'),
      'utf8'
    )
  );
  for (const mode of ['standalone', 'multi-tenant']) {
    const instanceAllowed = mode === 'standalone';
    const source = read('web/src/router/routes/index.ts');
    const route = (name, meta = {}) => ({ path: '/' + name, name, meta });
    let call = 0;
    const result = execute(
      source,
      {
        '@peanut-admin/ui-vue': policy,
        'virtual:peanut-instance-tool-routes': [
          route('tool', { instanceTool: true }),
        ],
        './plugin-contributions': { pluginRoutes: [route('plugin')] },
      },
      {
        __meta: {
          env: { DEV: true, VITE_DEPLOYMENT_MODE: mode },
          glob() {
            return call++ === 0
              ? {
                  first: { default: route('one') },
                  none: {},
                  second: {
                    default: [
                      route('two'),
                      route('platform', { controlPlane: true }),
                    ],
                  },
                }
              : { external: { default: route('outside') } };
          },
        },
        __PEANUT_INSTANCE_TOOLS_COMPILED__: instanceAllowed,
      }
    );
    assert.deepEqual(
      json(result.appRoutes.map((r) => r.name)),
      instanceAllowed
        ? ['one', 'two', 'tool', 'plugin']
        : ['one', 'two', 'platform', 'plugin']
    );
    assert.equal(result.appExternalRoutes[0].name, 'outside');
  }
});

test('native payment and actual mobile page scripts typecheck against installed declarations and selected Core', () => {
  const installed = path.resolve(deps, 'node_modules');
  const virtuals = Object.fromEntries(
    [profile, recharge].map((p) => [
      path.join(root, p + '.script.ts'),
      pageScript(p),
    ])
  );
  const ambient = path.join(root, '.local/tmp/standard-remaining/ambient.d.ts');
  virtuals[ambient] =
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
      'vue': [path.join(installed, 'vue/dist/vue.d.ts')],
      'pinia': [path.join(installed, 'pinia/dist/pinia.d.ts')],
      '@dcloudio/uni-app': [path.join(installed, '@dcloudio/uni-app')],
      '@peanut-admin/client': [path.join(core, 'packages/client/src/index.ts')],
      '@peanut-admin/uniapp': [path.join(core, 'packages/uniapp/src/index.ts')],
    },
  };
  const host = ts.createCompilerHost(options);
  const originalRead = host.readFile.bind(host),
    originalExists = host.fileExists.bind(host);
  host.readFile = (p) => virtuals[p] ?? originalRead(p);
  host.fileExists = (p) => Object.hasOwn(virtuals, p) || originalExists(p);
  const program = ts.createProgram(
    [
      ...Object.keys(virtuals),
      path.join(root, paymentFile),
      path.join(installed, '@dcloudio/types/index.d.ts'),
    ],
    options,
    host
  );
  const errors = ts.getPreEmitDiagnostics(program);
  assert.equal(
    errors.length,
    0,
    ts.formatDiagnosticsWithColorAndContext(errors, {
      getCurrentDirectory: () => root,
      getCanonicalFileName: (p) => p,
      getNewLine: () => '\n',
    })
  );
});
