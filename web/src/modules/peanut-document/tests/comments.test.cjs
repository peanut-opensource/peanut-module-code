/* Tests the actual component state machine with controlled transport responses, not a DOM or HTTP fixture. */
const assert = require('node:assert/strict');
const { test } = require('node:test');
const fs = require('node:fs');
const path = require('node:path');
const Module = require('node:module');
const { createHash } = require('node:crypto');
const ts = require(path.resolve(
  __dirname,
  '../../../../node_modules/typescript'
));
const sourcePath = path.resolve(__dirname, '../src/comments.ts');
const compiled = ts.transpileModule(fs.readFileSync(sourcePath, 'utf8'), {
  compilerOptions: {
    target: ts.ScriptTarget.ES2020,
    module: ts.ModuleKind.CommonJS,
  },
});
const actual = new Module(sourcePath, module);
actual.filename = sourcePath;
actual.paths = module.paths;
const rejectionPath = path.resolve(__dirname, '../src/comment-rejection.ts');
const rejection = new Module(rejectionPath, module);
rejection._compile(
  ts.transpileModule(fs.readFileSync(rejectionPath, 'utf8'), {
    compilerOptions: {
      target: ts.ScriptTarget.ES2020,
      module: ts.ModuleKind.CommonJS,
    },
  }).outputText,
  rejectionPath
);
const nativeRequire = actual.require.bind(actual);
actual.require = (id) =>
  id === './comment-rejection' ? rejection.exports : nativeRequire(id);
actual._compile(compiled.outputText, sourcePath);
const {
  RevisionCommentSession,
  DocumentCommentRejection,
  commentAnchor,
  parseCommentThreads,
} = actual.exports;

const hash = (value) => createHash('sha256').update(value).digest('hex');
const A = {
  documentId: 10,
  revisionId: 11,
  contextKey: 'synthetic-tenant-1-member-11-auth-1',
};
const B = {
  documentId: 20,
  revisionId: 21,
  contextKey: 'synthetic-tenant-2-member-33-auth-1',
};
const text = '中文😀e\u0301\nend';
const source = (scope) => ({
  document_id: scope.documentId,
  revision_id: scope.revisionId,
  revision_no: 1,
  source_state_seq: 1,
  version_digest: hash('version:' + scope.revisionId),
  text_profile: 'peanut.richtext.plain-text/1',
  offset_unit: 'unicode-code-point',
  text,
  text_sha256: hash(text),
  text_length: Array.from(text).length,
  owner: {
    module_key: 'example.notes',
    object_type: 'note',
    object_id: String(scope.documentId),
    slot: 'body',
  },
});
const thread = (scope, id = 1, revision = 1) => ({
  document_id: scope.documentId,
  revision_id: scope.revisionId,
  version_digest: source(scope).version_digest,
  text_profile: source(scope).text_profile,
  text_sha256: hash(text),
  thread_id: id,
  thread_revision: revision,
  start_offset: 0,
  end_offset: 2,
  quote: '中文',
  status: 'open',
  created_by_member_id: 11,
  updated_by_member_id: 22,
  created_at: '2026-11-07 00:00:00.000',
  updated_at: '2026-11-07 00:00:00.000',
});
const message = (threadId, id = 1, revision = 1) => ({
  message_id: id,
  thread_id: threadId,
  thread_revision: revision,
  action: revision === 1 ? 'create' : 'reply',
  body: 'Synthetic message',
  actor_member_id: revision === 1 ? 11 : 22,
  created_at: '2026-11-07 00:00:00.000',
});
const list = (scope, items = [thread(scope)]) => ({
  source: source(scope),
  can_write: true,
  items,
  next_before_thread_id: null,
});
const detail = (scope, id = 1, revision = 1) => ({
  source: source(scope),
  can_write: true,
  thread: thread(scope, id, revision),
  messages: [message(id, 1, 1)],
  next_after_message_id: null,
});
const receipt = (command, threadId = 1) => ({
  document_id: command.input.document_id,
  revision_id: command.input.revision_id,
  version_digest: command.input.version_digest,
  text_profile: command.input.text_profile,
  text_sha256: command.input.text_sha256,
  request_id: command.input.request_id,
  action: command.action,
  thread_id: command.action === 'create' ? threadId : command.input.thread_id,
  thread_revision:
    command.action === 'create'
      ? 1
      : command.input.expected_thread_revision + 1,
  status: command.action === 'resolve' ? 'resolved' : 'open',
  message_id: 1,
  actor_member_id: 11,
  created_at: '2026-11-07 00:00:00.000',
});
const defer = () => {
  let resolve;
  let reject;
  const promise = new Promise((yes, no) => {
    resolve = yes;
    reject = no;
  });
  return { promise, resolve, reject };
};
const transport = (overrides = {}) => ({
  threads: async (scope) => list(scope),
  thread: async (scope, id) => detail(scope, id),
  submit: async (command) => receipt(command),
  ...overrides,
});
async function ready(io = transport()) {
  let ids = 0;
  const session = new RevisionCommentSession(
    io,
    () => 'synthetic-request-' + ++ids
  );
  session.setScope(A);
  await session.load();
  return { session, ids: () => ids };
}
const anchor = { start_offset: 0, end_offset: 2, quote: '中文' };

test('real textarea UTF16 selection becomes code point range without splitting astral characters', () => {
  assert.deepEqual(commentAnchor('中😀e\u0301\n文', 1, 3), {
    start_offset: 1,
    end_offset: 2,
    quote: '😀',
  });
  assert.deepEqual(commentAnchor('中😀e\u0301\n文', 3, 5), {
    start_offset: 2,
    end_offset: 4,
    quote: 'e\u0301',
  });
  assert.throws(
    () => commentAnchor('中😀文', 1, 2),
    /DOCUMENT_COMMENT_RANGE_INVALID/
  );
  assert.throws(
    () => commentAnchor('中😀文', 2, 3),
    /DOCUMENT_COMMENT_RANGE_INVALID/
  );
  assert.throws(
    () => commentAnchor('A', 0, 0),
    /DOCUMENT_COMMENT_RANGE_INVALID/
  );
});
test('response binding rejects another document revision owner profile or exact quote', () => {
  for (const mutate of [
    (value) => {
      value.source.document_id = 99;
    },
    (value) => {
      value.source.revision_id = 99;
    },
    (value) => {
      value.source.text_profile = 'another-profile';
    },
    (value) => {
      value.items[0].quote = '文中';
    },
    (value) => {
      value.items[0].text_sha256 = hash('other');
    },
  ]) {
    const value = list(A);
    mutate(value);
    assert.throws(
      () => parseCommentThreads(value, A),
      /DOCUMENT_COMMENT_RESPONSE_INVALID/
    );
  }
  assert.throws(
    () =>
      parseCommentThreads(list(A), {
        ...A,
        owner: { ...source(A).owner, object_id: 'different' },
      }),
    /DOCUMENT_COMMENT_RESPONSE_INVALID/
  );
});
test('a late list response from the previous context cannot populate the new revision', async () => {
  const old = defer();
  const session = new RevisionCommentSession(
    transport({
      threads: (scope) =>
        scope.documentId === A.documentId
          ? old.promise
          : Promise.resolve(list(scope)),
    })
  );
  session.setScope(A);
  const pending = session.load();
  session.setScope(B);
  await session.load();
  old.resolve(list(A));
  await pending;
  assert.equal(session.source.document_id, B.documentId);
  assert.equal(session.threads[0].revision_id, B.revisionId);
});
test('late thread response cannot overwrite a newly selected thread', async () => {
  const one = defer();
  const two = defer();
  const { session } = await ready(
    transport({
      threads: async (scope) =>
        list(scope, [thread(scope, 2), thread(scope, 1)]),
      thread: (_scope, id) => (id === 1 ? one.promise : two.promise),
    })
  );
  const old = session.select(1);
  const current = session.select(2);
  two.resolve(detail(A, 2));
  await current;
  one.resolve(detail(A, 1));
  await old;
  assert.equal(session.active.thread_id, 2);
  assert.equal(session.messages[0].thread_id, 2);
});
test('unknown write retries the exact frozen command and request id without new input', async () => {
  const seen = [];
  const { session, ids } = await ready(
    transport({
      submit: async (command) => {
        seen.push(command);
        if (seen.length === 1) throw new Error('synthetic-timeout');
        return receipt(command);
      },
    })
  );
  await session.create(anchor, 'Original body');
  assert.equal(session.pending.state, 'unknown');
  assert.equal(session.pending.uncertain, true);
  assert.equal(session.source, undefined);
  assert.ok(Object.isFrozen(session.pending.command.input));
  assert.ok(Object.isFrozen(session.pending.command));
  await assert.rejects(() => session.create(anchor, 'Changed body'));
  assert.equal(ids(), 1);
  await session.retry();
  assert.equal(seen[0], seen[1]);
  assert.equal(seen[1].input.body, 'Original body');
  assert.equal(ids(), 1);
  assert.equal(session.pending, undefined);
  assert.equal(session.active.thread_id, 1);
});
test('a definite initial rejection permits explicit re-edit only after dismissing the old command', async () => {
  let calls = 0;
  const { session, ids } = await ready(
    transport({
      submit: async (command) => {
        if (++calls === 1)
          throw new DocumentCommentRejection(
            409,
            'DOCUMENT_COMMENT_REVISION_CONFLICT'
          );
        return receipt(command);
      },
    })
  );
  await session.create(anchor, 'First');
  assert.equal(session.pending.state, 'rejected');
  assert.equal(session.pending.uncertain, false);
  session.dismissRejected();
  await session.load();
  await session.create(anchor, 'Re-edited');
  assert.equal(ids(), 2);
  assert.equal(session.lastReceipt.request_id, 'synthetic-request-2');
});
test('denial after an unknown outcome does not discard the original potentially committed request', async () => {
  let calls = 0;
  const { session, ids } = await ready(
    transport({
      submit: async (command) => {
        calls += 1;
        if (calls === 1) throw new Error('synthetic-lost-ack');
        if (calls === 2)
          throw new DocumentCommentRejection(403, 'DOCUMENT_SESSION_DENIED');
        return receipt(command);
      },
    })
  );
  await session.create(anchor, 'Original');
  await session.retry();
  assert.equal(session.pending.state, 'rejected');
  assert.equal(session.pending.uncertain, true);
  const request = session.pending.command;
  session.dismissRejected();
  assert.equal(session.pending.command, request);
  await session.retry();
  assert.equal(ids(), 1);
  assert.equal(session.pending, undefined);
});
test('acknowledgement from another context cannot replace the current receipt or thread', async () => {
  const old = defer();
  let oldCommand;
  const { session } = await ready(
    transport({
      submit: (command) => {
        if (command.input.document_id === A.documentId) {
          oldCommand = command;
          return old.promise;
        }
        return Promise.resolve(receipt(command, 2));
      },
    })
  );
  const sending = session.create(anchor, 'A body');
  session.setScope(B);
  await session.load();
  await session.create(anchor, 'B body');
  assert.equal(session.lastReceipt.document_id, B.documentId);
  old.resolve(receipt(oldCommand));
  await sending;
  assert.equal(session.source.document_id, B.documentId);
  assert.equal(session.lastReceipt.document_id, B.documentId);
  assert.equal(session.active.thread_id, 2);
  session.setScope(A);
  assert.equal(session.pending, undefined);
});
test('returning to the original context before its late acknowledgement cannot leave it stuck sending', async () => {
  const old = defer();
  let command;
  const { session } = await ready(
    transport({
      submit: (value) => {
        command = value;
        return old.promise;
      },
    })
  );
  const sending = session.create(anchor, 'Original');
  session.setScope(B);
  await session.load();
  session.setScope(A);
  await session.load();
  assert.equal(session.pending.state, 'sending');
  old.resolve(receipt(command));
  await sending;
  assert.equal(session.pending, undefined);
  assert.equal(session.source.document_id, A.documentId);
  assert.equal(
    session.active,
    undefined,
    'A late acknowledgement must not select its former thread'
  );
});
test('revoked read clears old source authors threads messages and write capability', async () => {
  let denied = false;
  const { session } = await ready(
    transport({
      threads: async (scope) => {
        if (denied)
          throw new DocumentCommentRejection(403, 'OWNER_VIEW_DENIED');
        return list(scope);
      },
    })
  );
  await session.select(1);
  denied = true;
  await session.load();
  assert.equal(session.source, undefined);
  assert.deepEqual(session.threads, []);
  assert.deepEqual(session.messages, []);
  assert.equal(session.active, undefined);
  assert.equal(session.canWrite, false);
});
test('an invalid replacement scope clears previous content before reporting an error', async () => {
  const { session } = await ready();
  assert.throws(
    () => session.setScope({ ...B, contextKey: '' }),
    /DOCUMENT_COMMENT_CONTEXT_REQUIRED/
  );
  assert.equal(session.source, undefined);
  assert.equal(session.scope, undefined);
  assert.equal(session.canWrite, false);
});
test('malformed acknowledgement remains unknown and cannot be applied to a different request', async () => {
  const { session } = await ready(
    transport({
      submit: async (command) => ({
        ...receipt(command),
        request_id: 'wrong-request',
      }),
    })
  );
  await session.create(anchor, 'Original');
  assert.equal(session.pending.state, 'unknown');
  assert.equal(session.pending.uncertain, true);
  assert.equal(session.lastReceipt, undefined);
  assert.equal(session.source, undefined);
});
test('an immutable source changing digest within the same context is rejected rather than reanchored', async () => {
  let changed = false;
  const { session } = await ready(
    transport({
      threads: async (scope) => {
        const value = list(scope);
        if (changed) {
          value.source.version_digest = hash('changed');
          value.items[0].version_digest = value.source.version_digest;
        }
        return value;
      },
    })
  );
  changed = true;
  await session.load();
  assert.equal(session.source, undefined);
  assert.equal(session.error, 'DOCUMENT_COMMENT_RESPONSE_INVALID');
});
test('a replay receipt is followed by a new current read and does not roll the thread backwards', async () => {
  const { session } = await ready(
    transport({
      thread: async (scope, id) => detail(scope, id, 3),
    })
  );
  await session.create(anchor, 'Original replay');
  assert.equal(session.lastReceipt.thread_revision, 1);
  assert.equal(session.active.thread_revision, 3);
});
test('a view-only response cannot create or advance any request id', async () => {
  let submitted = 0;
  const { session, ids } = await ready(
    transport({
      threads: async (scope) => ({ ...list(scope), can_write: false }),
      submit: async (command) => {
        submitted += 1;
        return receipt(command);
      },
    })
  );
  await assert.rejects(
    () => session.create(anchor, 'Denied'),
    /DOCUMENT_COMMENT_NOT_WRITABLE/
  );
  assert.equal(submitted, 0);
  assert.equal(ids(), 0);
});
test('disposing the component prevents a late response from restoring its old content', async () => {
  const old = defer();
  const session = new RevisionCommentSession(
    transport({ threads: () => old.promise })
  );
  session.setScope(A);
  const loading = session.load();
  session.dispose();
  old.resolve(list(A));
  await loading;
  assert.equal(session.source, undefined);
  assert.deepEqual(session.threads, []);
});

const candidate = () => ({
  schemaVersion: 'peanut.richtext/1',
  editorModel: 'tiptap-prosemirror',
  content: {
    type: 'doc',
    content: [
      {
        type: 'paragraph',
        content: [
          { type: 'text', text: '修改后的完整正文', marks: [{ type: 'bold' }] },
        ],
      },
    ],
  },
  annotations: [],
});
const suggestion = (decision = 'pending') => ({
  candidate_content: candidate(),
  candidate_sha256: hash('candidate'),
  decision,
  decided_by_member_id: decision === 'pending' ? null : 22,
  decided_at: decision === 'pending' ? null : '2026-11-16 00:00:00.000',
  result_revision_id: decision === 'accepted' ? 99 : null,
  result_state_seq: decision === 'accepted' ? 8 : null,
});

test('isolated candidate is snapshotted and an unknown suggestion retries the same full request', async () => {
  const sent = [];
  const { session, ids } = await ready(
    transport({
      submit: async (command) => {
        sent.push(command);
        if (sent.length === 1) throw new Error('network result unknown');
        return {
          ...receipt(command),
          thread_id: 1,
          thread_revision: 1,
          suggestion: suggestion(),
        };
      },
    })
  );
  const local = candidate();
  await session.suggest(anchor, '格式与正文修改说明', local);
  local.content.content[0].content[0].text = '后来继续编辑的本地文字';
  assert.equal(session.pending.state, 'unknown');
  assert.equal(session.source, undefined);
  assert.equal(
    session.pending.command.input.candidate_content.content.content[0]
      .content[0].text,
    '修改后的完整正文'
  );
  assert.equal(ids(), 1);
  await session.retry();
  assert.equal(sent[0], sent[1]);
  assert.equal(session.pending, undefined);
  assert.equal(session.lastReceipt.action, 'suggest');
  assert.equal(ids(), 1);
});

test('accepting a pending suggestion uses current waterline and preserves independent resolved discussion status', async () => {
  const proposed = {
    ...thread(A),
    status: 'resolved',
    suggestion: suggestion(),
  };
  let submitted;
  const { session } = await ready(
    transport({
      threads: async () => list(A, [proposed]),
      thread: async () => ({ ...detail(A), thread: proposed }),
      submit: async (command) => {
        submitted = command;
        return {
          ...receipt(command),
          status: 'resolved',
          suggestion: suggestion('accepted'),
          result_revision_id: 99,
          result_state_seq: 8,
        };
      },
    })
  );
  await session.select(1);
  await session.decideSuggestion('accept', 7);
  assert.equal(submitted.action, 'accept');
  assert.equal(submitted.input.expected_state_seq, 7);
  assert.equal(submitted.input.expected_thread_revision, 1);
  assert.equal(session.pending, undefined);
  assert.equal(session.lastReceipt.status, 'resolved');
  assert.equal(session.lastReceipt.result_revision_id, 99);
});

test('a view-only scope cannot submit a full candidate or allocate a request id', async () => {
  const { session, ids } = await ready(
    transport({ threads: async () => ({ ...list(A), can_write: false }) })
  );
  await assert.rejects(
    () => session.suggest(anchor, '修改说明', candidate()),
    /DOCUMENT_COMMENT_NOT_WRITABLE/
  );
  assert.equal(ids(), 0);
  assert.equal(session.pending, undefined);
});

test('an incomplete accept receipt remains unknown and retains its original decision request', async () => {
  const proposed = { ...thread(A), suggestion: suggestion() };
  const { session } = await ready(
    transport({
      threads: async () => list(A, [proposed]),
      thread: async () => ({ ...detail(A), thread: proposed }),
      submit: async (command) => ({
        ...receipt(command),
        suggestion: suggestion('accepted'),
      }),
    })
  );
  await session.select(1);
  await session.decideSuggestion('accept', 7);
  assert.equal(session.pending.state, 'unknown');
  assert.equal(session.pending.command.input.expected_state_seq, 7);
  assert.equal(session.lastReceipt, undefined);
});

test('an empty fixed document permits the suggestion-only empty anchor while ordinary comments still require a quote', async () => {
  const emptySource = {
    ...source(A),
    text: '',
    text_length: 0,
    text_sha256: hash(''),
  };
  const emptyThread = {
    ...thread(A),
    text_sha256: hash(''),
    start_offset: 0,
    end_offset: 0,
    quote: '',
    suggestion: suggestion(),
  };
  const emptyPage = { ...list(A, [emptyThread]), source: emptySource };
  const { session, ids } = await ready(
    transport({
      threads: async () => emptyPage,
      thread: async () => ({
        ...detail(A),
        source: emptySource,
        thread: emptyThread,
      }),
      submit: async (command) => ({
        ...receipt(command),
        thread_id: 1,
        thread_revision: 1,
        suggestion: suggestion(),
      }),
    })
  );
  const emptyAnchor = { start_offset: 0, end_offset: 0, quote: '' };
  await assert.rejects(
    () => session.create(emptyAnchor, '普通评论不能使用空引用'),
    /DOCUMENT_COMMENT_RANGE_INVALID/
  );
  assert.equal(ids(), 0);
  await session.suggest(emptyAnchor, '从空正文开始提出完整候选', candidate());
  assert.equal(session.lastReceipt.action, 'suggest');
  assert.equal(session.pending, undefined);
  assert.equal(session.error, undefined);
  assert.equal(session.active.suggestion.decision, 'pending');
  assert.throws(
    () =>
      parseCommentThreads(
        { ...emptyPage, items: [{ ...emptyThread, suggestion: null }] },
        A
      ),
    /DOCUMENT_COMMENT_RESPONSE_INVALID/
  );
});

test('candidate boundary removes null and empty attrs while preserving native non-null defaults', async () => {
  let submitted;
  const { session } = await ready(
    transport({
      submit: async (command) => {
        submitted = command;
        throw new Error('hold original candidate');
      },
    })
  );
  const local = candidate();
  local.content.content[0].attrs = { textAlign: null };
  local.content.content[0].content[0].marks = [
    {
      type: 'link',
      attrs: {
        href: 'https://example.com',
        target: '_blank',
        rel: 'noopener noreferrer',
        class: null,
      },
    },
  ];
  local.content.content.push({
    type: 'orderedList',
    attrs: { start: 1 },
    content: [
      { type: 'listItem', content: [{ type: 'paragraph', attrs: {} }] },
    ],
  });
  await session.suggest(anchor, '完整候选的原生格式', local);
  const snapshot = submitted.input.candidate_content;
  assert.equal(snapshot.content.content[0].attrs, undefined);
  assert.deepEqual(snapshot.content.content[0].content[0].marks[0].attrs, {
    href: 'https://example.com',
    target: '_blank',
    rel: 'noopener noreferrer',
  });
  assert.deepEqual(snapshot.content.content[1].attrs, { start: 1 });
  assert.equal(
    snapshot.content.content[1].content[0].content[0].attrs,
    undefined
  );
  assert.deepEqual(local.content.content[0].attrs, { textAlign: null });
});
