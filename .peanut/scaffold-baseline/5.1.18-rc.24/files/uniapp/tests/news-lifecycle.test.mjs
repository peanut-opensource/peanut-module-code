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
const sfc = native('vue/compiler-sfc');
const source = readFileSync(
  resolve(root, 'uniapp/src/pages/news/news.vue'),
  'utf8'
);
const { descriptor, errors } = sfc.parse(source);
assert.deepEqual(errors, []);
const deferred = () => {
  let resolve;
  let reject;
  const promise = new Promise((yes, no) => {
    resolve = yes;
    reject = no;
  });
  return { promise, resolve, reject };
};
const page = (id) => ({ lists: [{ id, title: `article-${id}` }] });

function harness(api = {}) {
  const hooks = { show: [], hide: [], unload: [] };
  const navigations = [];
  const exports = {};
  const bindings = {
    vue,
    '@dcloudio/uni-app': {
      onShow: (fn) => hooks.show.push(fn),
      onHide: (fn) => hooks.hide.push(fn),
      onUnload: (fn) => hooks.unload.push(fn),
    },
    '@/api/news': {
      getArticleCate: async () => [{ id: 1, name: 'one', image: '', sort: 0 }],
      getArticleLists: async () => page(1),
      ...api,
    },
    '@/components/DecorationTabbar.vue': {},
  };
  const output = ts.transpileModule(
    descriptor.scriptSetup.content +
      `
export const observed = {
  categories, currentCateId, articles, loadCategories, loadArticles, switchCate, goDetail,
  loadingArticles: typeof loadingArticles === 'undefined' ? null : loadingArticles,
  articlesError: typeof articlesError === 'undefined' ? null : articlesError,
  categoriesError: typeof categoriesError === 'undefined' ? null : categoriesError,
};`,
    {
      compilerOptions: {
        target: ts.ScriptTarget.ES2022,
        module: ts.ModuleKind.CommonJS,
      },
    }
  ).outputText;
  const scope = vue.effectScope();
  scope.run(() =>
    vm.runInNewContext(output, {
      exports,
      Error,
      console: { error() {}, warn() {} },
      uni: { navigateTo: (value) => navigations.push(value.url) },
      require(name) {
        assert.ok(
          Object.hasOwn(bindings, name),
          `Unexpected dependency: ${name}`
        );
        return bindings[name];
      },
    })
  );
  return {
    state: exports.observed,
    hooks,
    navigations,
    stop: () => scope.stop(),
  };
}

for (const outcome of ['success', 'failure']) {
  test(`a late category ${outcome} cannot replace a newer article selection`, async () => {
    const old = deferred();
    const recent = deferred();
    const h = harness({
      getArticleLists: ({ cid }) => (cid === 1 ? old.promise : recent.promise),
    });
    try {
      h.state.currentCateId.value = 1;
      const first = h.state.loadArticles();
      h.state.currentCateId.value = 2;
      const second = h.state.loadArticles();
      recent.resolve(page(2));
      await second;
      if (outcome === 'success') old.resolve(page(1));
      else old.reject(new Error('late upstream error'));
      await first;
      assert.equal(h.state.articles.value[0].id, 2);
      assert.equal(h.state.articlesError?.value, '');
      assert.equal(h.state.loadingArticles?.value, false);
    } finally {
      h.stop();
    }
  });
}

for (const event of ['hide', 'unload', 'scope']) {
  test(`category and article responses are ignored after ${event}`, async () => {
    const categories = deferred();
    const articles = deferred();
    const h = harness({
      getArticleCate: () => categories.promise,
      getArticleLists: () => articles.promise,
    });
    try {
      const categoryWork = h.state.loadCategories();
      const articleWork = h.state.loadArticles();
      if (event === 'scope') h.stop();
      else h.hooks[event].forEach((fn) => fn());
      categories.resolve([{ id: 1, name: 'late', image: '', sort: 0 }]);
      articles.resolve(page(1));
      await Promise.all([categoryWork, articleWork]);
      assert.equal(h.state.categories.value.length, 0);
      assert.equal(h.state.articles.value.length, 0);
      assert.equal(h.state.loadingArticles?.value, false);
    } finally {
      h.stop();
    }
  });
}

test('native show reloads a cached page and does not launch articles after hide', async () => {
  const categories = deferred();
  let articleCalls = 0;
  const h = harness({
    getArticleCate: () => categories.promise,
    getArticleLists: async () => {
      articleCalls++;
      return page(1);
    },
  });
  try {
    assert.equal(h.hooks.show.length, 1);
    const work = h.hooks.show[0]();
    h.hooks.hide.forEach((fn) => fn());
    categories.resolve([]);
    await work;
    assert.equal(articleCalls, 0);
    await h.hooks.show[0]();
    assert.equal(articleCalls, 1);
    h.state.goDetail(8);
    assert.equal(h.navigations[0], '/pages/news_detail/news_detail?id=8');
  } finally {
    h.stop();
  }
});

test('selecting a category clears stale rows before the new request finishes', async () => {
  const next = deferred();
  const h = harness({ getArticleLists: () => next.promise });
  try {
    h.state.articles.value = [{ id: 1, title: 'old' }];
    const work = h.state.switchCate(2);
    assert.equal(h.state.currentCateId.value, 2);
    assert.equal(h.state.articles.value.length, 0);
    next.resolve(page(2));
    await work;
    assert.equal(h.state.articles.value[0].id, 2);
  } finally {
    h.stop();
  }
});

test('article failures stay visible and a successful retry clears the error', async () => {
  let calls = 0;
  const h = harness({
    getArticleLists: async () => {
      if (++calls === 1) throw new Error('private detail');
      return { lists: [] };
    },
  });
  try {
    await h.state.loadArticles();
    assert.ok(h.state.articlesError?.value.length > 0);
    assert.doesNotMatch(h.state.articlesError.value, /private detail/);
    await h.state.loadArticles();
    assert.equal(h.state.articlesError.value, '');
    assert.equal(h.state.articles.value.length, 0);
  } finally {
    h.stop();
  }
});

test('category failures do not masquerade as a successful empty category set', async () => {
  const h = harness({
    getArticleCate: async () => {
      throw new Error('private detail');
    },
  });
  try {
    await h.state.loadCategories();
    assert.ok(h.state.categoriesError?.value.length > 0);
    assert.doesNotMatch(h.state.categoriesError.value, /private detail/);
  } finally {
    h.stop();
  }
});

test('the actual template distinguishes loading, failure and an empty successful list', () => {
  assert.match(descriptor.template.content, /v-if="loadingArticles"/);
  assert.match(descriptor.template.content, /v-else-if="articlesError"/);
  assert.match(
    descriptor.template.content,
    /v-else-if="articles.length === 0"/
  );
  assert.match(descriptor.template.content, /@click="loadArticles"/);
  assert.match(descriptor.template.content, /@click="loadCategories"/);
});
