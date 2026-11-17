import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { runInNewContext } from 'node:vm';
import { test } from 'node:test';

const root = new URL('../../../', import.meta.url);
const require = createRequire(import.meta.url);
const ts = require(fileURLToPath(
  new URL('tools/quality/node_modules/typescript', root)
));
const source = readFileSync(
  new URL('uniapp/src/utils/decoration.ts', root),
  'utf8'
);
const compiled = ts.transpileModule(source, {
  compilerOptions: {
    module: ts.ModuleKind.CommonJS,
    target: ts.ScriptTarget.ES2022,
  },
}).outputText;
const theme = {
  themeColorId: 1,
  topTextColor: 'black',
  navigationBarColor: '#123456',
  themeColor1: '#123456',
  themeColor2: '#234567',
  buttonColor: 'white',
};

function fixture({ pages = [], setColor = () => {} } = {}) {
  const exports = {};
  const warnings = [];
  const calls = [];
  runInNewContext(compiled, {
    exports,
    require: (name) => {
      assert.equal(name, '@/utils/request');
      return {
        http: new Proxy(
          {},
          {
            get() {
              throw new Error('Unexpected HTTP');
            },
          }
        ),
      };
    },
    getCurrentPages: () => pages,
    uni: {
      setNavigationBarColor(options) {
        calls.push(options);
        return setColor(options);
      },
    },
    console: { warn: (...args) => warnings.push(args) },
  });
  return { apply: exports.applyDecorationTheme, warnings, calls, pages };
}

test('configuration can load before a page, then apply on a page-level call', () => {
  const f = fixture();
  f.apply({ data: theme });
  assert.equal(f.calls.length, 0);
  f.pages.push({ route: 'pages/index/index' });
  f.apply({ data: theme });
  assert.equal(f.calls.length, 1);
  assert.equal(f.calls[0].frontColor, '#000000');
  assert.equal(f.calls[0].backgroundColor, '#123456');
  assert.equal(typeof f.calls[0].fail, 'function');
  assert.equal(f.warnings.length, 0);
});

test('an absent theme never invokes page styling', () => {
  const f = fixture({ pages: [{}] });
  f.apply(null);
  f.apply({ data: {} });
  assert.equal(f.calls.length, 0);
});

test('native async failure callback records a disappearing page', async () => {
  const nativeError = { errMsg: 'setNavigationBarColor:fail page not found' };
  const f = fixture({
    pages: [{}],
    setColor: (options) => queueMicrotask(() => options.fail(nativeError)),
  });
  f.apply(theme);
  await new Promise((done) => queueMicrotask(done));
  assert.equal(f.warnings.length, 1);
  assert.equal(f.warnings[0][0], 'Unable to apply navigation theme');
  assert.equal(f.warnings[0][1], nativeError);
});

test('synchronous runtime failure is still reported', () => {
  const error = new Error('synthetic unavailable API');
  const f = fixture({
    pages: [{}],
    setColor: () => {
      throw error;
    },
  });
  f.apply(theme);
  assert.equal(f.warnings.length, 1);
  assert.equal(f.warnings[0][1], error);
});

test('white text and the configured background are retained', () => {
  const f = fixture({ pages: [{}] });
  f.apply({ ...theme, topTextColor: 'white', navigationBarColor: '#abcdef' });
  assert.equal(f.calls[0].frontColor, '#ffffff');
  assert.equal(f.calls[0].backgroundColor, '#abcdef');
});
