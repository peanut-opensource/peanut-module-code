import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import vm from 'node:vm';
import test from 'node:test';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const require = createRequire(import.meta.url);
const native = createRequire(resolve(root, 'uniapp/package.json'));
const ts = native('typescript');
const sfc = native('vue/compiler-sfc');

test('H5 policy query changes replace old content and reject stale responses', async () => {
  const path = resolve(root, 'uniapp/src/pages/agreement/agreement.vue');
  const { descriptor, errors } = sfc.parse(readFileSync(path, 'utf8'));
  assert.deepEqual(errors, []);
  const hooks = { load: [], unload: [], mount: [], unmount: [] };
  const listeners = new Map();
  const requests = [];
  const titles = [];
  const page = { location: { hash: '#/pages/agreement/agreement?type=privacy' },
    addEventListener(event, handler) { listeners.set(event, handler); },
    removeEventListener(event, handler) {
      if (listeners.get(event) === handler) listeners.delete(event);
    },
  };
  const source = descriptor.scriptSetup.content
    + '\nexport const observed = { policy, loading, errorMessage };';
  const compiled = ts.transpileModule(source, {
    compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.CommonJS },
  }).outputText;
  const exports = {};
  vm.runInNewContext(compiled, {
    exports, window: page, URLSearchParams,
    uni: { setNavigationBarTitle(value) { titles.push(value.title); } },
    require(name) {
      if (name === 'vue') return {
        ref(value) { return { value }; },
        onMounted(fn) { hooks.mount.push(fn); },
        onUnmounted(fn) { hooks.unmount.push(fn); },
      };
      if (name === '@dcloudio/uni-app') return {
        onLoad(fn) { hooks.load.push(fn); },
        onUnload(fn) { hooks.unload.push(fn); },
      };
      if (name === '@/utils/page-input') return {
        policyKind(value) {
          if (value === undefined || value === '') return 'service';
          if (value === 'privacy' || value === 'service') return value;
          throw new Error('POLICY_KIND_INVALID');
        },
      };
      if (name === '@/api/index') return {
        getPolicy(type) {
          return new Promise((resolve) => requests.push({ type, resolve }));
        },
      };
      throw new Error('Unexpected import ' + name);
    },
  });
  hooks.load[0]({ type: 'privacy' });
  hooks.mount[0]();
  assert.deepEqual(requests.map((r) => r.type), ['privacy']);
  page.location.hash = '#/pages/agreement/agreement?type=service';
  listeners.get('hashchange')();
  assert.deepEqual(requests.map((r) => r.type), ['privacy', 'service']);
  assert.equal(exports.observed.policy.value, null);
  requests[1].resolve({ title: '服务条款', content: 'service text' });
  await new Promise((resolve) => setImmediate(resolve));
  requests[0].resolve({ title: '隐私政策', content: 'stale text' });
  await new Promise((resolve) => setImmediate(resolve));
  assert.equal(exports.observed.policy.value.content, 'service text');
  assert.equal(titles.at(-1), '用户协议');
  page.location.hash = '#/pages/agreement/agreement?type=invalid';
  listeners.get('hashchange')();
  assert.equal(exports.observed.policy.value, null);
  assert.equal(exports.observed.errorMessage.value, '协议类型无效');
  hooks.unmount[0]();
  hooks.unload[0]();
  assert.equal(listeners.has('hashchange'), false);
  hooks.load[0]({ type: 'service' });
  assert.equal(requests.length, 3);
  assert.equal(exports.observed.policy.value, null);
});
