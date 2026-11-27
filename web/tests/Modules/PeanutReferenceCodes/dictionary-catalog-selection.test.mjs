import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { runInNewContext } from 'node:vm';
import { fileURLToPath } from 'node:url';
import test from 'node:test';

const require = createRequire(new URL('../../../package.json', import.meta.url));
const vue = require('vue');
const ts = require('typescript');
const compiler = require('vue/compiler-sfc');
const filename = fileURLToPath(
  new URL('../../../src/modules/peanut-reference-codes/DictionaryCatalogPage.vue', import.meta.url)
);
const { descriptor, errors } = compiler.parse(readFileSync(filename, 'utf8'), { filename });
assert.deepEqual(errors, []);
const actual = descriptor.scriptSetup?.content ?? '';

function deferred() {
  let resolve;
  let reject;
  const promise = new Promise((yes, no) => { resolve = yes; reject = no; });
  return { promise, resolve, reject };
}
function fixture() {
  const getRequests = [];
  const postRequests = [];
  let mounted = () => {};
  let unmounted = () => {};
  const compiled = ts.transpileModule(
    actual + '\nexports.ui = { types, selected, detail, loading, detailLoading, loadError, loadTypes, loadDetail, retryLoad, setMode, setOverride };',
    { fileName: filename + '.ts', compilerOptions: {
      target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.CommonJS, esModuleInterop: true,
    }, reportDiagnostics: true }
  );
  assert.equal(compiled.diagnostics?.length ?? 0, 0);
  const exports = {};
  const scope = vue.effectScope(true);
  scope.run(() => runInNewContext(compiled.outputText, {
    exports,
    require(name) {
      if (name === 'vue') return {
        ...vue,
        onMounted(fn) { mounted = fn; },
        onBeforeUnmount(fn) { unmounted = fn; },
      };
      if (name === 'axios') return {
        get(path) {
          const operation = deferred();
          getRequests.push({ path, ...operation });
          return operation.promise;
        },
        post(path, data) {
          const operation = deferred();
          postRequests.push({ path, data, ...operation });
          return operation.promise;
        },
      };
      if (name === 'vue-router') return { useRoute: () => ({ path: '/system/dictionary-catalog' }) };
      if (name === 'vue-i18n') return { useI18n: () => ({ t: (key) => key }) };
      if (name === 'element-plus') return { ElMessage: { success() {} } };
      if (name === '@/hooks/permission') return { hasPermission: () => true };
      throw new Error('Unexpected ReferenceCodes consumer: ' + name);
    },
  }, { filename }));
  return { ui: exports.ui, getRequests, postRequests, mount: () => mounted(),
    unmount() { unmounted(); scope.stop(); } };
}
const kind = (code) => ({ code, name: code, is_disable: 0, tenant_mode: 'extend', mode: 'extend' });
const detail = (code) => ({ type: kind(code), items: [{ value: code + '-1', name: code, override: null, is_disable: 0 }] });
const tick = () => new Promise((resolve) => setImmediate(resolve));

test('switching type A to B discards late A detail and keeps B as the only editable detail', async () => {
  const f = fixture();
  const loading = f.mount();
  assert.equal(f.getRequests[0].path, '/adminapi/api/v1/dictionary-catalog');
  f.getRequests[0].resolve({ data: [kind('a'), kind('b')] });
  await tick();
  assert.equal(f.getRequests[1].path.endsWith('/a'), true);
  f.ui.selected.value = 'b';
  const latest = f.ui.loadDetail();
  assert.equal(f.ui.detail.value, null);
  assert.equal(f.getRequests[2].path.endsWith('/b'), true);
  f.getRequests[2].resolve({ data: detail('b') });
  await latest;
  f.getRequests[1].resolve({ data: detail('a') });
  await loading;
  assert.equal(f.ui.selected.value, 'b');
  assert.equal(f.ui.detail.value.type.code, 'b');
  assert.equal(f.ui.loadError.value, null);
  f.unmount();
});

test('type-list failure displays retry rather than a false empty catalog', async () => {
  const f = fixture();
  const loading = f.mount();
  f.getRequests[0].reject(new Error('503'));
  await loading;
  assert.equal(f.ui.loadError.value, 'types');
  assert.equal(f.ui.loading.value, false);
  f.ui.retryLoad();
  assert.equal(f.getRequests.length, 2);
  f.getRequests[1].resolve({ data: [kind('a')] });
  await tick();
  assert.equal(f.getRequests.length, 3);
  f.getRequests[2].resolve({ data: detail('a') });
  await tick();
  assert.equal(f.ui.loadError.value, null);
  assert.equal(f.ui.detail.value.type.code, 'a');
  f.unmount();
});

test('old failed detail cannot overwrite a newer successful type and offers genuine retry on current failure', async () => {
  const f = fixture();
  const opening = f.mount();
  f.getRequests[0].resolve({ data: [kind('a'), kind('b')] });
  await tick();
  f.ui.selected.value = 'b';
  const latest = f.ui.loadDetail();
  f.getRequests[2].resolve({ data: detail('b') });
  await latest;
  f.getRequests[1].reject(new Error('late 403 for old type'));
  await opening;
  assert.equal(f.ui.loadError.value, null);
  assert.equal(f.ui.detail.value.type.code, 'b');
  const pending = f.ui.loadDetail();
  f.getRequests[3].reject(new Error('503'));
  await pending;
  assert.equal(f.ui.loadError.value, 'detail');
  assert.equal(f.ui.detail.value, null);
  f.ui.retryLoad();
  assert.equal(f.getRequests[4].path.endsWith('/b'), true);
  f.getRequests[4].resolve({ data: detail('b') });
  await tick();
  assert.equal(f.ui.detail.value.type.code, 'b');
  f.unmount();
});

test('unmount invalidates pending catalog and detail responses', async () => {
  const f = fixture();
  const initial = f.mount();
  f.unmount();
  f.getRequests[0].resolve({ data: [kind('a')] });
  await initial;
  assert.equal(f.ui.types.value.length, 0);
  assert.equal(f.getRequests.length, 1);
});

test('catalog template has exclusive loading/error/retry/empty states', () => {
  const template = descriptor.template?.content ?? '';
  assert.equal(compiler.compileTemplate({ source: template, filename, id: 'catalog-async' }).errors.length, 0);
  assert.match(template, /v-if="loadError"/);
  assert.match(template, /@click="retryLoad"/);
  assert.match(template, /v-if="!types.length && !loadError && !loading"/);
  assert.match(template, /v-if="detail && !detailLoading && !loadError"/);
});
