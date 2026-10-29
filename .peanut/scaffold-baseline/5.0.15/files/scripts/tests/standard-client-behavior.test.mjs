#!/usr/bin/env node
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { resolve } from 'node:path';
import vm from 'node:vm';
import test from 'node:test';

const root = fileURLToPath(new URL('../..', import.meta.url));
const projectRequire = createRequire(resolve(root, 'web/package.json'));
const ts = projectRequire('typescript');
const vue = projectRequire('vue');
const sfc = projectRequire('vue/compiler-sfc');
const tick = () => new Promise((done) => setImmediate(done));
const deferred = () => {
  let resolvePromise;
  let rejectPromise;
  const promise = new Promise((resolve, reject) => {
    resolvePromise = resolve;
    rejectPromise = reject;
  });
  return { promise, resolve: resolvePromise, reject: rejectPromise };
};

function executeTypeScript(source, bindings, globals = {}) {
  const result = ts.transpileModule(source, {
    compilerOptions: {
      target: ts.ScriptTarget.ES2022,
      module: ts.ModuleKind.CommonJS,
    },
    reportDiagnostics: true,
  });
  const failures = (result.diagnostics ?? []).filter(
    (item) => item.category === ts.DiagnosticCategory.Error
  );
  assert.deepEqual(failures, []);
  const exports = {};
  const context = vm.createContext({
    exports,
    ...globals,
    require(name) {
      assert.ok(
        Object.hasOwn(bindings, name),
        `Unexpected dependency: ${name}`
      );
      return bindings[name];
    },
  });
  new vm.Script(result.outputText, {
    filename: 'actual-candidate-source.cjs',
  }).runInContext(context);
  return exports;
}

const input = executeTypeScript(
  readFileSync(resolve(root, 'uniapp/src/utils/page-input.ts'), 'utf8'),
  {}
);
const useRequest = executeTypeScript(
  readFileSync(resolve(root, 'web/src/hooks/request.ts'), 'utf8'),
  { vue }
).default;

test('page input accepts only supported policy and safe positive identifiers', () => {
  assert.equal(input.policyKind(undefined), 'service');
  assert.equal(input.policyKind('privacy'), 'privacy');
  assert.equal(input.policyKind('service'), 'service');
  assert.equal(input.positivePageId('42'), 42);
  for (const value of [
    null,
    0,
    '0',
    '-1',
    '01',
    '1e2',
    '1.0',
    ' 1',
    '9007199254740992',
  ]) {
    assert.throws(() => input.positivePageId(value), /PAGE_ID_INVALID/);
  }
  for (const value of [null, 1, 'other'])
    assert.throws(() => input.policyKind(value), /POLICY_KIND_INVALID/);
});

test('request helper retains typed results and ignores older completion', async () => {
  const first = deferred();
  const second = deferred();
  let count = 0;
  const scope = vue.effectScope();
  const state = scope.run(() =>
    useRequest(() => (++count === 1 ? first.promise : second.promise))
  );
  const latest = state.reload();
  second.resolve({ data: { id: 'new' } });
  await latest;
  first.resolve({ data: { id: 'old' } });
  await tick();
  assert.equal(state.response.value.id, 'new');
  assert.equal(state.loading.value, false);
  scope.stop();
});

test('request errors remain distinct from successful empty data', async () => {
  const failure = deferred();
  const scope = vue.effectScope();
  const state = scope.run(() => useRequest(() => failure.promise));
  failure.reject(new Error('transport rejected'));
  await tick();
  assert.equal(state.response.value, undefined);
  assert.ok(state.error.value);
  assert.equal(state.loading.value, false);
  scope.stop();
});

test('disposing request scope prevents stale result writes', async () => {
  const pending = deferred();
  const scope = vue.effectScope();
  const state = scope.run(() => useRequest(() => pending.promise));
  scope.stop();
  pending.resolve({ data: 'late' });
  await tick();
  assert.equal(state.response.value, undefined);
  assert.equal(state.loading.value, false);
});

function newsPage(transport) {
  const file = resolve(root, 'uniapp/src/pages/news_detail/news_detail.vue');
  const descriptor = sfc.parse(readFileSync(file, 'utf8'), { filename: file });
  assert.deepEqual(descriptor.errors, []);
  let load;
  let unload;
  const toasts = [];
  const navigation = [];
  const user = { isLoggedIn: true };
  const module = executeTypeScript(
    descriptor.descriptor.scriptSetup.content +
      '\nexport { article, loading, errorMessage, collecting, toggleCollect };',
    {
      vue,
      '@dcloudio/uni-app': {
        onLoad(callback) {
          load = callback;
        },
        onUnload(callback) {
          unload = callback;
        },
      },
      '@/utils/page-input': input,
      '@/api/news': transport,
      '@/store/user': { useUserStore: () => user },
    },
    {
      uni: {
        showToast: (message) => toasts.push(message),
        navigateTo: (route) => navigation.push(route),
      },
    }
  );
  assert.equal(typeof load, 'function');
  assert.equal(typeof unload, 'function');
  return { ...module, load, unload, toasts, navigation, user };
}

test('uni-app page rejects invalid route parameters before transport', async () => {
  let calls = 0;
  const page = newsPage({
    getArticleDetail() {
      calls += 1;
    },
    addCollect() {},
    cancelCollect() {},
  });
  page.load({ id: 'invalid' });
  await tick();
  assert.equal(calls, 0);
  assert.equal(page.loading.value, false);
  assert.equal(page.errorMessage.value, '资讯编号无效');
});

test('uni-app page ignores network completion after unload', async () => {
  const pending = deferred();
  const page = newsPage({
    getArticleDetail: () => pending.promise,
    addCollect() {},
    cancelCollect() {},
  });
  page.load({ id: '1' });
  page.unload();
  pending.resolve({ id: 1, collect: false });
  await tick();
  assert.equal(page.article.value, null);
});

test('uni-app duplicate collection submits only once and reflects success', async () => {
  let additions = 0;
  const pending = deferred();
  const page = newsPage({
    getArticleDetail: async () => ({ id: 1, collect: false }),
    addCollect: () => {
      additions += 1;
      return pending.promise;
    },
    cancelCollect() {},
  });
  page.load({ id: '1' });
  await tick();
  const first = page.toggleCollect();
  await page.toggleCollect();
  assert.equal(additions, 1);
  assert.equal(page.collecting.value, true);
  pending.resolve();
  await first;
  assert.equal(page.article.value.collect, true);
  assert.equal(page.collecting.value, false);
});

test('uni-app request rejection does not leave an eternal loading state', async () => {
  const page = newsPage({
    getArticleDetail: async () => {
      throw new Error('500');
    },
    addCollect() {},
    cancelCollect() {},
  });
  page.load({ id: '1' });
  await tick();
  assert.equal(page.loading.value, false);
  assert.ok(page.errorMessage.value);
  assert.equal(page.article.value, null);
});
