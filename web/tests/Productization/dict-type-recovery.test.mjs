import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { test } from 'node:test';
import { fileURLToPath } from 'node:url';

const require = createRequire(new URL('../../package.json', import.meta.url));
const ts = require('typescript');
const { parse } = require('vue/compiler-sfc');
const { compile, createSSRApp, h } = require('vue');
const { renderToString } = require('vue/server-renderer');
const root = fileURLToPath(new URL('../../', import.meta.url));
const source = readFileSync(root + 'src/views/system/dict/index.vue', 'utf8');
const sfc = parse(source, { filename: 'src/views/system/dict/index.vue' });
assert.deepEqual(sfc.errors, []);

function deferred() {
  let resolve;
  let reject;
  const promise = new Promise((yes, no) => { resolve = yes; reject = no; });
  return { promise, resolve, reject };
}

function createHarness() {
  const requests = [];
  const formModel = { value: { name: 'first', type: 'a', is_disable: '' } };
  const renderData = { value: [] };
  const listError = { value: false };
  const failedQuery = { value: null };
  const pagination = { current: 1, pageSize: 15, total: 0 };
  let loading = false;
  let unmount;
  const slice = source.slice(source.indexOf('  const fetchData = async '), source.indexOf('  // ---- 数据抽屉 ----'));
  assert.ok(slice.startsWith('  const fetchData = async '));
  const js = ts.transpileModule('let requestSequence = 0;\n' + slice.replace(/^  fetchData\(\);\s*$/m, ''), {
    compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.None },
  }).outputText;
  const methods = new Function('getDictTypeList', 'formModel', 'renderData', 'listError',
    'failedQuery', 'pagination', 'setLoading', 'onBeforeUnmount', 'generateFormModel',
    js + '\nreturn { fetchData, retryList, search, onPageChange, reset };')(
    (params) => { const call = deferred(); requests.push({ params, ...call }); return call.promise; },
    formModel, renderData, listError, failedQuery, pagination,
    value => { loading = value; }, fn => { unmount = fn; },
    () => ({ name: '', type: '', is_disable: '' }),
  );
  return { ...methods, requests, formModel, renderData, listError, failedQuery, pagination,
    get loading() { return loading; }, unmount: () => unmount() };
}

test('dictionary type list has distinct failure and retained-result states with retry', async () => {
  const tpl = sfc.descriptor.template.ast;
  function find(node, match, found = []) {
    if (node.type === 1 && match(node)) found.push(node);
    for (const child of node.children ?? []) find(child, match, found);
    return found;
  }
  const alert = find(tpl, node => node.tag === 'div'
    && node.props.some(prop => prop.type === 6 && prop.name === 'role' && prop.value?.content === 'alert'))[0];
  assert.ok(alert);
  assert.match(alert.loc.source, /listError && !loading/);
  assert.match(alert.loc.source, /@click="retryList"/);
  const render = compile('<div>' + alert.loc.source + '</div>');
  async function view(failed, loading, list) {
    const app = createSSRApp({
      setup: () => ({ listError: failed, loading, renderData: list, retryList: () => { throw Error('SSR must not fetch'); } }),
      render,
    });
    app.config.globalProperties.$t = name => ({
      'systemDict.list.loadFailed': '字典类型加载失败，请重试',
      'systemDict.list.loadFailedRetained': '字典类型加载失败，当前仍显示上次结果',
      'systemDict.list.retry': '重试',
    })[name] ?? name;
    app.component('el-alert', { props: ['title'], setup: props => () => h('p', {}, props.title) });
    app.component('el-button', { setup: (_, context) => () => h('button', {}, context.slots.default?.()) });
    return renderToString(app);
  }
  assert.match(await view(true, false, []), /字典类型加载失败，请重试/);
  assert.match(await view(true, false, [{ id: 1 }]), /当前仍显示上次结果/);
  assert.doesNotMatch(await view(false, false, []), /字典类型加载失败/);
  assert.doesNotMatch(await view(true, true, []), /字典类型加载失败/);
});

test('retry uses exact failed filter and page; failed response preserves last data', async () => {
  const x = createHarness();
  const first = x.fetchData(2);
  x.requests[0].resolve({ data: { lists: [{ id: 9 }], pageNo: 2, count: 29 } });
  await first;
  x.formModel.value.name = 'second';
  const failed = x.fetchData(3);
  x.requests[1].reject(new Error('simulated API 503'));
  await failed;
  assert.equal(x.listError.value, true);
  assert.deepEqual(x.renderData.value, [{ id: 9 }]);
  assert.equal(x.pagination.total, 29);
  x.formModel.value.name = 'edited but not searched';
  x.retryList();
  assert.equal(x.requests[2].params.name, 'second');
  assert.equal(x.requests[2].params.page_no, 3);
  assert.equal(x.requests[2].params.page_size, 15);
  x.requests[2].resolve({ data: { lists: [{ id: 10 }], pageNo: 3, count: 39 } });
  await x.requests[2].promise;
  await Promise.resolve();
  assert.equal(x.listError.value, false);
  assert.equal(x.failedQuery.value, null);
  assert.equal(x.pagination.current, 3);
  assert.deepEqual(x.renderData.value, [{ id: 10 }]);
});

test('concurrent search/reset and unmount invalidate stale responses without losing filters', async () => {
  const x = createHarness();
  const old = x.fetchData(2);
  x.formModel.value.name = 'new';
  const next = x.search();
  x.requests[1].resolve({ data: { lists: [{ id: 2 }], pageNo: 1, count: 1 } });
  await next;
  x.requests[0].reject(new Error('stale request'));
  await old;
  assert.equal(x.listError.value, false);
  assert.deepEqual(x.renderData.value, [{ id: 2 }]);
  assert.equal(x.formModel.value.name, 'new');

  x.reset();
  assert.equal(x.requests[2].params.name, '');
  assert.equal(x.requests[2].params.page_no, 1);
  x.requests[2].resolve({ data: { lists: [], pageNo: 1, count: 0 } });
  await x.requests[2].promise;
  await Promise.resolve();
  assert.equal(x.listError.value, false, 'successful empty response is not an error');
  assert.deepEqual(x.renderData.value, []);

  const pending = x.onPageChange(2);
  x.unmount();
  x.requests[3].resolve({ data: { lists: [{ id: 123 }], pageNo: 2, count: 1 } });
  await pending;
  assert.deepEqual(x.renderData.value, [], 'unmounted view was mutated by late response');
});

test('type list preserves read-only controls and both language keys', () => {
  assert.match(source, /hasPermission\('peanut.reference-codes.manage', route.path\)/);
  assert.match(source, /v-if="canManageDict\(\)"/);
  assert.match(source, /v-permission="\['peanut.reference-codes.manage'\]"/);
  for (const locale of ['zh-CN', 'en-US']) {
    const text = readFileSync(root + 'src/views/system/dict/locale/' + locale + '.ts', 'utf8');
    for (const name of ['loadFailed', 'loadFailedRetained', 'retry']) {
      assert.ok(text.includes("'systemDict.list." + name + "'"), locale + ': ' + name);
    }
  }
});
