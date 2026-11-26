import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { runInNewContext } from 'node:vm';
import { fileURLToPath } from 'node:url';
import test from 'node:test';

const require = createRequire(new URL('../../../package.json', import.meta.url));
const vue = require('vue');
const ts = require('typescript');
const compiler = require('vue/compiler-sfc');
const filename = fileURLToPath(
  new URL('../../../src/modules/peanut-article/views/list/ArticleBodyHistory.vue', import.meta.url)
);
const { descriptor, errors } = compiler.parse(readFileSync(filename, 'utf8'), { filename });
assert.deepEqual(errors, []);
const script = descriptor.scriptSetup?.content ?? '';
const template = descriptor.template?.content ?? '';

function deferred() {
  let resolve;
  let reject;
  const promise = new Promise((yes, no) => { resolve = yes; reject = no; });
  return { promise, resolve, reject };
}
function fixture() {
  const scope = vue.effectScope(true);
  const requests = [];
  const listeners = new Map();
  let sessionGeneration = 1;
  let dispose;
  const props = vue.reactive({ articleId: 7, documentId: 9, contextKey: 'tenant-a' });
  const exports = {};
  const body = script +
    '\nexports.controls={opened,loading,error,items,nextRevisionNo,selectedRevisionId,viewerLoaded,load,close,selectRevision};';
  const result = ts.transpileModule(body, {
    fileName: filename + '.ts',
    compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.CommonJS, esModuleInterop: true },
    reportDiagnostics: true,
  });
  assert.equal(result.diagnostics?.length ?? 0, 0);
  scope.run(() => runInNewContext(result.outputText, {
    exports, defineProps: () => props, AbortController, localStorage: {},
    window: {
      addEventListener(name, listener) { listeners.set(name, listener); },
      removeEventListener(name) { listeners.delete(name); },
    },
    require(name) {
      if (name === 'vue') return { ...vue, onBeforeUnmount: (fn) => { dispose = fn; } };
      if (name === '@/utils/auth') return {
        getSessionSnapshot: () => ({ generation: sessionGeneration }),
      };
      if (name === '@/modules/peanut-document/components/DocumentViewer.vue') return {};
      if (name === '@/modules/peanut-document/api') return {
        getDocumentVersions(documentId, beforeRevisionNo, signal) {
          const call = deferred();
          requests.push({ documentId, beforeRevisionNo, signal, ...call });
          return call.promise;
        },
      };
      throw new Error('Unexpected document history dependency: ' + name);
    },
  }, { filename }));
  return {
    props, requests, ui: exports.controls,
    revokeSession() { sessionGeneration += 1; },
    dispose() { dispose(); scope.stop(); },
    listenerCount: () => listeners.size,
  };
}
function page(first, count, next, ownerObjectId = '7') {
  return {
    document_id: 9,
    owner: { module_key: 'peanut.article', object_type: 'article', object_id: ownerObjectId, slot: 'body' },
    items: Array.from({ length: count }, (_, index) => {
      const revision = first - index;
      return {
        revision_id: revision,
        revision_no: revision,
        created_at: '2026-11-27 10:00:00',
        source_state_seq: 0,
        content_kind: 'rich_text',
      };
    }),
    next_before_revision_no: next,
  };
}
async function firstPage(f) {
  const wait = f.ui.load(false);
  assert.equal(f.requests.length, 1);
  f.requests[0].resolve({ data: page(30, 20, 11) });
  await wait;
  assert.equal(f.ui.items.value.length, 20);
  assert.equal(f.ui.nextRevisionNo.value, 11);
}
test('temporary 503 while loading an older page preserves already approved document history and cursor', async () => {
  const f = fixture();
  try {
    await firstPage(f);
    f.ui.selectRevision(30);
    f.ui.viewerLoaded.value = true;
    const pending = f.ui.load(true);
    assert.equal(f.requests[1].beforeRevisionNo, 11);
    f.requests[1].reject({ response: { status: 503 } });
    await pending;
    assert.equal(f.ui.items.value.length, 20);
    assert.equal(f.ui.nextRevisionNo.value, 11);
    assert.equal(f.ui.selectedRevisionId.value, 30);
    assert.equal(f.ui.viewerLoaded.value, true);
    assert.match(f.ui.error.value, /仍可查看/);
    const retry = f.ui.load(true);
    assert.equal(f.requests[2].beforeRevisionNo, 11);
    f.requests[2].resolve({ data: page(10, 10, null) });
    await retry;
    assert.equal(f.ui.items.value.length, 30);
    assert.equal(f.ui.nextRevisionNo.value, null);
    assert.equal(f.ui.error.value, '');
  } finally { f.dispose(); }
});

test('revoked 403 on continuing document history clears prior confidential contents and viewer', async () => {
  const f = fixture();
  try {
    await firstPage(f);
    f.ui.selectRevision(30);
    const pending = f.ui.load(true);
    f.requests[1].reject({ response: { status: 403 } });
    await pending;
    assert.equal(f.ui.items.value.length, 0);
    assert.equal(f.ui.selectedRevisionId.value, undefined);
    assert.equal(f.ui.nextRevisionNo.value, null);
    assert.match(f.ui.error.value, /历史查询失败/);
  } finally { f.dispose(); }
});

test('malformed success or changed owner cannot be retained even on a continuation', async () => {
  const f = fixture();
  try {
    await firstPage(f);
    const pending = f.ui.load(true);
    f.requests[1].resolve({ data: page(10, 10, null, 'another-owner') });
    await pending;
    assert.equal(f.ui.items.value.length, 0);
    assert.match(f.ui.error.value, /历史查询失败/);
  } finally { f.dispose(); }
});

test('changing tenant context aborts an in-flight older-page request and prevents stale content disclosure', async () => {
  const f = fixture();
  try {
    await firstPage(f);
    const pending = f.ui.load(true);
    f.props.contextKey = 'tenant-b';
    assert.equal(f.requests[1].signal.aborted, true);
    f.requests[1].resolve({ data: page(10, 10, null) });
    await pending;
    assert.equal(f.ui.items.value.length, 0);
    assert.equal(f.ui.opened.value, false);
    assert.equal(f.ui.error.value, '');
  } finally { f.dispose(); }
});

test('the rendered Document consumer exposes retry, old-page status and read-only document viewer', () => {
  const compiled = compiler.compileTemplate({ source: template, filename, id: 'article-body-history' });
  assert.equal(compiled.errors.length, 0);
  assert.match(template, /重试加载更早版本/);
  assert.match(template, /@click="load\(true\)"/);
  assert.match(template, /:allow-downloads="false"/);
  assert.match(template, /@error="deny"/);
});
