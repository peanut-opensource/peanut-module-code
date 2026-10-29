import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const pcRoot = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const repoRoot = resolve(pcRoot, '..');
const read = (path) => readFileSync(resolve(repoRoot, path), 'utf8');

const routeSource = read('server/route/public_api.php');
const pcIndex = read('pc/pages/index.vue');
const pcInformation = read('pc/pages/information/index.vue');
const pcCategory = read('pc/pages/information/[source].vue');
const pcCollection = read('pc/pages/user/collection.vue');
const pcArticleApi = read('pc/api/article.ts');

for (const route of [
  "Route::get('pc/index'",
  "Route::get('pc/infoCenter'",
  "Route::get('pc/articleDetail'",
  "Route::get('article/lists'",
  "Route::get('article/detail'",
]) {
  assert.equal(
    routeSource.includes(route),
    true,
    `missing PC Article route: ${route}`
  );
}
assert.equal(
  routeSource.match(
    /->middleware\(PublicTenantModuleMiddleware::class, 'peanut\.article\.public-read', 'official\.article'/g
  )?.length >= 7,
  true,
  'PC and public Article routes are not uniformly Module guarded'
);

for (const endpoint of [
  'api/pc/index',
  'api/article/cate',
  'api/article/lists',
  'api/article/collect',
]) {
  assert.equal(
    pcArticleApi.includes(`'${endpoint}'`),
    true,
    `PC Article API drifted: ${endpoint}`
  );
}
assert.equal(
  pcIndex.includes('getPcIndex'),
  true,
  'PC home bypassed the guarded aggregate API'
);
assert.equal(
  pcInformation.includes('getArticleCategories') &&
    pcInformation.includes('getArticles'),
  true,
  'PC information page bypassed guarded Article APIs'
);
assert.equal(
  pcCategory.includes('getArticleCategories') &&
    pcCategory.includes('getArticles'),
  true,
  'PC category deep-link bypassed guarded Article APIs'
);
assert.equal(
  pcCollection.includes('getArticleCollections'),
  true,
  'PC member collection bypassed the Article collection API'
);

// A disabled module must never leave server-fetched Article data rendered from stale state.
const homeProjection = pcIndex.match(
  /const articles = computed\(\s*\(\) => ([^\n]+)\s*\)/
)?.[1];
assert.ok(homeProjection, 'review the actual reactive home projection');
const projectHome = new Function('indexData', `return (${homeProjection});`);
assert.deepEqual(
  projectHome({ value: undefined }),
  [],
  'unavailable data must not retain Article rows'
);
assert.deepEqual(
  projectHome({ value: {} }),
  [],
  'missing Article capabilities must render no rows'
);
assert.deepEqual(projectHome({ value: { all: [{ id: 1 }] } }), [{ id: 1 }]);
assert.deepEqual(projectHome({ value: { article: [{ id: 2 }] } }), [{ id: 2 }]);
assert.equal(
  pcCollection.includes('data?.lists || []'),
  true,
  'PC member collection lacks an empty fail-closed Article fallback'
);

console.log('PC-ARTICLE-MODULE-CAPABILITY-001 passed');
