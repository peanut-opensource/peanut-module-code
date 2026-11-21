import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import vm from 'node:vm';
import test from 'node:test';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const require = createRequire(import.meta.url);
const native = createRequire(resolve(root, 'uniapp/package.json'));
const ts = require(resolve(root, 'tools/quality/node_modules/typescript'));
const vue = native('vue');
const pinia = native('pinia');
const sfc = native('vue/compiler-sfc');
const read = (path) => readFileSync(resolve(root, path), 'utf8');
const deferred = () => {
  let resolve;
  let reject;
  const promise = new Promise((yes, no) => {
    resolve = yes;
    reject = no;
  });
  return { promise, resolve, reject };
};
const pageData = (name) => ({ id: 1, type: 1, name, data: [], meta: [] });
const homeData = (name) => ({ article: [], decorate: pageData(name) });

function harness(kind, overrides = {}) {
  const hooks = { show: [], hide: [], unload: [] };
  const navigation = [];
  const metadata = [];
  const warnings = [];
  const environment = {
    uni: {
      getStorageSync: () => '',
      setStorageSync() {},
      removeStorageSync() {},
      navigateTo: ({ url }) => navigation.push(url),
      setNavigationBarTitle: (value) => metadata.push(value),
    },
    console: {
      error: (...args) => warnings.push(args),
      warn: (...args) => warnings.push(args),
    },
  };
  function execute(source, bindings) {
    const exports = {};
    const output = ts.transpileModule(source, {
      compilerOptions: {
        target: ts.ScriptTarget.ES2022,
        module: ts.ModuleKind.CommonJS,
      },
    }).outputText;
    vm.runInNewContext(output, {
      ...environment,
      exports,
      Error,
      require(name) {
        assert.ok(
          Object.hasOwn(bindings, name),
          `Unexpected dependency: ${name}`
        );
        return bindings[name];
      },
    });
    return exports;
  }
  pinia.setActivePinia(pinia.createPinia());
  const user = execute(read('uniapp/src/store/user.ts'), { vue, pinia });
  const decoration = execute(read('uniapp/src/utils/decoration.ts'), {
    '@/utils/request': {
      http: { get: () => Promise.resolve(pageData('profile')) },
    },
  });
  const api = {
    getConfig: async () => ({ theme: null }),
    getIndexData: async () => homeData('home'),
    ...overrides,
  };
  const app = execute(read('uniapp/src/store/app.ts'), {
    vue,
    pinia,
    '@/api/index': api,
    '@/utils/decoration': decoration,
  });
  const userStore = user.useUserStore();
  userStore.login({ token: 'session-a', id: 1, nickname: 'account-a' });
  const path = `uniapp/src/pages/${kind}/${kind}.vue`;
  const { descriptor, errors } = sfc.parse(read(path), { filename: path });
  assert.deepEqual(errors, []);
  assert.equal(descriptor.scriptSetup.lang, 'ts');
  const names =
    kind === 'index'
      ? 'load: loadHome, data: decorate, articles, navigate: goNews'
      : 'load: loadProfile, data: decorate, navigate: goWallet';
  const source =
    descriptor.scriptSetup.content +
    `\nexport const observed = { ${names}, loading: typeof loading === 'undefined' ? null : loading, error: typeof error === 'undefined' ? null : error };`;
  const scope = vue.effectScope();
  const page = scope.run(() =>
    execute(source, {
      vue,
      '@dcloudio/uni-app': {
        onShow: (fn) => hooks.show.push(fn),
        onHide: (fn) => hooks.hide.push(fn),
        onUnload: (fn) => hooks.unload.push(fn),
      },
      '@/api/index': api,
      '@/api/user': {
        getUserCenter:
          overrides.getUserCenter ??
          (async () => ({ id: 1, nickname: 'current' })),
      },
      '@/store/user': user,
      '@/store/app': app,
      '@/components/DecorationTabbar.vue': {},
      '@/utils/decoration': {
        ...decoration,
        getMobileDecoration:
          overrides.getMobileDecoration ?? decoration.getMobileDecoration,
      },
    })
  ).observed;
  return {
    page,
    hooks,
    userStore,
    metadata,
    navigation,
    warnings,
    stop: () => scope.stop(),
  };
}

for (const event of ['hide', 'unload', 'scope']) {
  test(`home ignores completion after ${event}`, async () => {
    const pending = deferred();
    const started = deferred();
    const h = harness('index', {
      getIndexData: () => {
        started.resolve();
        return pending.promise;
      },
    });
    try {
      const work = h.page.load();
      await started.promise;
      if (event === 'scope') h.stop();
      else h.hooks[event].forEach((fn) => fn());
      pending.resolve(homeData('late'));
      await work;
      assert.equal(h.page.data.value, null);
      assert.equal(h.page.loading?.value, false);
    } finally {
      h.stop();
    }
  });
}

test('home keeps the newest response and ignores an older failure', async () => {
  const old = deferred();
  const fresh = deferred();
  let calls = 0;
  const firstStarted = deferred();
  const secondStarted = deferred();
  const h = harness('index', {
    getIndexData: () => {
      if (++calls === 1) {
        firstStarted.resolve();
        return old.promise;
      }
      secondStarted.resolve();
      return fresh.promise;
    },
  });
  try {
    const first = h.page.load();
    await firstStarted.promise;
    const second = h.page.load();
    await secondStarted.promise;
    fresh.resolve(homeData('new'));
    await second;
    old.reject(new Error('late upstream failure'));
    await first;
    assert.equal(h.page.data.value.name, 'new');
    assert.equal(h.page.error?.value, '');
    assert.equal(h.page.loading?.value, false);
  } finally {
    h.stop();
  }
});

test('home does not start its data request after hiding during config loading', async () => {
  const config = deferred();
  let calls = 0;
  const h = harness('index', {
    getConfig: () => config.promise,
    getIndexData: async () => {
      calls++;
      return homeData('late');
    },
  });
  try {
    const work = h.page.load();
    h.hooks.hide.forEach((fn) => fn());
    config.resolve({ theme: null });
    await work;
    assert.equal(calls, 0);
  } finally {
    h.stop();
  }
});

test('home exposes failure separately and clears it on a successful retry', async () => {
  let calls = 0;
  const h = harness('index', {
    getIndexData: async () => {
      if (++calls === 1) throw new Error('private detail');
      return homeData('retry');
    },
  });
  try {
    await h.page.load();
    assert.equal(typeof h.page.error?.value, 'string');
    assert.ok(h.page.error.value.length > 0);
    assert.doesNotMatch(h.page.error.value, /private detail/);
    await h.page.load();
    assert.equal(h.page.error.value, '');
    assert.equal(h.page.data.value.name, 'retry');
  } finally {
    h.stop();
  }
});

for (const change of ['logout', 'replacement', 'same-token-relogin']) {
  test(`profile rejects old account data after ${change}`, async () => {
    const center = deferred();
    const h = harness('user', { getUserCenter: () => center.promise });
    try {
      const work = h.page.load();
      h.userStore.logout();
      if (change !== 'logout')
        h.userStore.login({
          token: change === 'replacement' ? 'session-b' : 'session-a',
          id: 2,
          nickname: 'account-b',
        });
      center.resolve({ id: 1, nickname: 'stale-account-a' });
      await work;
      assert.notEqual(h.userStore.userInfo.nickname, 'stale-account-a');
      assert.equal(h.page.loading?.value, false);
    } finally {
      h.stop();
    }
  });
}

for (const event of ['hide', 'unload', 'scope']) {
  test(`profile rejects pending store and metadata writes after ${event}`, async () => {
    const center = deferred();
    const h = harness('user', { getUserCenter: () => center.promise });
    try {
      const work = h.page.load();
      if (event === 'scope') h.stop();
      else h.hooks[event].forEach((fn) => fn());
      center.resolve({ id: 1, nickname: 'late' });
      await work;
      assert.equal(h.userStore.userInfo.nickname, 'account-a');
      assert.equal(h.page.data.value, null);
    } finally {
      h.stop();
    }
  });
}

test('profile can load again on a later native show and keeps registered navigation', async () => {
  const h = harness('user');
  try {
    await h.hooks.show[0]();
    h.hooks.hide.forEach((fn) => fn());
    await h.hooks.show[0]();
    assert.equal(h.userStore.userInfo.nickname, 'current');
    h.page.navigate();
    assert.equal(h.navigation[0], '/packages/pages/user_wallet/user_wallet');
  } finally {
    h.stop();
  }
});

for (const kind of ['index', 'user']) {
  test(`${kind} template renders load/error/retry rather than only a success branch`, () => {
    const { descriptor } = sfc.parse(
      read(`uniapp/src/pages/${kind}/${kind}.vue`)
    );
    const template = descriptor.template.content;
    assert.match(template, /v-if="loading"/);
    assert.match(template, /v-else-if="error"/);
    assert.match(template, /role="alert"/);
    assert.match(
      template,
      kind === 'index' ? /@click="loadHome"/ : /@click="loadProfile"/
    );
  });
}
