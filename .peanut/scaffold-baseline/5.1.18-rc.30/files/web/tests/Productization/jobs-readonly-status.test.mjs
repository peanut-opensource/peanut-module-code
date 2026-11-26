import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { test } from 'node:test';
import { fileURLToPath } from 'node:url';

const native = createRequire(new URL('../../package.json', import.meta.url));
const { createSSRApp, h, compile } = native('vue');
const { renderToString } = native('vue/server-renderer');
const { parse } = native('vue/compiler-sfc');
const web = fileURLToPath(new URL('../../', import.meta.url));
const source = readFileSync(web + 'src/views/system/jobs/index.vue', 'utf8');
const { descriptor, errors } = parse(source, { filename: 'src/views/system/jobs/index.vue' });
assert.deepEqual(errors, []);
assert.ok(descriptor.template);
const template = descriptor.template.ast;

function allElements(node, predicate, found = []) {
  if (node.type === 1 && predicate(node)) found.push(node);
  for (const child of node.children ?? []) allElements(child, predicate, found);
  return found;
}
function expression(element, directive, arg) {
  const property = element.props.find((item) =>
    item.type === 7 && item.name === directive && (arg === undefined || item.arg?.content === arg)
  );
  return property?.exp?.content;
}
const statusColumn = allElements(template, (node) =>
  node.tag === 'el-table-column' &&
  expression(node, 'bind', 'label')?.includes('systemJobs.columns.status')
)[0];
assert.ok(statusColumn, 'The real Admin jobs page must have the status column');

test('actual Jobs status cell shows a readable state when edit permission is absent', async () => {
  // Compile the existing SFC's actual status column, rather than reproducing a
  // separate status rendering implementation or mounting a synthetic page.
  // The production Vue SFC compiler strips TypeScript from event expressions;
  // Vue's standalone SSR runtime compiler expects the same expression in JS.
  const tsEvent = '(v: string | number | boolean) => handleStatus(row, v as boolean)';
  assert.ok(statusColumn.loc.source.includes(tsEvent));
  const compiledSource = statusColumn.loc.source.replace(tsEvent, '(v) => handleStatus(row, v)');
  const render = compile('<div>' + compiledSource + '</div>');
  async function renderFor(editAllowed, status) {
    const checks = [];
    const app = createSSRApp({
      setup: () => ({
        canAccess: (key) => { checks.push(key); return editAllowed; },
        handleStatus: () => { throw Error('SSR may never submit a mutation'); },
      }),
      render,
    });
    app.config.globalProperties.$t = (key) => ({
      'systemJobs.columns.status': '状态',
      'systemJobs.status.normal': '启用',
      'systemJobs.status.stopped': '停用',
    }[key] ?? key);
    app.component('el-table-column', {
      setup(_, { slots }) {
        return () => h('div', { 'data-test': 'status-column' }, slots.default?.({
          row: { id: 9, status, status_desc: status === 1 ? '启用' : '停用' },
        }));
      },
    });
    app.component('el-switch', {
      props: ['modelValue'],
      setup(props) {
        return () => h('button', { 'data-test': 'status-mutation' }, props.modelValue ? 'enabled' : 'disabled');
      },
    });
    app.component('el-tag', {
      setup(_, { slots }) {
        return () => h('span', { 'data-test': 'status-readonly' }, slots.default?.());
      },
    });
    return { html: await renderToString(app), checks };
  }
  const enabled = await renderFor(false, 1);
  assert.match(enabled.html, /status-readonly/);
  assert.match(enabled.html, /启用/);
  assert.doesNotMatch(enabled.html, /status-mutation/);
  assert.deepEqual(enabled.checks, ['core.job.update']);
  const stopped = await renderFor(false, 0);
  assert.match(stopped.html, /status-readonly/);
  assert.match(stopped.html, /停用/);
  assert.doesNotMatch(stopped.html, /status-mutation/);
  const authorized = await renderFor(true, 1);
  assert.match(authorized.html, /status-mutation/);
  assert.doesNotMatch(authorized.html, /status-readonly/);
  assert.deepEqual(authorized.checks, ['core.job.update']);
});

test('Jobs consumes existing CRUD loading, retry, search/reset, columns and scoped permissions', () => {
  const page = allElements(template, (node) => node.tag === 'CrudPage');
  assert.equal(page.length, 1, 'Reuse one native CrudPage, not a second table');
  const props = [
    ['loading', 'loading'],
    ['data', 'renderData'],
    ['error', 'listError'],
    ['pagination', 'pagination'],
    ['fields', 'columns'],
    ['column-storage-key', 'columnStorageKey'],
  ];
  for (const [name, value] of props) {
    assert.equal(expression(page[0], 'bind', name), value, name);
  }
  assert.equal(expression(page[0], 'on', 'retry'), 'fetchList(pagination.page)');
  assert.equal(expression(page[0], 'on', 'page-change'), 'fetchList');
  const buttons = allElements(template, (node) => node.tag === 'el-button');
  for (const name of ['search', 'reset']) {
    assert.ok(buttons.some((node) => expression(node, 'on', 'click') === name), name);
  }
  assert.match(source, /useCrudTable<JobsRecord/);
  assert.match(source, /useCrudModal<JobsForm/);
  assert.match(source, /:column-storage-key="columnStorageKey"/);
  assert.match(source, /hasPermission\(permission, route\.path\)/);
  assert.match(source, /:saved-views|columnStorageKey/);
  assert.match(source, /peanut:jobs:columns:\$\{window\.location\.host\}:\$\{userStore\.tenantName\}:\$\{userStore\.id\}/);
  const crudSource = readFileSync(web + 'src/components/crud/CrudPage.vue', 'utf8');
  const crud = parse(crudSource, { filename: 'src/components/crud/CrudPage.vue' });
  assert.deepEqual(crud.errors, []);
  const alerts = allElements(crud.descriptor.template.ast, (node) =>
    node.tag === 'div' && expression(node, 'if')?.startsWith('error && !loading')
  );
  assert.deepEqual(alerts.map((node) => expression(node, 'if')), [
    'error && !loading && data.length === 0',
    'error && !loading && data.length > 0',
  ]);
  const retryButtons = allElements(crud.descriptor.template.ast, (node) =>
    node.tag === 'el-button' && expression(node, 'on', 'click') === "$emit('retry')"
  );
  assert.equal(retryButtons.length, 2);
  const table = allElements(crud.descriptor.template.ast, (node) => node.tag === 'el-table');
  assert.equal(table.length, 1);
  assert.equal(expression(table[0], 'loading'), 'loading');
  assert.equal(expression(table[0], 'bind', 'data'), 'tableData');
  const settings = allElements(crud.descriptor.template.ast, (node) => node.tag === 'TableColumnSetting');
  assert.equal(settings.length, 1);
  assert.equal(expression(settings[0], 'bind', 'storage-key'), 'effectiveColumnKey');
  assert.equal(expression(settings[0], 'bind', 'schema'), 'fields');
  const route = readFileSync(web + 'src/router/routes/modules/system.ts', 'utf8');
  assert.match(route, /name: 'SystemJobs'/);
  assert.match(route, /requiredPermissions: \['core\.job\.read'\]/);
  const apiRoutes = readFileSync(web + '../server/route/admin.php', 'utf8');
  assert.match(apiRoutes, /Route::get\('jobs\/lists',[^\n]*'peanut_permission' => 'core\.job\.read'/);
  assert.match(apiRoutes, /Route::post\('jobs\/status',[^\n]*'peanut_permission' => 'core\.job\.update'/);
  assert.match(source, /v-permission="\['core\.job\.create'\]"/);
  assert.match(source, /v-permission="\['core\.job\.archive'\]"/);
});

test('CrudPage used by Jobs distinguishes load failure without data, stale data failure, and loading', async () => {
  const crudSource = readFileSync(web + 'src/components/crud/CrudPage.vue', 'utf8');
  const crud = parse(crudSource, { filename: 'src/components/crud/CrudPage.vue' });
  assert.deepEqual(crud.errors, []);
  const alerts = allElements(crud.descriptor.template.ast, (node) =>
    node.tag === 'div' && expression(node, 'if')?.startsWith('error && !loading')
  );
  assert.equal(alerts.length, 2);
  const render = compile('<section>' + alerts.map((node) => node.loc.source).join('') + '</section>');
  async function renderFailure({ error, loading, data }) {
    const app = createSSRApp({
      setup: () => ({ error, loading, data }),
      render,
    });
    app.component('el-alert', {
      props: ['title'],
      setup(props) {
        return () => h('p', { 'data-test': 'crud-alert' }, props.title);
      },
    });
    app.component('el-button', {
      setup(_, { slots }) {
        return () => h('button', { 'data-test': 'retry' }, slots.default?.());
      },
    });
    return await renderToString(app);
  }
  const emptyFailure = await renderFailure({ error: 'request failed', loading: false, data: [] });
  assert.match(emptyFailure, /列表加载失败，请重试/);
  assert.match(emptyFailure, /data-test="retry"/);
  assert.doesNotMatch(emptyFailure, /当前仍显示上次结果/);
  const staleFailure = await renderFailure({ error: 'request failed', loading: false, data: [{ id: 1 }] });
  assert.match(staleFailure, /当前仍显示上次结果/);
  assert.match(staleFailure, /data-test="retry"/);
  assert.doesNotMatch(staleFailure, /列表加载失败，请重试/);
  const pending = await renderFailure({ error: 'request failed', loading: true, data: [] });
  assert.doesNotMatch(pending, /data-test="crud-alert"/);
  assert.doesNotMatch(pending, /data-test="retry"/);
});
