import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { isFormattingSource } from '../format-source.mjs';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const require = createRequire(resolve(root, 'tools/quality/package.json'));
const prettier = require('prettier');
const policy = require(resolve(root, '.prettierrc.cjs'));

for (const path of [
  'package.json',
  'server/composer.json',
  'tools/quality/composer.json',
  'web/package.json',
  'web/tsconfig.json',
  'web/tsconfig.optional-tests.json',
  'server/app/modules/official/identity/module.json',
  'server/app/modules/official/identity/composer.json',
  'packages/client/package.json',
  'packages/client/tsconfig.json',
  '.vscode/settings.json',
]) {
  test('authored configuration uses the locked policy: ' + path, () => {
    assert.equal(isFormattingSource(path), true);
  });
}

for (const path of [
  'plugins/official.identity/plugin.json',
  'plugins/module.json',
  'scaffold/application-template-inventory.json',
  'scaffold/releases/v1/files/package.json',
  'server/tests/fixtures/example/module.json',
  'web/node_modules/example/package.json',
  'web/.nuxt/tsconfig.json',
  'resources/architecture/tpq-issue-register.json',
  'server/composer.lock',
  'web/package-lock.json',
  'web/src/generated/api.json',
  'output/module.json',
]) {
  test('generated, historical and fixture bytes stay excluded: ' + path, () => {
    assert.equal(isFormattingSource(path), false);
  });
}

test('JSON formatting retains values and is idempotent', () => {
  const source =
    '{"autoload":{"psr-4":{"Example\\\\":"src/"}},"enabled":false,"n":0,"empty":null,"list":["x",2]}';
  const formatted = prettier.format(source, { ...policy, parser: 'json' });
  assert.deepEqual(JSON.parse(formatted), JSON.parse(source));
  assert.equal(
    prettier.format(formatted, { ...policy, parser: 'json' }),
    formatted
  );
  assert.equal(formatted.includes('\r'), false);
  assert.equal(formatted.includes('\t'), false);
  assert.match(formatted, /\n  "autoload":/);
});
