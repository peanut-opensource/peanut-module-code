import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import vm from 'node:vm';
import test from 'node:test';

const root = path.resolve(
  path.dirname(fileURLToPath(import.meta.url)),
  '../..'
);
const require = createRequire(path.join(root, 'web/package.json'));
const compiler = require('vue/compiler-sfc');
const ts = require('typescript');
const file = path.join(
  root,
  'web/src/views/system/role/RoleDataPolicyDialog.vue'
);
const parsed = compiler.parse(fs.readFileSync(file, 'utf8'), {
  filename: file,
});
assert.deepEqual(parsed.errors, []);
const source = parsed.descriptor.scriptSetup.content;
const syntax = ts.createSourceFile(
  file,
  source,
  ts.ScriptTarget.Latest,
  true,
  ts.ScriptKind.TS
);
const functions = syntax.statements.filter(
  (statement) =>
    ts.isFunctionDeclaration(statement) &&
    ['dateValue', 'dateText', 'cleanPolicy'].includes(statement.name?.text)
);
assert.equal(functions.length, 3);
const code = ts.transpileModule(
  functions.map((statement) => statement.getText(syntax)).join('\n'),
  {
    compilerOptions: { target: ts.ScriptTarget.ES2020 },
  }
).outputText;
const cleanPolicy = vm.runInNewContext(`${code}\ncleanPolicy;`);

test('changing only the reason preserves UTC policy validity in local time zones', () => {
  const previous = process.env.TZ;
  try {
    for (const zone of ['UTC', 'Asia/Shanghai']) {
      process.env.TZ = zone;
      const input = {
        status: 'active',
        reason: 'before',
        valid_from: '2031-01-02 03:04:05.123',
        valid_until: '2031-02-03 04:05:06.456',
        groups: [],
      };
      const loaded = cleanPolicy(input);
      loaded.reason = 'after';
      const outgoing = cleanPolicy(loaded);
      assert.equal(outgoing.valid_from, '2031-01-02T03:04:05.123Z');
      assert.equal(outgoing.valid_until, '2031-02-03T04:05:06.456Z');
      assert.equal(outgoing.reason, 'after');
      assert.equal(
        cleanPolicy({ ...input, valid_from: null, valid_until: null })
          .valid_from,
        null
      );
      assert.equal(
        cleanPolicy({ ...input, valid_from: '2031-01-02T11:04:05.123+08:00' })
          .valid_from,
        outgoing.valid_from
      );
    }
  } finally {
    if (previous === undefined) delete process.env.TZ;
    else process.env.TZ = previous;
  }
});
