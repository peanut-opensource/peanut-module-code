import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import { transformWithEsbuild } from 'vite';

const filename = fileURLToPath(new URL('../src/core/permission-menu-tree.ts', import.meta.url));
const module = { exports: {} };
const { code } = await transformWithEsbuild(readFileSync(filename, 'utf8'), filename, { format: 'cjs' });
runInNewContext(code, { module, exports: module.exports });
const tree = module.exports.permissionMenuTree;
const record = (key, parent_key, type, name) => ({
  menu_key: key, parent_key, type, name, page_path: '',
  action_key: type === 'A' ? key : '', permission_key: 'core.fixture',
  default_permission_key: '', revision: 'f'.repeat(64),
});

test('formal directory→menu→button structure preserves identities and does not mutate source', () => {
  const source = [
    record('t.btn', 't.page', 'A', '新增'),
    record('t.dir', null, 'M', '内容管理'),
    record('t.page', 't.dir', 'C', '文章管理'),
  ];
  const result = JSON.parse(JSON.stringify(tree(source)));
  assert.deepEqual(result.map(({ menu_key }) => menu_key), ['t.dir']);
  assert.deepEqual(result[0].children.map(({ menu_key }) => menu_key), ['t.page']);
  assert.deepEqual(result[0].children[0].children.map(({ menu_key }) => menu_key), ['t.btn']);
  assert.deepEqual(source.map(({ menu_key }) => menu_key), ['t.btn', 't.dir', 't.page']);
  assert.ok(source.every((row) => !('children' in row)));
});

test('Chinese search retains authoritative ancestor chains and excludes unrelated branches', () => {
  const source = [
    record('t.dir', null, 'M', '内容管理'),
    record('t.page', 't.dir', 'C', '文章管理'),
    record('t.btn', 't.page', 'A', '新增文章'),
    record('u.dir', null, 'M', '系统设置'),
  ];
  const result = JSON.parse(JSON.stringify(tree(source, '新增文章')));
  assert.equal(result.length, 1);
  assert.equal(result[0].menu_key, 't.dir');
  assert.equal(result[0].children[0].children[0].menu_key, 't.btn');
  assert.deepEqual(JSON.parse(JSON.stringify(tree(source, 'no match'))), []);
});

test('duplicate menu keys, cycles, and action-child corruption fail visibly', () => {
  assert.throws(() => tree([record('t', null, 'M', '目录'), record('t', null, 'M', '目录2')]), /重复/);
  assert.throws(() => tree([record('a', 'b', 'C', 'A'), record('b', 'a', 'C', 'B')]), /循环/);
  assert.throws(() => tree([record('a', null, 'A', '按钮'), record('b', 'a', 'C', '子页')]), /按钮不能有子菜单/);
});

test('the same official permission menu parser accepts directories but rejects invented types', async () => {
  const filename = fileURLToPath(new URL('../src/api/permission-bundles.ts', import.meta.url));
  const module = { exports: {} };
  const { code } = await transformWithEsbuild(readFileSync(filename, 'utf8'), filename, { format: 'cjs' });
  runInNewContext(code, { module, exports: module.exports });
  const directory = record('t.dir', null, 'M', '正式目录');
  const result = JSON.parse(JSON.stringify(module.exports.readPermissionMenuBinding(directory)));
  assert.equal(result.type, 'M');
  assert.equal(result.menu_key, 't.dir');
  assert.throws(() => module.exports.readPermissionMenuBinding({
    ...directory, type: 'X',
  }), /目录\/菜单\/按钮类型异常/);
});
