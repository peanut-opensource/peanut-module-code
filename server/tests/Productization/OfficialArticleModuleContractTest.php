<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/route/registry_source.php';

function officialArticleExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$serverRoot = dirname(__DIR__, 2);
$repoRoot = dirname($serverRoot);
$moduleRoot = $serverRoot . '/app/modules/peanut/article';
$manifest = json_decode(
    (string) file_get_contents($moduleRoot . '/module.json'),
    true,
    64,
    JSON_THROW_ON_ERROR,
);

officialArticleExpect(($manifest['key'] ?? null) === 'peanut.article', 'official Article Module key changed');
officialArticleExpect(($manifest['tenant']['enableable'] ?? null) === true, 'official Article is not Tenant enableable');
officialArticleExpect(
    ($manifest['tenant']['disable_behavior'] ?? null) === 'reject_new_operations',
    'official Article disable behavior changed',
);
officialArticleExpect(
    ($manifest['database']['owned_tables'] ?? null) === ['pa_article_cate', 'pa_article', 'pa_article_collect', 'pa_article_body_save'],
    'official Article table ownership changed',
);
officialArticleExpect(
    ($manifest['contracts']['exports'] ?? null) === [
        'PeanutAdmin\\Modules\\Article\\Contract\\ArticleAdministration',
        'PeanutAdmin\\Modules\\Article\\Contract\\ArticleBodySaveInput',
        'PeanutAdmin\\Modules\\Article\\Contract\\ArticleQueries',
        'PeanutAdmin\\Modules\\Article\\Contract\\PublicArticleQueries',
    ],
    'official Article public contracts did not converge to consumed contracts and body save input',
);
officialArticleExpect(
    ($manifest['backend']['migrations'] ?? null) === 'database/migrations'
        && ($manifest['backend']['setting_definitions'] ?? null) === 'resources/setting-definitions.json',
    'official Article manifest does not declare its migrations and setting definitions',
);
officialArticleExpect(
    json_decode((string) file_get_contents($moduleRoot . '/resources/setting-definitions.json'), true, 8, JSON_THROW_ON_ERROR) === [],
    'official Article setting definition catalog must be explicitly empty',
);

$migration = (string) file_get_contents($moduleRoot . '/database/migrations/20261102000009_initial_article_schema.php');
officialArticleExpect(str_contains($migration, 'extends Migrator'), 'Article must use the native PHP migration');
foreach ($manifest['database']['owned_tables'] as $table) {
    officialArticleExpect(str_contains($migration, 'CREATE TABLE `' . $table . '`'), 'Article owned table missing: ' . $table);
}

$permissions = json_decode(
    (string) file_get_contents($moduleRoot . '/resources/permissions.json'),
    true,
    64,
    JSON_THROW_ON_ERROR,
);
$permissionKeys = array_column($permissions, 'key');
foreach ([
    'peanut.article.category.list',
    'peanut.article.category.add',
    'peanut.article.list',
    'peanut.article.add',
    'peanut.article.delete',
] as $permission) {
    officialArticleExpect(in_array($permission, $permissionKeys, true), 'missing Article permission: ' . $permission);
}
officialArticleExpect(
    count(array_filter($permissionKeys, static fn(string $key): bool => str_starts_with($key, 'peanut.article.'))) === count($permissionKeys),
    'official Article permission escaped its Module-key namespace',
);

$routes = (string) file_get_contents($moduleRoot . '/route/app.php');
$hostRoutes = peanut_route_registry_source($serverRoot);
$legacyHostRoutes = implode('', array_map(
    static fn(string $file): string => (string) file_get_contents($serverRoot . '/route/' . $file),
    ['app.php', 'platform.php', 'tenant.php', 'admin.php', 'public_api.php'],
));
$publicMiddleware = (string) file_get_contents($serverRoot . '/app/api/middleware/PublicTenantModuleMiddleware.php');
$administration = (string) file_get_contents($moduleRoot . '/src/Service/ArticleAdministrationService.php');
$publicArticles = (string) file_get_contents($moduleRoot . '/src/Service/PublicArticleService.php');
$publicContract = (string) file_get_contents($moduleRoot . '/src/Contract/PublicArticleQueries.php');
$articlePersistence = $administration . $publicArticles
    . (string) file_get_contents($moduleRoot . '/src/Model/Article.php')
    . (string) file_get_contents($moduleRoot . '/src/Model/ArticleCate.php')
    . (string) file_get_contents($moduleRoot . '/src/Model/ArticleCollect.php');
$provider = (string) file_get_contents($moduleRoot . '/src/ModuleProvider.php');
$categoryController = (string) file_get_contents($moduleRoot . '/src/Controller/ArticleCateController.php');
officialArticleExpect(substr_count($routes, "Route::get('peanut.article.") === 12, 'Article GET route count changed');
officialArticleExpect(substr_count($routes, "Route::post('peanut.article.") === 15, 'Article POST route count changed');
officialArticleExpect(str_contains($routes, "[OfficialModuleMiddleware::class, ['peanut.article', 'http.admin']]"), 'Article admin routes lost their Module boundary');
officialArticleExpect(
    str_contains($categoryController, 'use CrudTrait;')
        && str_contains($categoryController, 'protected string $crudClass = ArticleCategoryAdministration::class;')
        && str_contains($categoryController, '@property-read ArticleCategoryAdministration $crud'),
    'Article category controller is not mapped to the declared category application use case',
);
officialArticleExpect(
    !str_contains($administration, 'class ArticleAdministrationService extends BaseLogic')
        && str_contains($administration, 'implements ArticleAdministration'),
    'Article administration did not converge on its application contract',
);
officialArticleExpect(
    !is_file($moduleRoot . '/src/Http/ArticleModuleMiddleware.php'),
    'Article-specific Module middleware was reintroduced',
);
officialArticleExpect(!str_contains($legacyHostRoutes, "Route::get('peanut.article."), 'Article Admin routes remain Host-owned');
officialArticleExpect(!str_contains($legacyHostRoutes, "Route::post('peanut.article."), 'Article Admin writes remain Host-owned');
$menus = json_decode((string) file_get_contents($moduleRoot . '/resources/menus.json'), true, 64, JSON_THROW_ON_ERROR);
foreach ($menus as $menu) {
    officialArticleExpect(in_array($menu['required_permission'], $permissionKeys, true), 'Article menu permission is not declared');
    officialArticleExpect(str_starts_with($menu['key'], 'peanut.article.'), 'Article menu owner changed');
}
$menuRepository = (string) file_get_contents($serverRoot . '/app/modules/peanut/identity/src/Menu/ThinkPhpMenuCatalogRepository.php');
officialArticleExpect(str_contains($menuRepository, 'activeTenantModules') && str_contains($menuRepository, '$this->entitlements->moduleKeys($tenantId)'), 'Menu module availability must use Identity tenant entitlements');

// Every public Article/PC entry must fail closed when the Tenant Module is disabled.
$publicRoutes = $hostRoutes;
foreach ([
    "Route::get('index/index'",
    "Route::get('article/cate'",
    "Route::get('article/lists'",
    "Route::get('article/detail'",
    "Route::get('pc/index'",
    "Route::get('pc/infoCenter'",
    "Route::get('pc/articleDetail'",
] as $entry) {
    officialArticleExpect(substr_count($publicRoutes, $entry) === 1, 'missing public Article entry: ' . $entry);
}
officialArticleExpect(
    substr_count($publicRoutes, "->middleware(PublicTenantModuleMiddleware::class, 'peanut.article.public-read', 'peanut.article'") === 7,
    'public Article and PC aggregation entries are not uniformly Module guarded',
);

$consumer = (string) file_get_contents($moduleRoot . '/src/Controller/ConsumerArticleController.php');
$landing = (string) file_get_contents($moduleRoot . '/src/Service/ArticleLandingService.php');
officialArticleExpect(str_contains($consumer, 'protected string $articlesClass = PublicArticleQueries::class;') && str_contains($landing, 'private PublicArticleQueries $articles'), 'Article consumer bypasses its public queries');
officialArticleExpect(str_contains($provider, 'PublicArticleQueries::class =>') && str_contains($publicArticles, 'implements PublicArticleQueries'), 'Public Article provider binding is missing');
officialArticleExpect(
    str_contains($publicMiddleware, "'文章模块当前不可用'")
        && str_contains($publicMiddleware, '\'error_code\' => $exception->errorCode'),
    'public Article disable refusal is not fail-closed with a stable error code',
);

officialArticleExpect(
    !str_contains($articlePersistence, 'ModuleProvider')
        && !str_contains($articlePersistence, 'assertAvailable')
        && !str_contains($articlePersistence, "where('tenant_id'")
        && !str_contains($articlePersistence, "['tenant_id' =>"),
    'Article persistence reintroduced per-query Module or Tenant enforcement',
);
officialArticleExpect(
    str_contains($publicMiddleware, '$this->entryBindings->system(')
        && str_contains($publicMiddleware, 'assertHttp($moduleKey, $operation)')
        && str_contains($publicRoutes, "'peanut.article.public-read', 'peanut.article'")
        && str_contains($publicMiddleware, 'ModuleExecutionBoundary'),
    'public Article entry is not Host-bound and Module guarded',
);

$contribution = (string) file_get_contents($repoRoot . '/web/src/modules/peanut-article/contribution.ts');
officialArticleExpect(
    substr_count($contribution, "tenantModuleKey: 'peanut.article'") === 3,
    'Article frontend contribution lost TenantModule route metadata',
);
officialArticleExpect(
    !is_file($repoRoot . '/web/src/router/routes/modules/article.ts')
        && str_contains($contribution, "@/modules/peanut-article/views/cate/index.vue")
        && str_contains($contribution, "@/modules/peanut-article/views/list/index.vue"),
    'Article frontend route escaped its Module subtree',
);

$moduleFiles = [
    'src/Controller/ArticleCateController.php',
    'src/Controller/ArticleController.php',
    'src/Service/ArticleAdministrationService.php',
    'src/Service/PublicArticleService.php',
    'src/Contract/ArticleAdministration.php',
    'src/Contract/PublicArticleQueries.php',
    'src/Validation/ArticleCateValidate.php',
    'src/Validation/ArticleValidate.php',
    'src/Model/Article.php',
    'src/Model/ArticleCate.php',
    'src/Model/ArticleCollect.php',
];
foreach ($moduleFiles as $relative) {
    officialArticleExpect(is_file($moduleRoot . '/' . $relative), 'Article business file is outside Module subtree: ' . $relative);
}
foreach ([
    '/app/adminapi/controller/article',
    '/app/adminapi/application/article',
    '/app/adminapi/validate/article',
    '/app/common/model/article',
] as $legacyDirectory) {
    officialArticleExpect(!is_dir($serverRoot . $legacyDirectory), 'legacy Article backend directory remains: ' . $legacyDirectory);
}
officialArticleExpect(
    is_file($repoRoot . '/web/src/modules/peanut-article/api.ts')
        && is_dir($repoRoot . '/web/src/modules/peanut-article/views')
        && !is_file($repoRoot . '/web/src/api/article.ts')
        && !is_dir($repoRoot . '/web/src/views/article'),
    'Article frontend business code did not move atomically into its Module subtree',
);

echo "OFFICIAL-ARTICLE-MODULE-001 passed\n";
