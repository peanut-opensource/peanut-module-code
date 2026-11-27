import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { runInNewContext } from 'node:vm';
import { fileURLToPath } from 'node:url';
import test from 'node:test';

const require = createRequire(new URL('../../../package.json', import.meta.url));
const ts = require('typescript');
const compiler = require('vue/compiler-sfc');
const root = new URL('../../../src/modules/', import.meta.url);
const sources = {
  operations: new URL('peanut-document/components/DocumentOperations.vue', root),
  surface: new URL('peanut-document/components/DocumentSurface.vue', root),
  article: new URL('peanut-article/views/list/ArticleBodyEditor.vue', root),
};
const parse = (url) => {
  const filename = fileURLToPath(url);
  const result = compiler.parse(readFileSync(filename, 'utf8'), { filename });
  assert.deepEqual(result.errors, []);
  return { ...result, filename };
};

function restorationControls(overrides = {}) {
  const { descriptor, filename } = parse(sources.operations);
  const source = descriptor.scriptSetup?.content ?? '';
  const begin = source.indexOf('  watch(\n    () => props.restoreSelection,');
  const end = source.indexOf('  watch(\n    [\n      () => props.contextKey,', begin);
  assert.ok(begin >= 0 && end > begin, 'test actual DocumentOperations proposal watch');
  let handler;
  const props = {
    restoreSelection: undefined,
    readOnly: false,
    document: { document_id: 8 },
    ...overrides,
  };
  const action = { value: 'version.create' };
  const revision = { value: '' };
  const busy = { value: false };
  const command = { value: undefined };
  const allowed = { value: ['version.create', 'version.restore'] };
  const code = ts.transpileModule(source.slice(begin, end), {
    fileName: filename + '.ts', compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.CommonJS },
    reportDiagnostics: true,
  });
  assert.equal(code.diagnostics?.length ?? 0, 0);
  runInNewContext(code.outputText, {
    watch(_getter, callback) { handler = callback; },
    props, action, revision, busy, command, allowed,
    targetRequestId: undefined,
    disposed: false,
  }, { filename });
  return { props, action, revision, busy, command, allowed, propose(value) { handler(value); } };
}

test('a historical revision only pre-fills the official restore command; never submits', () => {
  const f = restorationControls();
  f.propose({ revisionId: 41, selectionKey: 'article:1' });
  assert.equal(f.action.value, 'version.restore');
  assert.equal(f.revision.value, '41');
  assert.equal(f.command.value, undefined);
  f.propose({ revisionId: 35, selectionKey: 'article:2' });
  assert.equal(f.revision.value, '35');
  assert.equal(f.command.value, undefined);
});

test('read-only, stale or admitted operations cannot be overwritten by history proposals', () => {
  for (const overrides of [{ readOnly: true }, { document: undefined }]) {
    const f = restorationControls(overrides);
    f.propose({ revisionId: 41, selectionKey: 'article:1' });
    assert.equal(f.action.value, 'version.create');
    assert.equal(f.revision.value, '');
  }
  const f = restorationControls();
  for (const invalid of [{ revisionId: -1, selectionKey: 'invalid' },
    { revisionId: 2.5, selectionKey: 'invalid' }]) {
    f.propose(invalid);
    assert.equal(f.revision.value, '');
  }
  f.busy.value = true;
  f.propose({ revisionId: 41, selectionKey: 'busy' });
  assert.equal(f.revision.value, '');
  f.busy.value = false;
  f.command.value = { action: 'version.restore', input: {} };
  f.propose({ revisionId: 42, selectionKey: 'admitted' });
  assert.equal(f.revision.value, '');
});

test('real Article owner only offers a restore proposal for saved editable content', () => {
  const { descriptor, filename } = parse(sources.article);
  const script = descriptor.scriptSetup?.content ?? '';
  const begin = script.indexOf('  const canRestoreHistory = computed(');
  const end = script.indexOf('  function adoptDraft(', begin);
  assert.ok(begin >= 0 && end > begin);
  const canAppendDraft = { value: true };
  const saveState = { value: 'saved' };
  const hasUnsaved = { value: false };
  const restoreSelection = { value: undefined };
  const message = { value: '' };
  const context = { value: 'tenant-1:article-7' };
  const source = script.slice(begin, end) + '\nexports.ui={canRestoreHistory,prepareHistoryRestore};';
  const out = ts.transpileModule(source, { fileName: filename + '.ts', compilerOptions: {
    target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.CommonJS,
  }, reportDiagnostics: true });
  assert.equal(out.diagnostics?.length ?? 0, 0);
  const exports = {};
  runInNewContext(out.outputText, {
    exports, canAppendDraft, saveState, hasUnsaved, restoreSelection, message, context,
    computed: (get) => ({ get value() { return get(); } }),
    restoreSelectionSequence: 0,
  }, { filename });
  exports.ui.prepareHistoryRestore(24);
  assert.equal(restoreSelection.value.revisionId, 24);
  assert.match(message.value, /尚未提交/);
  hasUnsaved.value = true;
  exports.ui.prepareHistoryRestore(25);
  assert.equal(restoreSelection.value.revisionId, 24);
  hasUnsaved.value = false;
  saveState.value = 'error';
  exports.ui.prepareHistoryRestore(26);
  assert.equal(restoreSelection.value.revisionId, 24);
  saveState.value = 'saved';
  canAppendDraft.value = false;
  exports.ui.prepareHistoryRestore(27);
  assert.equal(restoreSelection.value.revisionId, 24);
});

test('real Article and Document component templates pass verified selection to the existing operation UI', () => {
  const article = parse(sources.article);
  const surface = parse(sources.surface);
  const operations = parse(sources.operations);
  for (const item of [article, surface, operations])
    assert.deepEqual(compiler.compileTemplate({
      source: item.descriptor.template.content, filename: item.filename, id: 'history-restore',
    }).errors, []);
  const a = article.descriptor.template.content;
  assert.match(a, /:can-restore="canRestoreHistory"/);
  assert.match(a, /@request-restore="prepareHistoryRestore"/);
  assert.match(a, /:restore-selection="restoreSelection"/);
  assert.match(surface.descriptor.template.content, /:restore-selection="props.restoreSelection"/);
  assert.match(operations.descriptor.template.content, /@click="start"/);
  assert.match(operations.descriptor.template.content, /恢复会创建新版本并保留原版本/);
});
