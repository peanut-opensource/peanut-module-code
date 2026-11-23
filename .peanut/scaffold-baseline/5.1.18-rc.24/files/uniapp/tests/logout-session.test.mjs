import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { resolve } from 'node:path';
import vm from 'node:vm';
import test from 'node:test';
const root = resolve(import.meta.dirname, '../..');
const require = createRequire(import.meta.url);
const native = createRequire(resolve(root, 'uniapp/package.json'));
const ts = require(resolve(root, 'tools/quality/node_modules/typescript'));
const vue = native('vue');
const pinia = native('pinia');
const sfc = native('vue/compiler-sfc');
const read = (path) => readFileSync(resolve(root, path), 'utf8');

for (const token of ['different-token', 'same-token']) {
  test(`explicit UniApp logout preserves ${token} relogin while remote logout is pending`, async () => {
    let complete;
    let started;
    const beginning = new Promise((done) => {
      started = done;
    });
    const remote = new Promise((done) => {
      complete = done;
    });
    const navigation = [];
    const uni = {
      getStorageSync: () => '',
      setStorageSync() {},
      removeStorageSync() {},
      showModal: ({ success }) => success({ confirm: true }),
      reLaunch: ({ url }) => navigation.push(url),
    };
    function execute(source, bindings) {
      const exports = {};
      const output = ts.transpileModule(source, {
        compilerOptions: {
          module: ts.ModuleKind.CommonJS,
          target: ts.ScriptTarget.ES2022,
        },
      }).outputText;
      vm.runInNewContext(output, {
        uni,
        exports,
        require: (name) => {
          assert.ok(Object.hasOwn(bindings, name), name);
          return bindings[name];
        },
      });
      return exports;
    }
    pinia.setActivePinia(pinia.createPinia());
    const user = execute(read('uniapp/src/store/user.ts'), { vue, pinia });
    const store = user.useUserStore();
    store.login({ token: 'same-token', id: 1 });
    const { descriptor } = sfc.parse(
      read('uniapp/src/pages/user_set/user_set.vue')
    );
    const page = execute(
      descriptor.scriptSetup.content + '\nexport { handleLogout };',
      {
        vue,
        '@/store/user': user,
        '@/api/oauth': {
          bindWechatIdentity: () => {
            throw new Error('Unexpected OAuth call');
          },
        },
        '@/api/account': {
          logout: () => {
            started();
            return remote;
          },
        },
      }
    );
    const work = page.handleLogout();
    await beginning;
    store.login({ token, id: 2 });
    complete();
    await work;
    assert.equal(store.token, token);
    assert.equal(store.userInfo.id, 2);
    assert.deepEqual(navigation, []);
  });
}
