import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { runInNewContext } from 'node:vm';
import { fileURLToPath } from 'node:url';
import test from 'node:test';

const require = createRequire(new URL('../../../../package.json', import.meta.url));
const vue = require('vue');
const ts = require('typescript');
const compiler = require('vue/compiler-sfc');
const filename = fileURLToPath(new URL('../views/index.vue', import.meta.url));
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
  const requests = { dryRun: [], apply: [] };
  const messages = { success: [], error: [] };
  let confirmation = async () => 'confirmed';
  const module = { exports: {} };
  const body = script + '\nexports.controls={packageText,conflictPolicy,secretBindings,plan,canApply,planning,applying,inputGeneration,plannedGeneration,handleDryRun,handleApply};';
  const compiled = ts.transpileModule(body, {
    fileName: filename + '.ts',
    compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.CommonJS, esModuleInterop: true },
    reportDiagnostics: true,
  });
  assert.equal(compiled.diagnostics?.length ?? 0, 0);
  scope.run(() => runInNewContext(compiled.outputText, {
    module, exports: module.exports, Blob, URL, document: {},
    require(name) {
      if (name === 'vue') return vue;
      if (name === 'vue-i18n') return { useI18n: () => ({ t: (key) => key }) };
      if (name === 'element-plus') return {
        ElMessage: {
          success: (text) => messages.success.push(text),
          error: (text) => messages.error.push(text),
        },
        ElMessageBox: { confirm: (...args) => confirmation(...args) },
      };
      if (name === '../api') return {
        exportTenantConfiguration() { throw new Error('unrelated export'); },
        dryRunTenantConfiguration(payload) {
          const request = deferred();
          requests.dryRun.push({ payload, ...request });
          return request.promise;
        },
        applyTenantConfiguration(payload) {
          const request = deferred();
          requests.apply.push({ payload, ...request });
          return request.promise;
        },
      };
      throw new Error('Unsupported real dependency: ' + name);
    },
  }, { filename }));
  return {
    ui: module.exports.controls,
    requests,
    messages,
    setConfirmation(callback) { confirmation = callback; },
    dispose() { scope.stop(); },
  };
}
const plan = (checksum = 'checksum-01') => ({
  protocol: 'peanut.configuration-transfer',
  schema_version: 1,
  scope: 'tenant',
  checksum,
  dry_run: true,
  status: 'ready',
  can_apply: true,
  conflict_policy: 'abort',
  counts: { total: 1, create: 1, replace: 0, unchanged: 0, skip: 0, conflict: 0 },
  entries: [],
  conflicts: [],
  missing_secret_references: [],
});
async function reviewed(f, text = '{"entries":[]}') {
  f.ui.packageText.value = text;
  await vue.nextTick();
  const running = f.ui.handleDryRun();
  assert.equal(f.requests.dryRun.length, 1);
  f.requests.dryRun[0].resolve({ data: plan() });
  await running;
  assert.equal(f.ui.canApply.value, true);
}

test('successful dry run is revoked immediately when package or conflict policy changes', async () => {
  const f = fixture();
  try {
    await reviewed(f);
    f.ui.packageText.value = '{"entries":[{"key":"edited"}]}';
    assert.equal(f.ui.plan.value, null);
    assert.equal(f.ui.canApply.value, false);
    await f.ui.handleApply();
    assert.equal(f.requests.apply.length, 0);
    const fresh = f.ui.handleDryRun();
    f.requests.dryRun[1].resolve({ data: plan('second-checksum') });
    await fresh;
    assert.equal(f.ui.canApply.value, true);
    f.ui.conflictPolicy.value = 'overwrite';
    assert.equal(f.ui.canApply.value, false);
    assert.equal(f.ui.plan.value, null);
  } finally { f.dispose(); }
});

test('changing a secret binding revokes the reviewed plan without persisting secret text', async () => {
  const f = fixture();
  try {
    await reviewed(f, '{"entries":[{"value":{"$secret":{"reference":"provider-token"}}}]}');
    f.ui.secretBindings['provider-token'] = 'synthetic-credential-1';
    assert.equal(f.ui.canApply.value, false);
    assert.equal(f.ui.plan.value, null);
    assert.equal(f.requests.apply.length, 0);
  } finally { f.dispose(); }
});

test('stale dry-run response cannot authorize a newer package after the user edits input', async () => {
  const f = fixture();
  try {
    f.ui.packageText.value = '{"entries":[{"key":"old"}]}';
    const old = f.ui.handleDryRun();
    f.ui.packageText.value = '{"entries":[{"key":"new"}]}';
    f.requests.dryRun[0].resolve({ data: plan() });
    await old;
    assert.equal(f.ui.plan.value, null);
    assert.equal(f.ui.canApply.value, false);
    const next = f.ui.handleDryRun();
    assert.equal(f.requests.dryRun.length, 2);
    f.requests.dryRun[1].resolve({ data: plan('new-checksum') });
    await next;
    assert.equal(f.ui.plan.value.checksum, 'new-checksum');
  } finally { f.dispose(); }
});

test('confirmation cannot submit a different input revision and duplicate clicks stay single-flight', async () => {
  const f = fixture();
  const deferredConfirmation = deferred();
  f.setConfirmation(() => deferredConfirmation.promise);
  try {
    await reviewed(f);
    const awaiting = f.ui.handleApply();
    assert.equal(f.ui.applying.value, true);
    const duplicate = f.ui.handleApply();
    await duplicate;
    assert.equal(f.requests.apply.length, 0);
    f.ui.packageText.value = '{"entries":[{"key":"changed-while-confirming"}]}';
    deferredConfirmation.resolve('confirmed');
    await awaiting;
    assert.equal(f.ui.applying.value, false);
    assert.equal(f.requests.apply.length, 0);
  } finally { f.dispose(); }
});

test('successful apply uses the approved exact input and keeps applied receipt after erasing secret bindings', async () => {
  const f = fixture();
  try {
    const body = '{"entries":[{"value":{"$secret":{"reference":"provider-token"}}}]}';
    f.ui.packageText.value = body;
    await vue.nextTick();
    f.ui.secretBindings['provider-token'] = 'synthetic-credential-2';
    const preview = f.ui.handleDryRun();
    f.requests.dryRun[0].resolve({ data: plan() });
    await preview;
    const applying = f.ui.handleApply();
    await new Promise((resolve) => setImmediate(resolve));
    assert.equal(f.requests.apply.length, 1);
    assert.equal(f.requests.apply[0].payload.package, body);
    assert.equal(f.requests.apply[0].payload.secret_bindings['provider-token'], 'synthetic-credential-2');
    f.requests.apply[0].resolve({ data: { ...plan(), status: 'applied', can_apply: false } });
    await applying;
    assert.equal(f.ui.secretBindings['provider-token'], '');
    assert.equal(f.ui.plan.value.status, 'applied');
    assert.equal(f.ui.canApply.value, false);
    assert.equal(f.messages.success.length, 1);
  } finally { f.dispose(); }
});

test('unknown apply failure removes stale authorization and directs user to reconcile, never auto-retry', async () => {
  const f = fixture();
  try {
    await reviewed(f);
    const applying = f.ui.handleApply();
    await new Promise((resolve) => setImmediate(resolve));
    f.requests.apply[0].reject(new Error('synthetic network failure'));
    await applying;
    assert.equal(f.ui.plan.value, null);
    assert.equal(f.ui.canApply.value, false);
    assert.match(f.messages.error[0], /applyUnconfirmed/);
    await f.ui.handleApply();
    assert.equal(f.requests.apply.length, 1);
  } finally { f.dispose(); }
});

test('real configuration-transfer form gates apply on the current reviewed input', () => {
  const compiled = compiler.compileTemplate({ source: template, filename, id: 'configuration-preview' });
  assert.equal(compiled.errors.length, 0);
  assert.match(template, /:disabled="!canApply"/);
  assert.match(template, /@click="handleDryRun"/);
  assert.match(template, /@click="handleApply"/);
});
