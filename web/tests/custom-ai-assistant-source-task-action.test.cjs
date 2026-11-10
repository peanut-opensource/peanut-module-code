'use strict';

// Executes the real API payload helpers and original-request ledger without HTTP,
// browser storage, credentials, or a running service.
const assert = require('node:assert/strict');
const { test } = require('node:test');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const { webcrypto } = require('node:crypto');
const ts = require('typescript');
const { parse, compileScript, compileTemplate } = require('vue/compiler-sfc');

const root = path.resolve(__dirname, '..');
const moduleRoot = path.join(root, 'src/modules/custom-ai-assistant');
const modules = new Map();
function sourceModule(name) {
  if (modules.has(name)) return modules.get(name);
  const filename = path.join(moduleRoot, name + '.ts');
  const source = fs.readFileSync(filename, 'utf8');
  const compiled = ts.transpileModule(source, {
    fileName: filename,
    reportDiagnostics: true,
    compilerOptions: {
      target: ts.ScriptTarget.ES2022,
      module: ts.ModuleKind.CommonJS,
    },
  });
  assert.equal(
    (compiled.diagnostics || []).filter(
      (d) => d.category === ts.DiagnosticCategory.Error
    ).length,
    0,
    name + ' TypeScript syntax'
  );
  const module = { exports: {} };
  const failTransport = () => {
    throw new Error('HTTP is forbidden in this regression');
  };
  vm.runInNewContext(
    compiled.outputText,
    {
      module,
      exports: module.exports,
      require(specifier) {
        if (['./request-recovery', './api-session', './text-draft-api'].includes(specifier))
          return sourceModule(specifier.slice(2));
        if (specifier === 'axios')
          return { get: failTransport, post: failTransport };
        if (specifier === '@/utils/auth')
          return { getSessionSnapshot: () => ({ generation: 1 }) };
        throw new Error('Unexpected import: ' + specifier);
      },
      crypto: webcrypto,
      TextEncoder,
      AbortController,
      console,
      setTimeout,
      clearTimeout,
    },
    { filename }
  );
  modules.set(name, module.exports);
  return module.exports;
}
const api = sourceModule('api');
const { OriginalRequestStore } = sourceModule('request-recovery');
const plain = (value) => JSON.parse(JSON.stringify(value));
function record(patch = {}) {
  return {
    id: 17,
    title: 'Synthetic source preparation',
    status: 'preparing',
    version: 31,
    created_by: 7,
    details: { stage: 'prepared' },
    created_at: 1,
    updated_at: 2,
    ...patch,
  };
}
function nativeRecord(status = 'queued', details = {}) {
  return record({
    status,
    details: {
      stage: 'prepared',
      native_job_key: 'job_fixture_17',
      ...details,
    },
    native_task: {
      job_key: 'job_fixture_17',
      status,
      revision: 9,
      attempt_count: status === 'dead' ? 1 : 0,
      last_error_code: null,
    },
  });
}
function memoryStorage() {
  const values = new Map();
  return {
    getItem: (key) => values.get(key) ?? null,
    setItem: (key, value) => {
      values.set(key, value);
    },
    removeItem: (key) => {
      values.delete(key);
    },
    get length() {
      return values.size;
    },
    key: (index) => [...values.keys()][index] ?? null,
  };
}
function store(storage = memoryStorage()) {
  return new OriginalRequestStore(
    storage,
    { session: 'synthetic-session', tenantId: 11, memberId: 7 },
    { run: async (_key, work) => work() }
  );
}

test('preparation cancellation carries the exact business version without a fabricated native revision', () => {
  for (const stage of ['preparing', 'prepared']) {
    const r = record({ details: { stage } });
    assert.equal(api.canCancelTaskPreparation(r), true);
    assert.equal(api.canCancelTask(r), true);
    const payload = plain(
      api.taskActionPayload(r, 'cancel', 'prepare-cancel-17')
    );
    assert.deepEqual(payload, {
      id: 17,
      action: 'cancel',
      version: 31,
      request_id: 'prepare-cancel-17',
    });
    assert.equal(Object.hasOwn(payload, 'job_revision'), false);
  }
});

test('native actions preserve the actual native revision and never substitute business version', () => {
  const r = nativeRecord();
  assert.equal(api.canCancelTaskPreparation(r), false);
  for (const action of ['cancel', 'retry', 'observeUnknown', 'poll']) {
    const payload = plain(api.taskActionPayload(r, action, 'native-action-17'));
    assert.deepEqual(payload, {
      id: 17,
      action,
      job_revision: 9,
      request_id: 'native-action-17',
    });
    assert.equal(Object.hasOwn(payload, 'version'), false);
  }
});

test('missing, contradictory, attempted, and collection states cannot be recast as preparation cancellation', () => {
  const unsafe = [
    record({ version: 0 }),
    record({ version: Number.MAX_SAFE_INTEGER + 1 }),
    record({ status: 'queued' }),
    record({
      details: { stage: 'prepared', native_job_key: 'job_fixture_17' },
    }),
    record({ details: { stage: 'sending' } }),
    record({ details: { stage: 'prepared', attempt_started_at: 1 } }),
    record({ details: { stage: 'prepared', provider_id: 'provider-fixture' } }),
    record({ details: { stage: 'prepared', collection_claim: {} } }),
    record({
      details: { stage: 'prepared', collect_request_id: 'collect-17' },
    }),
    record({ details: { stage: 'prepared', fixed_result: {} } }),
  ];
  for (const r of unsafe) {
    assert.equal(api.canCancelTaskPreparation(r), false);
    assert.equal(api.canCancelTask(r), false);
    assert.throws(() => api.taskActionPayload(r, 'cancel', 'blocked-17'));
  }
  for (const action of ['retry', 'poll', 'observeUnknown', 'invented'])
    assert.throws(() => api.taskActionPayload(record(), action, 'blocked-17'));
  for (const revision of [0, -1, 1.5, Number.MAX_SAFE_INTEGER + 1]) {
    const r = nativeRecord();
    r.native_task.revision = revision;
    assert.throws(() => api.taskActionPayload(r, 'cancel', 'blocked-17'));
  }
  const conflicting = nativeRecord();
  conflicting.details.native_job_key = 'another-job';
  assert.throws(() =>
    api.taskActionPayload(conflicting, 'cancel', 'blocked-17')
  );
});

test('an unstarted dead task exposes cancellation with its real native revision after a final source fence denial', () => {
  for (const stage of ['prepared', 'blocked_source_authorization']) {
    const r = nativeRecord('dead', { stage });
    assert.equal(api.canCancelTask(r), true);
    assert.equal(api.canCancelTaskPreparation(r), false);
    assert.equal(
      api.taskActionPayload(r, 'cancel', 'dead-cancel-17').job_revision,
      9
    );
  }
  for (const details of [
    { stage: 'unknown' },
    { attempt_started_at: 1 },
    { provider_id: 'provider-fixture' },
    { fixed_result: {} },
  ])
    assert.equal(api.canCancelTask(nativeRecord('dead', details)), false);
});

test('the real original-request ledger restores a preparation cancellation unchanged after an uncertain attempt', async () => {
  const storage = memoryStorage();
  const first = store(storage);
  const payload = api.taskActionPayload(record(), 'cancel', 'recover-17');
  const original = await first.prepare('taskAction', 'task', payload);
  await first.phase(original, 'unknown');
  const restored = store(storage);
  const entries = await restored.list();
  assert.equal(entries.length, 1);
  assert.equal(entries[0].id, 'recover-17');
  assert.equal(entries[0].phase, 'unknown');
  assert.equal(entries[0].attempts, 1);
  assert.deepEqual(plain(await restored.payload(entries[0])), plain(payload));
  assert.equal(
    Object.hasOwn(await restored.payload(entries[0]), 'job_revision'),
    false
  );
});

test('original UUID, actor scope, action and business version remain bound during recovery', async () => {
  const storage = memoryStorage();
  const ledger = store(storage);
  const payload = api.taskActionPayload(record(), 'cancel', 'immutable-17');
  const original = await ledger.prepare('taskAction', 'task', payload);
  await assert.rejects(
    ledger.prepare('taskAction', 'task', { ...payload, version: 32 }),
    /AI_PENDING_REQUEST_CONFLICT/
  );
  await assert.rejects(
    ledger.prepare('taskAction', 'task', {
      id: 17,
      action: 'cancel',
      job_revision: 9,
      request_id: 'immutable-17',
    }),
    /AI_PENDING_REQUEST_CONFLICT/
  );
  await assert.rejects(
    ledger.prepare('taskAction', 'task', {
      ...payload,
      request_id: 'replacement-17',
    }),
    /UUID/
  );
  const changed = plain(original);
  changed.payload.version = 32;
  await assert.rejects(ledger.payload(changed), /AI_PENDING_ORIGINAL_CHANGED/);
  const otherActor = new OriginalRequestStore(
    storage,
    { session: 'synthetic-session', tenantId: 11, memberId: 8 },
    { run: async (_key, work) => work() }
  );
  await assert.rejects(
    otherActor.payload(original),
    /AI_PENDING_REQUEST_MISSING/
  );
  assert.deepEqual(plain(await ledger.payload(original)), plain(payload));
});

test('native cancellation original requests retain their established payload and replay hash input', async () => {
  const storage = memoryStorage();
  const ledger = store(storage);
  const payload = api.taskActionPayload(
    nativeRecord(),
    'cancel',
    'native-recover-17'
  );
  const original = await ledger.prepare('taskAction', 'task', payload);
  const same = await ledger.prepare('taskAction', 'task', payload);
  assert.equal(same.digest, original.digest);
  assert.deepEqual(plain(await store(storage).payload(original)), {
    id: 17,
    action: 'cancel',
    job_revision: 9,
    request_id: 'native-recover-17',
  });
});

test('the actual Workspace compiles and retains management permissions and frozen original-request submission', () => {
  const filename = path.join(moduleRoot, 'Workspace.vue');
  const source = fs.readFileSync(filename, 'utf8');
  const parsed = parse(source, { filename });
  assert.deepEqual(parsed.errors, []);
  const script = compileScript(parsed.descriptor, {
    id: 'ai-source-action-regression',
  });
  const template = compileTemplate({
    source: parsed.descriptor.template.content,
    filename,
    id: 'ai-source-action-regression',
    compilerOptions: { bindingMetadata: script.bindings },
  });
  assert.deepEqual(template.errors, []);
  assert.match(
    parsed.descriptor.template.content,
    /permissions\.use\s*&&\s*permissions\.task_manage\s*&&\s*api\.canCancelTask\(selected\)/
  );
  assert.match(
    script.content,
    /api\.taskActionPayload\(r, name, intent\.requestId\)/
  );
  assert.match(
    script.content,
    /originals\.prepare\(endpoint, intent\.kind, data\)/
  );
  assert.match(script.content, /originals\.run\(record, true, secret\)/);
});
