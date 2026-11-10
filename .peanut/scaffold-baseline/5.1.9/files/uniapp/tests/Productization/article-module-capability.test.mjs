import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const uniRoot = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const repoRoot = resolve(uniRoot, '..');
const read = (path) => readFileSync(resolve(repoRoot, path), 'utf8');

const routeSource = read('server/route/public_api.php');
const indexPage = read('uniapp/src/pages/index/index.vue');
const newsPage = read('uniapp/src/pages/news/news.vue');
const detailPage = read('uniapp/src/pages/news_detail/news_detail.vue');
const newsApi = read('uniapp/src/api/news.ts');

for (const endpoint of [
  'api/article/cate',
  'api/article/lists',
  'api/article/detail',
  'api/article/addCollect',
  'api/article/cancelCollect',
]) {
  assert.equal(
    newsApi.includes(`'${endpoint}'`),
    true,
    `UniApp Article API drifted: ${endpoint}`
  );
}
for (const route of [
  "Route::get('index/index'",
  "Route::get('article/cate'",
  "Route::get('article/lists'",
  "Route::get('article/detail'",
]) {
  assert.equal(
    routeSource.includes(route),
    true,
    `missing UniApp public Article route: ${route}`
  );
}
assert.equal(
  routeSource.match(
    /->middleware\(PublicTenantModuleMiddleware::class, 'peanut\.article\.public-read', 'official\.article'/g
  )?.length >= 7,
  true,
  'UniApp public Article routes are not Module guarded'
);

assert.equal(
  indexPage.includes('articles.value = []') &&
    indexPage.includes('decorate.value = null'),
  true,
  'disabled home Article data was not cleared'
);
assert.equal(
  newsPage.includes('categories.value = []') &&
    newsPage.includes('articles.value = []'),
  true,
  'disabled news Article data was not cleared'
);
assert.equal(
  detailPage.includes('article.value = null'),
  true,
  'disabled Article deep-link detail was not cleared'
);
assert.equal(
  indexPage.includes('/pages/news_detail/news_detail?id=${id}'),
  true,
  'UniApp Article deep-link is not explicit'
);

console.log('UNIAPP-ARTICLE-MODULE-CAPABILITY-001 passed');
