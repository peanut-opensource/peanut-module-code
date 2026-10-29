import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { readFileSync, readdirSync } from 'node:fs';
import { createRequire } from 'node:module';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';

const root = path.resolve(
  path.dirname(fileURLToPath(import.meta.url)),
  '../..'
);
const require = createRequire(import.meta.url);
const native = createRequire(path.join(root, 'uniapp/package.json'));
const ts = require(path.join(root, 'tools/quality/node_modules/typescript'));
const sfc = native('vue/compiler-sfc');
const files = new Set(
  execFileSync('git', ['ls-files', '-z', 'uniapp/src'], {
    cwd: root,
    encoding: 'utf8',
  })
    .split('\0')
    .filter(Boolean)
);
const read = (file) => readFileSync(path.join(root, file), 'utf8');
const pagesJson = JSON.parse(read('uniapp/src/pages.json'));
const routes = [
  ...pagesJson.pages.map((page) => page.path),
  ...pagesJson.subPackages.flatMap((pack) =>
    pack.pages.map((page) => `${pack.root}/${page.path}`)
  ),
];
const expectedRoutes = [
  'pages/index/index',
  'pages/news/news',
  'pages/user/user',
  'pages/login/login',
  'pages/register/register',
  'pages/news_detail/news_detail',
  'pages/search/search',
  'pages/user_data/user_data',
  'pages/user_set/user_set',
  'pages/collection/collection',
  'pages/change_password/change_password',
  'pages/bind_mobile/bind_mobile',
  'pages/customer_service/customer_service',
  'pages/as_us/as_us',
  'pages/agreement/agreement',
  'pages/forget_pwd/forget_pwd',
  'pages/oauth/callback',
  'pages/oauth/complete',
  'packages/pages/user_wallet/user_wallet',
  'packages/pages/recharge/recharge',
  'packages/pages/recharge_record/recharge_record',
];

function exactFile(file) {
  assert.ok(files.has(file), `Untracked or case-mismatched source: ${file}`);
  let directory = root;
  for (const part of file.split('/')) {
    assert.ok(
      readdirSync(directory).includes(part),
      `Incorrect path casing: ${file}`
    );
    directory = path.join(directory, part);
  }
  return file;
}

function localImport(from, specifier) {
  if (!specifier.startsWith('.') && !specifier.startsWith('@/')) return null;
  const target = path.posix.normalize(
    specifier.startsWith('@/')
      ? `uniapp/src/${specifier.slice(2)}`
      : path.posix.join(path.posix.dirname(from), specifier)
  );
  assert.ok(
    target.startsWith('uniapp/src/'),
    `Import outside native source: ${target}`
  );
  const candidates = [
    target,
    ...['.ts', '.tsx', '.vue', '.d.ts', '.js', '.json'].map(
      (ext) => target + ext
    ),
    `${target}/index.ts`,
    `${target}/index.js`,
  ];
  const found = candidates.find((file) => files.has(file));
  assert.ok(found, `Unresolved exact import: ${from} -> ${specifier}`);
  return exactFile(found);
}

function script(file) {
  if (!file.endsWith('.vue')) return read(file);
  const parsed = sfc.parse(read(file), { filename: file });
  assert.deepEqual(parsed.errors, []);
  assert.equal(
    parsed.descriptor.script,
    null,
    `Use the approved script setup entry: ${file}`
  );
  assert.ok(parsed.descriptor.scriptSetup, `Missing script setup: ${file}`);
  assert.equal(parsed.descriptor.scriptSetup.lang, 'ts');
  return parsed.descriptor.scriptSetup.content;
}

function moduleSpecifiers(source) {
  const tree = ts.createSourceFile(
    'source.ts',
    source,
    ts.ScriptTarget.Latest,
    true
  );
  const result = [];
  function visit(node) {
    if (
      (ts.isImportDeclaration(node) || ts.isExportDeclaration(node)) &&
      node.moduleSpecifier
    ) {
      assert.ok(ts.isStringLiteral(node.moduleSpecifier));
      result.push(node.moduleSpecifier.text);
    }
    if (
      ts.isCallExpression(node) &&
      node.expression.kind === ts.SyntaxKind.ImportKeyword
    ) {
      assert.ok(
        node.arguments.length === 1 && ts.isStringLiteral(node.arguments[0]),
        'Dynamic imports need an explicit reviewed registration'
      );
      result.push(node.arguments[0].text);
    }
    ts.forEachChild(node, visit);
  }
  visit(tree);
  return result;
}

test('native pages and subpackage URLs remain unchanged, unique and case-exact', () => {
  assert.deepEqual(routes, expectedRoutes);
  assert.equal(new Set(routes).size, routes.length);
  for (const route of routes) exactFile(`uniapp/src/${route}.vue`);
  const registered = new Set(routes.map((route) => `uniapp/src/${route}.vue`));
  const sourcePages = [...files].filter((file) =>
    /^uniapp\/src\/(?:packages\/)?pages\/.*\.vue$/.test(file)
  );
  assert.equal(sourcePages.length, registered.size);
  for (const file of sourcePages)
    assert.ok(registered.has(file), `Unregistered native page: ${file}`);
});

test('framework entries, ordinary files and display components retain distinct naming roles', () => {
  for (const file of [
    'uniapp/src/App.vue',
    'uniapp/src/main.ts',
    'uniapp/src/pages.json',
    'uniapp/src/manifest.json',
  ])
    exactFile(file);
  for (const file of files) {
    if (file.endsWith('.ts')) {
      const name = path.posix.basename(file).replace(/(?:\.d)?\.ts$/, '');
      assert.match(
        name,
        /^(?:[a-z][a-z0-9]*(?:-[a-z0-9]+)*|use[A-Z][A-Za-z0-9]*)$/,
        file
      );
    }
    if (file.startsWith('uniapp/src/components/') && file.endsWith('.vue')) {
      const name = path.posix.basename(file, '.vue');
      assert.match(name, /^[A-Z][A-Za-z0-9]*[A-Z][A-Za-z0-9]*$/, file);
    }
  }
  // This application uses explicit imports; no speculative easycom remapping is introduced.
  assert.equal(pagesJson.easycom?.custom, undefined);
});

test('all native TypeScript and Vue script imports resolve through exact tracked source paths', (t) => {
  const sourcesOnDisk = [];
  function collect(directory) {
    for (const entry of readdirSync(path.join(root, directory), {
      withFileTypes: true,
    })) {
      const file = `${directory}/${entry.name}`;
      assert.equal(
        entry.isSymbolicLink(),
        false,
        `Source must not escape the checkout: ${file}`
      );
      if (entry.isDirectory()) collect(file);
      else if (/\.(?:ts|tsx|vue)$/.test(file)) sourcesOnDisk.push(file);
    }
  }
  collect('uniapp/src');
  const sources = [...files].filter((file) => /\.(?:ts|tsx|vue)$/.test(file));
  assert.deepEqual(
    sources.sort(),
    sourcesOnDisk.sort(),
    'Every source on disk must be tracked and inspected'
  );
  for (const required of [
    'uniapp/src/App.vue',
    'uniapp/src/main.ts',
    'uniapp/src/store/user.ts',
    'uniapp/src/utils/request.ts',
  ]) {
    assert.ok(
      sources.includes(required),
      `Missing native consumer: ${required}`
    );
  }
  let imports = 0;
  for (const file of sources) {
    const source = script(file);
    for (const specifier of moduleSpecifiers(source))
      if (localImport(file, specifier)) imports++;
  }
  assert.ok(imports > 0, 'An empty import scope is not a pass');
  t.diagnostic(
    `${sources.length} native source files; ${imports} exact local imports; ${routes.length} registered pages`
  );
});

test('PascalCase tabbar consumers use the real component rather than easycom assumptions', () => {
  const component = exactFile('uniapp/src/components/DecorationTabbar.vue');
  for (const page of ['index', 'news', 'user']) {
    const file = `uniapp/src/pages/${page}/${page}.vue`;
    const parsed = sfc.parse(read(file), { filename: file });
    assert.match(parsed.descriptor.template.content, /<DecorationTabbar\s*\/>/);
    assert.ok(
      moduleSpecifiers(script(file)).includes(
        '@/components/DecorationTabbar.vue'
      )
    );
    assert.equal(
      localImport(file, '@/components/DecorationTabbar.vue'),
      component
    );
  }
});

for (const specifier of [
  '@/components/decorationTabbar.vue',
  '@/api/News',
  '@/../packages/pages/404/404.vue',
  './missing-module',
]) {
  test(`the path guard rejects ${specifier}`, () => {
    assert.throws(
      () => localImport('uniapp/src/pages/news/news.vue', specifier),
      /case|Unresolved|outside/i
    );
  });
}

test('dynamic imports are inspected and unregistered expression imports fail explicitly', () => {
  assert.deepEqual(
    moduleSpecifiers("const page = () => import('@/pages/news/news.vue');"),
    ['@/pages/news/news.vue']
  );
  assert.throws(
    () => moduleSpecifiers('const page = (path: string) => import(path);'),
    /explicit reviewed registration/
  );
});
