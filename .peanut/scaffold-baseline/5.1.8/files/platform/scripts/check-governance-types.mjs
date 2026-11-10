import { createRequire } from 'node:module';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const require = createRequire(resolve(root, 'package.json'));
const ts = require('typescript');
const configPath = resolve(
  root,
  'src/modules/governance/optional-runtime/tsconfig.json'
);
const loaded = ts.readConfigFile(configPath, ts.sys.readFile);
if (loaded.error)
  throw new Error(
    ts.flattenDiagnosticMessageText(loaded.error.messageText, '\n')
  );
const parsed = ts.parseJsonConfigFileContent(
  loaded.config,
  ts.sys,
  dirname(configPath)
);
if (parsed.options.strict !== true)
  throw new Error('GOVERNANCE_STRICT_TYPES_REQUIRED');
const files = new Set(parsed.fileNames.map((path) => resolve(path)));
for (const entry of [
  'api/client.ts',
  'generated/api.d.ts',
  'governance/index.ts',
  'governance/types.ts',
  'governance/catalog.ts',
  'governance/menu.ts',
  'governance/roles.ts',
  'governance/audit.ts',
]) {
  if (!files.has(resolve(dirname(configPath), entry))) {
    throw new Error('GOVERNANCE_SOURCE_EXCLUDED: ' + entry);
  }
}
const program = ts.createProgram(parsed.fileNames, {
  ...parsed.options,
  noEmit: true,
});
const diagnostics = [...parsed.errors, ...ts.getPreEmitDiagnostics(program)];
if (diagnostics.length !== 0) {
  console.error(
    ts.formatDiagnostics(diagnostics, {
      getCanonicalFileName: (path) => path,
      getCurrentDirectory: () => root,
      getNewLine: () => '\n',
    })
  );
  process.exitCode = 1;
} else {
  console.log(
    `GOVERNANCE-TYPE-COVERAGE-001 passed: ${files.size} configured source files; strict=true`
  );
}
