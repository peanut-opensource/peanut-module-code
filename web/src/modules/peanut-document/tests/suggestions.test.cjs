const assert = require('node:assert/strict');
const { test } = require('node:test');
const fs = require('node:fs');
const path = require('node:path');
const Module = require('node:module');
const ts = require(path.resolve(
  __dirname,
  '../../../../node_modules/typescript'
));
const sourcePath = path.resolve(__dirname, '../src/suggestions.ts');
const actual = new Module(sourcePath, module);
actual.filename = sourcePath;
actual.paths = module.paths;
actual._compile(
  ts.transpileModule(fs.readFileSync(sourcePath, 'utf8'), {
    compilerOptions: {
      target: ts.ScriptTarget.ES2020,
      module: ts.ModuleKind.CommonJS,
    },
  }).outputText,
  sourcePath
);
const { suggestionTextChange, parseDocumentChanges } = actual.exports;

test('candidate difference retains Unicode code point boundaries', () => {
  assert.deepEqual(suggestionTextChange('甲😀旧文乙', '甲😀新文乙'), {
    prefix: '甲😀',
    removed: '旧',
    added: '新',
    suffix: '文乙',
  });
});

const change = (id = 2) => ({
  change_id: id,
  document_id: 10,
  state_seq: 7,
  kind: 'suggestion.accept',
  actor_member_id: 22,
  source_label: '实际接受建议的成员',
  from_projection_sha256: 'a'.repeat(64),
  to_projection_sha256: 'b'.repeat(64),
  before_text: '修改前',
  after_text: '修改后',
  created_at: '2026-11-16',
  operation_id: 'original-operation',
});
test('persisted provenance retains the real submitter and nullable unknown account', () => {
  const page = parseDocumentChanges(
    {
      items: [change(), { ...change(1), actor_member_id: null }],
      next_before_change_id: null,
    },
    10
  );
  assert.equal(page.items[0].actor_member_id, 22);
  assert.equal(page.items[1].actor_member_id, null);
});
test('provenance from another document and unordered pagination are rejected', () => {
  assert.throws(
    () =>
      parseDocumentChanges(
        { items: [change()], next_before_change_id: null },
        20
      ),
    /DOCUMENT_CHANGES_RESPONSE_INVALID/
  );
  assert.throws(
    () =>
      parseDocumentChanges(
        { items: [change(1), change(2)], next_before_change_id: 2 },
        10
      ),
    /DOCUMENT_CHANGES_RESPONSE_INVALID/
  );
});
