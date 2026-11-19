import { readdirSync, existsSync } from 'node:fs';
import { createRequire } from 'node:module';
import { dirname, resolve, relative } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const require = createRequire(resolve(root, '../tools/quality/package.json'));
const ts = require('typescript');
const areas = [
  'peanut-file/optional-runtime',
  'peanut-import-export/optional-runtime',
  'peanut-notification/optional-runtime',
  'peanut-task/optional-runtime',
  'peanut-reference-codes/runtime',
  'peanut-settings/runtime',
  'peanut-content-tools',
  'peanut-rich-text',
];
const vueExtension = [
  {
    extension: '.vue',
    isMixedContent: true,
    scriptKind: ts.ScriptKind.Deferred,
  },
];
function parse(file) {
  const loaded = ts.readConfigFile(file, ts.sys.readFile);
  if (loaded.error)
    throw new Error(
      ts.flattenDiagnosticMessageText(loaded.error.messageText, '\n')
    );
  const config = ts.parseJsonConfigFileContent(
    loaded.config,
    ts.sys,
    dirname(file),
    {},
    file,
    undefined,
    vueExtension
  );
  if (config.errors.length || config.options.strict !== true) {
    throw new Error(
      'OPTIONAL_CONFIG_INVALID: ' +
        file +
        '\n' +
        config.errors
          .map((error) =>
            ts.flattenDiagnosticMessageText(error.messageText, '\n')
          )
          .join('\n')
    );
  }
  return config;
}
function files(directory) {
  return readdirSync(directory, { recursive: true })
    .filter((name) => /\.(?:ts|tsx|vue)$/.test(name))
    .map((name) => resolve(directory, name));
}
function requireIncluded(config, expected, label) {
  if (expected.length === 0) throw new Error('OPTIONAL_EMPTY_SCOPE: ' + label);
  const selected = new Set(config.fileNames.map((file) => resolve(file)));
  for (const file of expected) {
    if (!selected.has(file))
      throw new Error('OPTIONAL_SOURCE_EXCLUDED: ' + relative(root, file));
  }
}
const production = parse(resolve(root, 'tsconfig.json'));
const tests = parse(resolve(root, 'tsconfig.optional-tests.json'));
const selectedCore = process.env.WEB_CORE_SOURCE_ROOT;
if (!selectedCore)
  throw new Error(
    'WEB_CORE_SOURCE_ROOT is required for optional runtime type checks.'
  );
const corePaths = {};
for (const name of ['client', 'vue', 'ui-vue']) {
  const entry = resolve(selectedCore, `packages/${name}/src/index.ts`);
  if (!existsSync(entry)) throw new Error('SELECTED_CORE_MISSING: ' + entry);
  corePaths[`@peanut-admin/${name}`] = [entry];
}
let productionCount = 0;
let testCount = 0;
for (const area of areas) {
  const directory = resolve(root, `src/modules/${area}`);
  const productionFiles = files(resolve(directory, 'src'));
  if (area === 'peanut-rich-text')
    productionFiles.push(
      ...files(resolve(directory, 'components')),
      resolve(directory, 'contribution.ts')
    );
  const testFiles = files(resolve(directory, 'tests'));
  requireIncluded(production, productionFiles, area + ' production');
  requireIncluded(
    parse(resolve(directory, 'tsconfig.json')),
    productionFiles,
    area + ' module'
  );
  requireIncluded(tests, testFiles, area + ' tests');
  productionCount += productionFiles.length;
  testCount += testFiles.length;
}
const program = ts.createProgram(tests.fileNames, {
  ...tests.options,
  paths: { ...tests.options.paths, ...corePaths },
  noEmit: true,
});
const errors = ts.getPreEmitDiagnostics(program);
if (errors.length) {
  console.error(
    ts.formatDiagnosticsWithColorAndContext(errors, {
      getCanonicalFileName: (file) => file,
      getCurrentDirectory: () => root,
      getNewLine: () => '\n',
    })
  );
  process.exitCode = 1;
} else {
  console.log(
    `OPTIONAL-TYPE-COVERAGE-001 passed: ${productionCount} production files included, ${testCount} test files checked; strict=true`
  );
}
