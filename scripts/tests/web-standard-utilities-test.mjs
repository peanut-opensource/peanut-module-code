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
const webRequire = createRequire(path.join(root, 'web/package.json'));
const ts = webRequire('typescript');
const qualityTs = createRequire(import.meta.url)(
  path.join(root, 'tools/quality/node_modules/typescript')
);
const pinia = webRequire('pinia');
const paths = {
  predicates: path.join(root, 'web/src/utils/is.ts'),
  window: path.join(root, 'web/src/utils/index.ts'),
  app: path.join(root, 'web/src/store/modules/app/index.ts'),
};
const sources = Object.fromEntries(
  Object.entries(paths).map(([key, file]) => [
    key,
    fs.readFileSync(file, 'utf8'),
  ])
);

function execute(source, bindings = {}, globals = {}) {
  const compiled = ts.transpileModule(source, {
    compilerOptions: {
      target: ts.ScriptTarget.ES2020,
      module: ts.ModuleKind.CommonJS,
    },
    reportDiagnostics: true,
  });
  assert.deepEqual(compiled.diagnostics ?? [], []);
  const exports = {};
  vm.runInNewContext(compiled.outputText, {
    exports,
    ...globals,
    require(name) {
      assert.ok(Object.hasOwn(bindings, name), `Unexpected import: ${name}`);
      return bindings[name];
    },
  });
  return exports;
}

function diagnostics(compiler, text) {
  const file = path.join(
    root,
    '.local/tmp/web-standard-utilities/type-contract.ts'
  );
  const options = {
    target: compiler.ScriptTarget.ES2020,
    module: compiler.ModuleKind.ESNext,
    moduleResolution: compiler.ModuleResolutionKind.NodeJs,
    strict: true,
    noEmit: true,
    skipLibCheck: true,
    resolveJsonModule: true,
    esModuleInterop: true,
    types: [],
    baseUrl: root,
    paths: { pinia: ['web/node_modules/pinia'] },
  };
  const host = compiler.createCompilerHost(options);
  const read = host.readFile.bind(host);
  const exists = host.fileExists.bind(host);
  host.readFile = (name) => (name === file ? text : read(name));
  host.fileExists = (name) => name === file || exists(name);
  const program = compiler.createProgram([file], options, host);
  return { compiler, errors: compiler.getPreEmitDiagnostics(program) };
}

function assertTypechecks(result) {
  assert.equal(
    result.errors.length,
    0,
    result.compiler.formatDiagnosticsWithColorAndContext(result.errors, {
      getCurrentDirectory: () => root,
      getCanonicalFileName: (name) => name,
      getNewLine: () => '\n',
    })
  );
}

const predicates = execute(sources.predicates);

test('owned utility and settings sources contain no explicit any or type suppression', () => {
  for (const [key, source] of Object.entries(sources)) {
    assert.doesNotMatch(
      source,
      /@ts-(?:ignore|nocheck)|\bas\s+unknown\s+as\b/,
      key
    );
    const tree = ts.createSourceFile(
      paths[key],
      source,
      ts.ScriptTarget.Latest,
      true
    );
    const visit = (node) => {
      assert.notEqual(
        node.kind,
        ts.SyntaxKind.AnyKeyword,
        `${key}: explicit any`
      );
      ts.forEachChild(node, visit);
    };
    visit(tree);
  }
});

test('primitive guards reject boxed values and forged tags', () => {
  assert.equal(predicates.isString(''), true);
  assert.equal(predicates.isString('page'), true);
  assert.equal(predicates.isString(new String('page')), false);
  assert.equal(predicates.isString({ [Symbol.toStringTag]: 'String' }), false);
  for (const number of [0, -1, 1.5, Infinity])
    assert.equal(predicates.isNumber(number), true);
  for (const value of [
    NaN,
    new Number(1),
    '1',
    null,
    { [Symbol.toStringTag]: 'Number' },
  ]) {
    assert.equal(predicates.isNumber(value), false);
  }
  assert.equal(predicates.isArray([]), true);
  assert.equal(predicates.isArray({ [Symbol.toStringTag]: 'Array' }), false);
});

test('existence always returns a boolean while retaining the established truthiness and zero rule', () => {
  for (const value of [0, -0, 1, 'text', {}, []])
    assert.equal(predicates.isExist(value), true);
  for (const value of [undefined, null, false, '', NaN])
    assert.equal(predicates.isExist(value), false);
});

test('object, empty, nullish and function predicates retain their declared behavior', () => {
  assert.equal(predicates.isObject({}), true);
  assert.equal(predicates.isObject(null), false);
  assert.equal(predicates.isObject([]), false);
  assert.equal(predicates.isEmptyObject({}), true);
  assert.equal(predicates.isEmptyObject({ key: 0 }), false);
  assert.equal(predicates.isUndefined(undefined), true);
  assert.equal(predicates.isUndefined(null), false);
  assert.equal(predicates.isNull(null), true);
  assert.equal(
    predicates.isFunction(() => undefined),
    true
  );
  assert.equal(predicates.isFunction({}), false);
});

test('window identity is safe without a browser and precise with a browser global', () => {
  assert.equal(predicates.isWindow({}), false);
  const window = {};
  const browser = execute(sources.predicates, {}, { window });
  assert.equal(browser.isWindow(window), true);
  assert.equal(browser.isWindow({}), false);
});

test('window feature serialization and default target retain the existing behavior', () => {
  const calls = [];
  const helper = execute(
    sources.window,
    {},
    { window: { open: (...args) => calls.push(args) } }
  );
  helper.openWindow('https://example.test');
  helper.openWindow('https://example.test/details', {
    target: '_self',
    width: 640,
    resizable: true,
    toolbar: 'no',
  });
  assert.deepEqual(calls, [
    ['https://example.test', '_blank', ''],
    [
      'https://example.test/details',
      '_self',
      'width=640,resizable=true,toolbar=no',
    ],
  ]);
});

test('utilities typecheck strictly with both installed compilers and preserve narrowing', () => {
  const text = `import { isString, isNumber, isArray, isObject, isFunction, isExist } from ${JSON.stringify(
    paths.predicates.slice(0, -3)
  )};
import { openWindow } from ${JSON.stringify(paths.window.slice(0, -3))};
declare const value: unknown;
if (isString(value)) { const s: string = value; s.toUpperCase(); }
if (isNumber(value)) { const n: number = value; n.toFixed(2); }
if (isArray(value)) { const items: unknown[] = value; void items; }
if (isObject(value)) { const entry: unknown = value.key; void entry; }
if (isFunction(value)) { const result: unknown = value(); void result; }
const exists: boolean = isExist(value);
openWindow('https://example.test', { target: '_blank', width: 640, noopener: true });
void exists;`;
  for (const compiler of [ts, qualityTs])
    assertTypechecks(diagnostics(compiler, text));
});

function settingsStore() {
  const instance = pinia.createPinia();
  const settings = JSON.parse(
    fs.readFileSync(path.join(root, 'web/src/config/settings.json'), 'utf8')
  );
  const useStore = execute(sources.app, {
    pinia,
    'element-plus': { ElNotification: {} },
    '@peanut-admin/vue': { enabledTenantModulesFromRoutes: () => [] },
    '@/config/settings.json': { default: settings },
    '@/api/user': {
      getMenuList: async () => {
        throw new Error('Not part of the settings test');
      },
    },
    './server-menu': { default: () => [] },
  }).default;
  return useStore(instance);
}

test('settings updates retain native Pinia deep merge, unrelated fields and one patch notification', () => {
  const store = settingsStore();
  store.$patch({ extension: { nested: { kept: 1, changed: 1 }, other: true } });
  const events = [];
  const stop = store.$subscribe((mutation) => events.push(mutation.type), {
    flush: 'sync',
  });
  store.updateSettings({
    globalSettings: true,
    menuWidth: 260,
    extension: { nested: { changed: 2 } },
  });
  assert.equal(store.globalSettings, true);
  assert.equal(store.menuWidth, 260);
  assert.equal(store.theme, 'light');
  assert.deepEqual(JSON.parse(JSON.stringify(store.$state.extension)), {
    nested: { kept: 1, changed: 2 },
    other: true,
  });
  assert.deepEqual(events, ['patch object']);
  stop();
  store.$dispose();
});

test('the actual settings action and state typecheck against native Pinia without suppression', () => {
  const tree = ts.createSourceFile(
    paths.app,
    sources.app,
    ts.ScriptTarget.Latest,
    true
  );
  let action;
  const visit = (node) => {
    if (
      ts.isMethodDeclaration(node) &&
      node.name.getText(tree) === 'updateSettings'
    )
      action = node.getFullText(tree);
    ts.forEachChild(node, visit);
  };
  visit(tree);
  assert.ok(
    action,
    'Read the real updateSettings method, not a rewritten equivalent'
  );
  const text = `import { defineStore } from 'pinia';
import type { AppSettings, AppState } from ${JSON.stringify(
    path.join(root, 'web/src/store/modules/app/types')
  )};
import settings from ${JSON.stringify(
    path.join(root, 'web/src/config/settings.json')
  )};
const useStore = defineStore('settings-type-contract', { state: (): AppState => ({ ...settings }), actions: { ${action} } });
useStore().updateSettings({ globalSettings: true, menuCollapse: false });
useStore().updateSettings({ menuWidth: 260, theme: 'light' });`;
  for (const compiler of [ts, qualityTs]) {
    assertTypechecks(diagnostics(compiler, text));
    for (const invalid of ['{ serverMenu: [] }', '{ menuWidth: "wide" }']) {
      // Check the declared input directly: AppState's open index can widen Pinia's instance surface.
      assert.ok(
        diagnostics(
          compiler,
          text + '\nconst invalidPatch: Partial<AppSettings> = ' + invalid + ';'
        ).errors.length > 0
      );
    }
  }
});
