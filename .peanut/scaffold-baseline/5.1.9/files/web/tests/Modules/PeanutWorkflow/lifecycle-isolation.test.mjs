import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import test from 'node:test';

const root = path.resolve(
  path.dirname(fileURLToPath(import.meta.url)),
  '../../../..'
);
// An isolated source worktree may reuse separately installed *identical locked*
// dependencies without copying packages or changing the production runtime.
const dependencyRoot = process.env.PEANUT_TEST_DEPENDENCIES_ROOT
  ? path.resolve(process.env.PEANUT_TEST_DEPENDENCIES_ROOT)
  : root;
if (dependencyRoot !== root) {
  for (const file of ['web/pnpm-lock.yaml', 'tools/quality/pnpm-lock.yaml']) {
    assert.equal(
      fs.readFileSync(path.join(root, file), 'utf8'),
      fs.readFileSync(path.join(dependencyRoot, file), 'utf8'),
      'Test dependency lock must match the source worktree: ' + file
    );
  }
}
const webRequire = createRequire(path.join(dependencyRoot, 'web/package.json'));
const vue = webRequire('vue');
const compiler = webRequire('vue/compiler-sfc');
const ts = webRequire(
  path.join(dependencyRoot, 'tools/quality/node_modules/typescript')
);
const workflow = path.join(root, 'web/src/modules/peanut-workflow');

// Execute the current production scripts with real Vue effects/component hooks.
// Browser storage/events, tenant registration, host UI and network are explicit
// fixtures. These tests are not a real browser/HTTP/tenant qualification.
function events() {
  const listeners = new Map();
  return {
    addEventListener(type, listener) {
      if (!listeners.has(type)) listeners.set(type, new Set());
      listeners.get(type).add(listener);
    },
    removeEventListener(type, listener) {
      listeners.get(type)?.delete(listener);
    },
    emit(type, event) {
      for (const listener of [...(listeners.get(type) || [])]) listener(event);
    },
    count(type) {
      return listeners.get(type)?.size || 0;
    },
  };
}
function storage() {
  const values = new Map();
  return {
    getItem: (key) => values.get(key) ?? null,
    setItem: (key, value) => values.set(key, String(value)),
    removeItem: (key) => values.delete(key),
    clear: () => values.clear(),
  };
}
function deferred() {
  let resolve;
  let reject;
  const promise = new Promise((yes, no) => {
    resolve = yes;
    reject = no;
  });
  return { promise, resolve, reject };
}
function fixture() {
  const window = events();
  window.location = { origin: 'https://workflow.invalid' };
  const document = Object.assign(events(), { hidden: false });
  const localStorage = storage();
  const sessionStorage = storage();
  localStorage.setItem('token', 'synthetic-token');
  localStorage.setItem('peanut-admin-session', 'synthetic-session');
  const user = vue.reactive({ id: '1', tenantName: 'tenant-a' });
  const disposers = new Map();
  const timers = new Map();
  const cache = new Map();
  const calls = { disposed: 0, definitions: 0 };
  const api = {
    dispose() {
      calls.disposed += 1;
    },
    async inbox() {
      return { lists: [], count: 0 };
    },
    async definition() {
      calls.definitions += 1;
      throw new Error('unexpected definition call');
    },
  };
  const confirm = deferred();
  function load(file, expose = []) {
    if (cache.has(file)) return cache.get(file);
    let source = fs.readFileSync(file, 'utf8');
    if (file.endsWith('.vue')) {
      const parsed = compiler.parse(source, { filename: file });
      assert.deepEqual(parsed.errors, []);
      assert.ok(parsed.descriptor.scriptSetup);
      source = parsed.descriptor.scriptSetup.content;
      // Bind only the Vite environment value; do not replace application logic.
      source = source.replaceAll('import.meta.env.VITE_API_BASE_URL', "'/'");
      // Vite-only lazy module lookups are not evaluated by this VM unit fixture.
      source = source.replaceAll(
        /import\.meta\.glob\(\s*'\/src\/modules\/[^']*'\s*\)/g,
        '({})'
      );
    }
    const result = ts.transpileModule(source, {
      fileName: file.endsWith('.vue') ? `${file}.ts` : file,
      compilerOptions: {
        target: ts.ScriptTarget.ES2022,
        module: ts.ModuleKind.CommonJS,
        esModuleInterop: true,
      },
      reportDiagnostics: true,
    });
    assert.equal(result.diagnostics?.length || 0, 0);
    const exports = {};
    cache.set(file, exports);
    vm.runInNewContext(
      `${result.outputText}\nexports.__state = {${expose.join(',')}};`,
      {
        exports,
        require(name) {
          if (name === 'vue') return vue;
          if (name === '@/store') return { useUserStore: () => user };
          if (name === '@/utils/auth')
            return load(path.join(root, 'web/src/utils/auth.ts'));
          if (name === '@/hooks/permission')
            return { hasPermission: () => true };
          if (name === '@peanut-admin/vue')
            return {
              registerTenantDisposer(key, close) {
                assert.equal(disposers.has(key), false);
                disposers.set(key, close);
                return () => disposers.delete(key);
              },
            };
          if (name === 'vue-router')
            return {
              onBeforeRouteLeave() {},
              useRouter: () => ({ push: async () => {} }),
            };
          if (name === 'element-plus')
            return { ElMessageBox: { confirm: () => confirm.promise } };
          if (name.endsWith('/host-api'))
            return { createHostWorkflowApi: () => api };
          if (name === './business-forms')
            return { useWorkflowTaskForm: () => null };
          if (name.endsWith('.vue')) return {};
          if (name.startsWith('.'))
            return load(path.resolve(path.dirname(file), `${name}.ts`));
          assert.fail(`Unexpected production dependency: ${name}`);
        },
        window,
        document,
        localStorage,
        sessionStorage,
        location: window.location,
        crypto: globalThis.crypto,
        console,
        AbortController,
        DOMException,
        Request,
        Response,
        URL,
        fetch: globalThis.fetch,
        defineProps: () => ({}),
        defineEmits: () => () => {},
        setTimeout(callback) {
          const id = Symbol('timer');
          timers.set(id, callback);
          return id;
        },
        clearTimeout(id) {
          timers.delete(id);
        },
      },
      { filename: file }
    );
    return exports;
  }
  return {
    window,
    document,
    localStorage,
    sessionStorage,
    user,
    disposers,
    timers,
    calls,
    api,
    confirm,
    load,
  };
}
function mount(setup) {
  const node = () => ({ children: [], parent: null });
  const renderer = vue.createRenderer({
    createElement: node,
    createText: node,
    createComment: node,
    insert(child, parent) {
      child.parent = parent;
      parent.children.push(child);
    },
    remove(child) {
      if (child.parent)
        child.parent.children = child.parent.children.filter(
          (entry) => entry !== child
        );
    },
    setText() {},
    setElementText() {},
    patchProp() {},
    parentNode: (child) => child.parent,
    nextSibling: () => null,
  });
  let state;
  const app = renderer.createApp({
    setup() {
      state = setup();
      return () => null;
    },
  });
  app.mount(node());
  return { state, unmount: () => app.unmount() };
}
const lifecyclePath = path.join(workflow, 'runtime/lifecycle.ts');

test('identity change disposes synchronously and removes its subscriptions before unmount', () => {
  const f = fixture();
  let closed = 0;
  const app = mount(() =>
    f.load(lifecyclePath).useWorkflowLifecycle(() => {
      closed += 1;
    })
  );
  try {
    assert.equal(f.disposers.size, 1);
    assert.equal(f.window.count('storage'), 1);
    f.user.id = '2';
    assert.equal(
      closed,
      1,
      'identity invalidation must not wait for the next render tick'
    );
    assert.equal(f.disposers.size, 0);
    assert.equal(f.window.count('storage'), 0);
    assert.equal(app.state.valid(), false);
    app.state.close();
    assert.equal(closed, 1);
  } finally {
    app.unmount();
  }
  assert.equal(closed, 1);
});

test('closing one same-host page leaves the other page and its registration active', () => {
  const f = fixture();
  const closed = [0, 0];
  const first = mount(() =>
    f.load(lifecyclePath).useWorkflowLifecycle(() => {
      closed[0] += 1;
    })
  );
  const second = mount(() =>
    f.load(lifecyclePath).useWorkflowLifecycle(() => {
      closed[1] += 1;
    })
  );
  try {
    assert.equal(f.disposers.size, 2);
    first.state.close();
    assert.equal(f.disposers.size, 1);
    assert.equal(f.window.count('storage'), 1);
    assert.equal(second.state.valid(), true);
    assert.deepEqual(closed, [1, 0]);
  } finally {
    first.unmount();
    second.unmount();
  }
  assert.equal(f.disposers.size, 0);
  assert.deepEqual(closed, [1, 1]);
});

test('ordinary token refresh survives, while localStorage clear invalidates the original session', () => {
  const f = fixture();
  let closed = 0;
  const app = mount(() =>
    f.load(lifecyclePath).useWorkflowLifecycle(() => {
      closed += 1;
    })
  );
  const auth = f.load(path.join(root, 'web/src/utils/auth.ts'));
  try {
    auth.setToken('refreshed-same-session');
    f.window.emit('storage', { key: 'token', storageArea: f.localStorage });
    assert.equal(app.state.valid(), true);
    assert.equal(closed, 0);
    f.localStorage.clear();
    f.window.emit('storage', { key: null, storageArea: f.localStorage });
    assert.equal(
      closed,
      1,
      'cross-window storage clear must immediately retire the old page'
    );
    assert.equal(f.disposers.size, 0);
  } finally {
    app.unmount();
  }
});

test('unmount cleans subscriptions even when the owning page cleanup throws', () => {
  const f = fixture();
  const app = mount(() =>
    f.load(lifecyclePath).useWorkflowLifecycle(() => {
      throw new Error('cleanup failure');
    })
  );
  try {
    assert.throws(() => app.state.close(), /cleanup failure/);
    assert.equal(f.disposers.size, 0);
    assert.equal(f.window.count('storage'), 0);
    assert.equal(app.state.valid(), false);
  } finally {
    app.unmount();
  }
});

test('designer identity loss clears private state and a pending confirmation cannot start another load', async () => {
  const f = fixture();
  const exposed = [
    'moduleKey',
    'workflowKey',
    'changed',
    'revision',
    'error',
    'notice',
    'previewInitiator',
    'previewDepartment',
    'previewSubjectType',
    'previewSubjectKey',
    'previewSummary',
    'previewPeople',
    'canUndo',
    'canRedo',
    'busy',
    'conflict',
    'serverRevision',
    'load',
  ];
  const app = mount(
    () =>
      f.load(path.join(workflow, 'designer/WorkflowDesignerPage.vue'), exposed)
        .__state
  );
  const s = app.state;
  try {
    for (const key of [
      'moduleKey',
      'workflowKey',
      'error',
      'notice',
      'previewInitiator',
      'previewDepartment',
      'previewSubjectType',
      'previewSubjectKey',
      'previewSummary',
    ])
      s[key].value = `private-${key}`;
    s.previewPeople.value = ['private member'];
    s.revision.value = 7;
    s.serverRevision.value = 8;
    s.changed.value = true;
    s.canUndo.value = true;
    s.canRedo.value = true;
    s.conflict.value = true;
    const pending = s.load();
    f.user.tenantName = 'tenant-b';
    // valid() at a response boundary must not be the only cleanup opportunity.
    await vue.nextTick();
    f.confirm.resolve(true);
    await pending;
    assert.equal(f.calls.definitions, 0);
    for (const key of [
      'moduleKey',
      'workflowKey',
      'error',
      'notice',
      'previewInitiator',
      'previewDepartment',
      'previewSubjectType',
      'previewSubjectKey',
      'previewSummary',
    ])
      assert.equal(s[key].value, '', key);
    assert.equal(s.previewPeople.value.length, 0);
    for (const key of ['changed', 'canUndo', 'canRedo', 'busy', 'conflict'])
      assert.equal(s[key].value, false, key);
    assert.equal(s.revision.value, null);
    assert.equal(s.serverRevision.value, null);
    assert.equal(f.window.count('beforeunload'), 0);
    assert.equal(f.window.count('storage'), 0);
  } finally {
    app.unmount();
  }
});

test('runtime identity loss clears selection, dialogs, counters, timers and visibility subscription', async () => {
  const f = fixture();
  const exposed = [
    'count',
    'error',
    'notice',
    'action',
    'selectedTask',
    'comment',
    'dialog',
    'reassignTaskKey',
    'newAssignee',
    'loading',
    'submitting',
  ];
  const app = mount(
    () =>
      f.load(path.join(workflow, 'runtime/WorkflowRuntimePage.vue'), exposed)
        .__state
  );
  const s = app.state;
  try {
    await Promise.resolve();
    s.count.value = 19;
    for (const key of [
      'error',
      'notice',
      'action',
      'comment',
      'reassignTaskKey',
      'newAssignee',
    ])
      s[key].value = `private-${key}`;
    s.selectedTask.value = { work_item_key: 'private-task' };
    s.dialog.value = true;
    s.loading.value = true;
    s.submitting.value = true;
    f.user.id = '2';
    await vue.nextTick();
    assert.equal(s.count.value, 0);
    for (const key of [
      'error',
      'notice',
      'action',
      'comment',
      'reassignTaskKey',
      'newAssignee',
    ])
      assert.equal(s[key].value, '', key);
    assert.equal(s.selectedTask.value, null);
    for (const key of ['dialog', 'loading', 'submitting'])
      assert.equal(s[key].value, false, key);
    assert.equal(f.timers.size, 0);
    assert.equal(f.document.count('visibilitychange'), 0);
    assert.equal(f.window.count('storage'), 0);
  } finally {
    app.unmount();
  }
});

test('runtime ignores late action success for a different selected workflow', async () => {
  const f = fixture();
  const pendingAction = deferred();
  const old = {
    instance_key: 'instance-old',
    status: 'running',
    reassign_work_items: [],
    active_nodes: [],
  };
  const next = { ...old, instance_key: 'instance-new' };
  f.api.instanceAction = async () => pendingAction.promise;
  f.api.instance = async (key) => {
    assert.equal(key, next.instance_key);
    return next;
  };
  f.api.events = async () => [];
  const app = mount(
    () =>
      f.load(path.join(workflow, 'runtime/WorkflowRuntimePage.vue'), [
        'instance',
        'action',
        'comment',
        'dialog',
        'notice',
        'error',
        'selectedTask',
        'submit',
        'showInstance',
        'submitting',
      ]).__state
  );
  const s = app.state;
  try {
    s.instance.value = old;
    s.action.value = 'retry';
    s.comment.value = 'old private opinion';
    s.dialog.value = true;
    const submit = s.submit();
    await s.showInstance(next.instance_key);
    assert.equal(s.instance.value.instance_key, next.instance_key);
    assert.equal(s.dialog.value, false);
    assert.equal(s.comment.value, '');
    assert.equal(s.selectedTask.value, null);
    pendingAction.resolve({ ...old, status: 'completed' });
    await submit;
    assert.equal(s.instance.value.instance_key, next.instance_key);
    assert.equal(s.notice.value, '');
    assert.equal(s.error.value, '');
    assert.equal(s.submitting.value, false);
  } finally {
    app.unmount();
  }
});

test('runtime keeps a current action when the inbox refreshes in the same identity', async () => {
  const f = fixture();
  const pendingAction = deferred();
  const current = {
    instance_key: 'instance-current',
    status: 'running',
    reassign_work_items: [],
    active_nodes: [],
  };
  f.api.instanceAction = async () => pendingAction.promise;
  const app = mount(
    () =>
      f.load(path.join(workflow, 'runtime/WorkflowRuntimePage.vue'), [
        'instance',
        'action',
        'comment',
        'notice',
        'error',
        'load',
        'submit',
        'submitting',
      ]).__state
  );
  const s = app.state;
  try {
    s.instance.value = current;
    s.action.value = 'retry';
    s.comment.value = 'still a current opinion';
    const submit = s.submit();
    await s.load(); // Same identity and instance: only the inbox changed.
    f.document.hidden = true;
    f.document.emit('visibilitychange');
    f.document.hidden = false;
    f.document.emit('visibilitychange');
    pendingAction.resolve({ ...current, status: 'completed' });
    await submit;
    assert.equal(s.instance.value.instance_key, current.instance_key);
    assert.equal(s.instance.value.status, 'completed');
    assert.match(s.notice.value, /操作已记录/);
    assert.equal(s.error.value, '');
    assert.equal(s.submitting.value, false);
  } finally {
    app.unmount();
  }
});

test('runtime ignores late failed-action refresh after switching instances', async () => {
  const f = fixture();
  const pendingAction = deferred();
  const pendingOldRefresh = deferred();
  const refreshStarted = deferred();
  const old = {
    instance_key: 'instance-old',
    status: 'running',
    reassign_work_items: [],
    active_nodes: [],
  };
  const next = { ...old, instance_key: 'instance-new' };
  let refreshCalled = false;
  f.api.instanceAction = async () => pendingAction.promise;
  f.api.instance = async (key) => {
    if (key === old.instance_key) {
      refreshCalled = true;
      refreshStarted.resolve();
      return pendingOldRefresh.promise;
    }
    assert.equal(key, next.instance_key);
    return next;
  };
  f.api.events = async () => [];
  const app = mount(
    () =>
      f.load(path.join(workflow, 'runtime/WorkflowRuntimePage.vue'), [
        'instance',
        'action',
        'comment',
        'dialog',
        'error',
        'notice',
        'submit',
        'showInstance',
      ]).__state
  );
  const s = app.state;
  try {
    s.instance.value = old;
    s.action.value = 'retry';
    s.comment.value = 'must preserve error on original instance';
    const submit = s.submit();
    pendingAction.reject(new Error('conflict on old instance'));
    await refreshStarted.promise;
    assert.equal(refreshCalled, true);
    await s.showInstance(next.instance_key);
    pendingOldRefresh.resolve({ ...old, status: 'blocked' });
    await submit;
    assert.equal(s.instance.value.instance_key, next.instance_key);
    assert.equal(s.error.value, '');
    assert.equal(s.notice.value, '');
  } finally {
    app.unmount();
  }
});

for (const boundary of ['fetch', 'body']) {
  test(`late successful synthetic response cannot escape disposal at the ${boundary} boundary`, async () => {
    const f = fixture();
    const { createWorkflowTransport } = f.load(
      path.join(workflow, 'transport.ts')
    );
    const delayed = deferred();
    let request;
    let deliveredStatus = null;
    const transport = createWorkflowTransport({
      baseUrl: 'https://workflow.invalid',
      session: () => ({ generation: 1, token: 'synthetic-token' }),
      fetch: async (value) => {
        request = value;
        if (boundary === 'fetch') await delayed.promise;
        const response = new Response(
          JSON.stringify({ data: { private: 'old identity' }, meta: {} }),
          { status: 200 }
        );
        deliveredStatus = response.status;
        if (boundary === 'body')
          response.json = async () => {
            await delayed.promise;
            return { data: { private: 'old identity' }, meta: {} };
          };
        return response;
      },
    });
    const pending = transport.request('/instances/1', 'GET');
    await Promise.resolve();
    await Promise.resolve();
    transport.dispose();
    assert.equal(request.signal.aborted, true);
    delayed.resolve();
    await assert.rejects(pending, (error) => error.name === 'AbortError');
    assert.equal(
      deliveredStatus,
      200,
      'the fixture delivers success despite the abort, not a cancelled network promise'
    );
  });
}
