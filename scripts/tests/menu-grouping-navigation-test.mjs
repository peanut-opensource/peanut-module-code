import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { createRequire } from 'node:module';
import vm from 'node:vm';
import test from 'node:test';

const root = path.resolve(import.meta.dirname, '../..');
const require = createRequire(path.join(root, 'web/package.json'));
const ts = require('typescript');
const source = fs.readFileSync(
  path.join(root, 'web/src/store/modules/app/server-menu.ts'),
  'utf8'
);
const code = ts.transpileModule(source, {
  compilerOptions: { module: ts.ModuleKind.CommonJS },
}).outputText;
function mapper(routes) {
  const exports = {};
  vm.runInNewContext(code, {
    exports,
    require: (name) => {
      assert.equal(name, '@/router/app-menus');
      return { default: routes };
    },
  });
  return exports.default;
}

test('categories preserve static leaf routes, names and cache metadata', () => {
  const component = () => {};
  const map = mapper([
    {
      path: '/system',
      children: [
        { path: 'file', name: 'File', component, meta: { keepAlive: true } },
      ],
    },
  ]);
  const result = map([
    {
      id: 100,
      menu_key: 'core.content',
      type: 'M',
      name: '内容与资源',
      paths: '/article',
      children: [
        {
          id: 100,
          menu_key: 'official.file.library',
          type: 'C',
          name: '文件',
          paths: '/system/file',
          component: 'untrusted/component',
          required_permission: 'official.file.list',
        },
      ],
    },
  ]);
  assert.equal(result.length, 1);
  assert.equal(result[0].component, undefined);
  assert.equal(result[0].name, 'menu-group-core.content');
  assert.equal(result[0].children[0].path, '/system/file');
  assert.equal(result[0].children[0].name, 'File');
  assert.equal(result[0].children[0].component, component);
  assert.equal(result[0].children[0].meta.keepAlive, true);
  assert.equal(
    result[0].children[0].meta.requiredPermissions,
    'official.file.list'
  );
});

test('unknown pages and empty categories cannot load arbitrary components', () => {
  const map = mapper([]);
  assert.equal(
    map([
      {
        id: 1,
        menu_key: 'custom.category',
        type: 'M',
        paths: '/category',
        children: [
          { id: 2, type: 'C', paths: '/unknown', component: 'unsafe' },
        ],
      },
    ]).length,
    0
  );
});

test('custom category and page hiding is preserved independently of authorization', () => {
  const map = mapper([
    {
      path: '/known',
      name: 'Known',
      meta: { requiredPermissions: 'page.read' },
    },
  ]);
  const result = map([
    {
      id: 1,
      menu_key: 'custom.category',
      type: 'M',
      name: '我的目录',
      is_show: 0,
      children: [
        { id: 2, type: 'C', name: '我的页面', paths: '/known', is_show: 0 },
      ],
    },
  ]);
  assert.equal(result[0].meta.hideInMenu, true);
  assert.equal(result[0].children[0].meta.hideInMenu, true);
  assert.equal(result[0].children[0].meta.requiredPermissions, 'page.read');
});

test('platform regrouping retains every existing view and permission condition', () => {
  const app = fs.readFileSync(path.join(root, 'platform/src/App.vue'), 'utf8');
  const indices = [...app.matchAll(/<el-menu-item[^>]*index="([^"]+)"/g)].map(
    (match) => match[1]
  );
  assert.deepEqual(
    indices.sort(),
    [
      'overview',
      'tenants',
      'owners',
      'endpoints',
      'modules',
      'operators',
      'roles',
      'ops',
      'developer',
      'storage',
      'audit',
    ].sort()
  );
  assert.equal([...app.matchAll(/<el-sub-menu\b/g)].length, 3);
  assert.match(app, /platform\.tenant\.read/);
  assert.match(app, /platform\.ops\.read/);
});
