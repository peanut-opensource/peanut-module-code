<?php

declare(strict_types=1);

use PeanutAdmin\Modules\Article\Contract\ArticleAdministration;
use PeanutAdmin\Modules\Article\Contract\ArticleCategoryAdministration;
use PeanutAdmin\Modules\Article\Contract\ArticleQueries;
use PeanutAdmin\Modules\Article\Contract\PublicArticleQueries;
use PeanutAdmin\Modules\Article\Model\Article;
use PeanutAdmin\Modules\Article\Model\ArticleCate;
use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use app\common\execution\AdminExecutionContext;
use app\common\contract\authorization\AdminAuthorizationQuery;
use app\common\dto\authorization\AdminAccessData;
use app\common\dto\authorization\AdminPrincipal;
use app\common\dto\authorization\PermissionDecision;
use app\common\services\decoration\DecorationSchemaService;
use PeanutAdmin\Kernel\Context\AuthenticatedMemberContext;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;

require_once __DIR__ . '/../Support/CiBootstrap.php';
require __DIR__ . '/../Support/IsolatedBackendEnvironment.php';
require_once __DIR__ . '/../Support/IdentityMysqlFixture.php';
require_once __DIR__ . '/../Support/ThinkPhpTestConnection.php';
require_once __DIR__ . '/../Support/RegisteredMysqlTestResource.php';

/** Only a registry-backed, lease-owned exact database may be used by either branch. */
function articleTestDatabase(): string
{
    $database = RegisteredMysqlTestResource::configuredDatabaseName();
    $requested = getenv('PEANUT_TEST_DATABASE');
    if ($requested !== false && $requested !== '' && !hash_equals($database, $requested)) {
        throw new RuntimeException('ARTICLE_TEST_DATABASE_INVALID');
    }
    return $database;
}

function expectArticleTenant(bool $condition, string $message): void
{
    $GLOBALS['articleTenantChecks'] = ($GLOBALS['articleTenantChecks'] ?? 0) + 1;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function tenantContext(int $tenantId, int $accountId, int $memberId, string $requestId): TenantContext
{
    return TenantContext::fromValidatedSession(new ValidatedTenantSession(
        $memberId,
        '01JMT02ARTICLE' . str_pad((string) $memberId, 13, '0', STR_PAD_LEFT),
        $tenantId,
        $accountId,
        $memberId,
        'admin-web',
        new DateTimeImmutable('2031-01-01T00:00:00Z'),
        1,
        1,
    ), $requestId);
}

function articleAdminExecution(TenantContext $context, string $operation): AdminExecutionContext
{
    return new AdminExecutionContext($context, $operation, [
        'id' => $context->memberId,
        'tenant_id' => $context->tenantId,
        'account_id' => $context->accountId,
        'authorization_revision' => $context->authorizationRevision,
    ]);
}

function deniedShape(callable $operation): array
{
    try {
        $operation();
    } catch (Throwable $exception) {
        return [
            property_exists($exception, 'errorCode') ? $exception->errorCode : null,
            property_exists($exception, 'httpStatus') ? $exception->httpStatus : null,
            $exception->getMessage(),
        ];
    }
    throw new RuntimeException('Article capability denial was expected.');
}

final class ArticleFixtureAuthorization implements AdminAuthorizationQuery
{
    public function principal(TenantContext $tenantContext): AdminPrincipal
    {
        return AdminPrincipal::fromArray([
            'id' => $tenantContext->memberId,
            'tenant_id' => $tenantContext->tenantId,
            'account_id' => $tenantContext->accountId,
            'authorization_revision' => $tenantContext->authorizationRevision,
        ]);
    }

    public function accessData(TenantContext $tenantContext, AdminPrincipal $admin): AdminAccessData
    {
        return new AdminAccessData([], []);
    }

    public function decide(?TenantContext $tenantContext, AdminPrincipal $admin, string $accessUri): PermissionDecision
    {
        return $tenantContext instanceof TenantContext
            ? PermissionDecision::allow($accessUri)
            : PermissionDecision::deny($accessUri, 'MISSING_CONTEXT');
    }

    public function decisions(TenantContext $tenantContext, array $accessUris): array
    {
        $admin = $this->principal($tenantContext);
        $decisions = [];
        foreach ($accessUris as $accessUri) {
            $decisions[$accessUri] = $this->decide($tenantContext, $admin, $accessUri);
        }
        return $decisions;
    }

    public function assignableMenuRecords(TenantContext $tenantContext): array
    {
        return [];
    }
}

function createArticleCollectMemberFkSchema(PDO $pdo): void
{
    IdentityMysqlFixture::install($pdo, [
        dirname(__DIR__, 2) . '/app/modules/peanut/file/database/migrations',
        dirname(__DIR__, 2) . '/app/modules/peanut/member/database/migrations',
        dirname(__DIR__, 2) . '/app/modules/peanut/document/database/migrations',
        dirname(__DIR__, 2) . '/app/modules/peanut/article/database/migrations',
    ]);
    $pdo->exec(<<<'SQL'
INSERT INTO pa_tenant (id,code,name,display_name,status,created_at,updated_at) VALUES
 (101,'alpha','Alpha','Alpha','active',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3)),
 (202,'beta','Beta','Beta','active',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3));
INSERT INTO pa_member (id,tenant_id) VALUES (501,101),(502,202);
INSERT INTO pa_article_cate (id,tenant_id,name) VALUES (11,101,'Alpha'),(12,202,'Beta');
INSERT INTO pa_article (id,tenant_id,cid) VALUES (21,101,11),(22,202,12);
SQL);
}

function expectArticleCollectConstraintFailure(PDO $pdo, string $sql, string $message): void
{
    try {
        $pdo->exec($sql);
    } catch (PDOException $exception) {
        expectArticleTenant($exception->getCode() === '23000', $message . ': unexpected SQLSTATE ' . $exception->getCode());
        return;
    }
    throw new RuntimeException($message . ': insert unexpectedly succeeded');
}

function runArticleCollectMemberFkGate(): void
{
    $database = articleTestDatabase();
    [$pdo, $createdDatabase] = RegisteredMysqlTestResource::openEmptyDatabase($database);
    try {
        createArticleCollectMemberFkSchema($pdo);
        $pdo->exec(
            'INSERT INTO pa_article_collect (tenant_id, member_id, article_id) VALUES (101, 501, 21)',
        );
        expectArticleCollectConstraintFailure(
            $pdo,
            'INSERT INTO pa_article_collect (tenant_id, member_id, article_id) VALUES (101, 502, 21)',
            'cross-Tenant member collection was not rejected',
        );
        expectArticleCollectConstraintFailure(
            $pdo,
            'INSERT INTO pa_article_collect (tenant_id, member_id, article_id) VALUES (101, 501, 22)',
            'cross-Tenant Article collection was not rejected',
        );
        expectArticleTenant(
            (int) $pdo->query(
                'SELECT COUNT(*) FROM pa_article_collect WHERE tenant_id=101 AND member_id=501 AND article_id=21',
            )->fetchColumn() === 1,
            'existing valid Article collection changed after migration',
        );

        echo "MT02-ARTICLE-COLLECT-MEMBER-TENANT-FK-001 passed\n";
    } finally {
        RegisteredMysqlTestResource::cleanup($pdo, $database, $createdDatabase);
    }
}

if (in_array('--collect-member-fk', $argv ?? [], true)) {
    runArticleCollectMemberFkGate();
    exit(0);
}

$serverRoot = dirname(__DIR__, 2);
foreach ([
    'app/common/execution/CurrentExecutionContext.php',
    'app/modules/peanut/article/src/Model/Article.php',
    'app/modules/peanut/article/src/Model/ArticleCate.php',
    'app/modules/peanut/article/src/Model/ArticleCollect.php',
    'app/modules/peanut/article/src/Controller/ArticleController.php',
    'app/modules/peanut/article/src/Controller/ArticleCateController.php',
    'app/modules/peanut/article/src/Service/ArticleAdministrationService.php',
    'app/modules/peanut/article/src/Service/ArticleCategoryAdministrationService.php',
    'app/modules/peanut/article/src/Contract/ArticleAdministration.php',
    'app/modules/peanut/article/src/Contract/ArticleCategoryAdministration.php',
    'app/modules/peanut/article/src/Validation/ArticleValidate.php',
    'app/modules/peanut/article/src/Validation/ArticleCateValidate.php',
    'app/adminapi/controller/decoration/DecorationPageController.php',
    'app/adminapi/controller/decoration/DecorationTabbarController.php',
    'app/adminapi/services/decoration/DecorationPageApplicationService.php',
    'app/adminapi/services/decoration/DecorationTabbarApplicationService.php',
    'app/common/services/decoration/DecorationSchemaService.php',
    'app/modules/peanut/article/src/Controller/ArticleController.php',
    'app/api/controller/IndexController.php',
    'app/api/controller/PcController.php',
    'app/modules/peanut/member/src/Controller/MemberSelfController.php',
    'app/modules/peanut/article/src/Service/PublicArticleService.php',
    'app/modules/peanut/article/src/Contract/PublicArticleQueries.php',
    'app/api/services/IndexApplicationService.php',
    'app/api/services/PcApplicationService.php',
    'app/modules/peanut/member/src/Service/UserApplicationService.php',
    'tests/Multitenancy/ArticleTenantIsolationTest.php',
] as $relativePath) {
    $command = escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($serverRoot . '/' . $relativePath);
    exec($command, $lintOutput, $lintExit);
    expectArticleTenant($lintExit === 0, 'PHP 8.3 lint failed: ' . $relativePath . ' ' . implode(' ', $lintOutput));
    $lintOutput = [];
}

$host = IsolatedBackendEnvironment::required('DB_HOST');
$port = (int) IsolatedBackendEnvironment::required('DB_PORT');
$user = IsolatedBackendEnvironment::required('DB_USER');
$password = IsolatedBackendEnvironment::required('DB_PASS');
$database = articleTestDatabase();
[$pdo, $createdDatabase] = RegisteredMysqlTestResource::openEmptyDatabase($database);

try {
    IsolatedBackendEnvironment::activateDatabase($host, $port, $database, $user, $password, 'multi-tenant');
    $fixtureApp = new think\App($serverRoot);
    $fixtureApp->initialize();
    ThinkPhpTestConnection::fromPdo($pdo);
    IdentityMysqlFixture::install($pdo, [
        $serverRoot . '/app/modules/peanut/file/database/migrations',
        $serverRoot . '/app/modules/peanut/member/database/migrations',
        $serverRoot . '/app/modules/peanut/document/database/migrations',
        $serverRoot . '/app/modules/peanut/article/database/migrations',
    ]);
    $pdo->exec(<<<'SQL'
INSERT INTO pa_tenant (id,code,name,display_name,status,created_at,updated_at) VALUES (101,'alpha','Alpha','Alpha','active',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3)),(202,'beta','Beta','Beta','active',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3));
INSERT INTO pa_member (id, tenant_id) VALUES (501, 101), (502, 202);
INSERT INTO pa_article_cate (id, tenant_id, name, sort, is_show) VALUES (11, 101, 'Alpha seed', 10, 1), (12, 202, 'Beta', 10, 1);
INSERT INTO pa_article (id, tenant_id, cid, title, is_show, click_actual) VALUES (21, 101, 11, 'Alpha seed article', 1, 0), (22, 202, 12, 'Beta visible', 1, 0);
INSERT INTO pa_article_collect (id, tenant_id, member_id, article_id, status) VALUES (31, 101, 501, 21, 1);
SQL);

    IsolatedBackendEnvironment::activateDatabase($host, $port, $database, $user, $password, 'multi-tenant');
    $app = new think\App();
    $app->initialize();
    ThinkPhpTestConnection::fromPdo($pdo);
    $app->instance(AdminAuthorizationQuery::class, new ArticleFixtureAuthorization());
    $articles = app(ArticleAdministration::class);
    $categories = app(ArticleCategoryAdministration::class);

    $alpha = tenantContext(101, 1001, 501, 'mt02-alpha');
    $beta = tenantContext(202, 2002, 502, 'mt02-beta');
    $alphaMember = new AuthenticatedMemberContext(101, 501, 'fixture-alpha-member', 'mt02-alpha-member');
    $missingRequest = new stdClass();
    try {
        app(CurrentExecutionContext::class)->tenantAdmin();
        throw new RuntimeException('missing TenantContext unexpectedly succeeded');
    } catch (Throwable $exception) {
        expectArticleTenant($exception->getMessage() !== '', 'missing context denial lost its shape');
    }

    $payload = ['tenant_id' => 202, 'name' => 'Alpha category', 'sort' => 20, 'is_show' => 1];
    expectArticleTenant(
        app(ExecutionContextStore::class)->run(
            articleAdminExecution($alpha, 'test.article.category.add'),
            fn() => $categories->add($alpha, $payload),
        ),
        'Alpha category was not created',
    );
    $alphaCategoryId = (int) app(ExecutionContextStore::class)->run(
        articleAdminExecution($alpha, 'test.article.category.query'),
        fn() => ArticleCate::where([])->where('name', 'Alpha category')->value('id'),
    );
    expectArticleTenant($alphaCategoryId > 0, 'Alpha category was not created');
    expectArticleTenant(
        (int) $pdo->query("SELECT tenant_id FROM pa_article_cate WHERE id = {$alphaCategoryId}")->fetchColumn() === 101,
        'payload tenant_id overrode trusted context',
    );
    expectArticleTenant(
        app(ExecutionContextStore::class)->run(
            articleAdminExecution($alpha, 'test.article.add'),
            fn() => $articles->add($alpha, [
                'tenant_id' => 202, 'cid' => $alphaCategoryId, 'title' => 'Alpha visible',
                'is_show' => 1, 'desc' => '', 'abstract' => '', 'content' => '',
            ]),
        ),
        'Alpha article was not created',
    );
    $alphaArticleId = (int) app(ExecutionContextStore::class)->run(
        articleAdminExecution($alpha, 'test.article.query'),
        fn() => Article::where([])->where('title', 'Alpha visible')->value('id'),
    );
    expectArticleTenant($alphaArticleId > 0, 'Alpha article was not created');

    app(ExecutionContextStore::class)->run(
        articleAdminExecution($alpha, 'test.article.delete'),
        fn() => $articles->delete($alpha, $alphaArticleId),
    );
    expectArticleTenant(
        app(ExecutionContextStore::class)->run(
            articleAdminExecution($alpha, 'test.article.default-hidden'),
            fn() => Article::where([])->where('id', $alphaArticleId)->findOrEmpty()->isEmpty(),
        ),
        'soft-deleted Article remained visible to the default query',
    );
    $recycled = app(ExecutionContextStore::class)->run(
        articleAdminExecution($alpha, 'test.article.recycle.list'),
        fn() => $articles->recycleLists($alpha, ['page_size' => 20]),
    );
    expectArticleTenant(
        in_array($alphaArticleId, array_map('intval', array_column($recycled->items, 'id')), true),
        'soft-deleted Article was missing from the authorized recycle list',
    );
    expectArticleTenant(
        app(ExecutionContextStore::class)->run(
            articleAdminExecution($beta, 'test.article.recycle.cross-tenant'),
            fn() => $articles->recycleDetail($beta, $alphaArticleId),
        ) === [],
        'recycle detail crossed the Tenant boundary',
    );
    $restored = app(ExecutionContextStore::class)->run(
        articleAdminExecution($alpha, 'test.article.restore'),
        fn() => $articles->restore($alpha, [$alphaArticleId]),
    );
    expectArticleTenant($restored['restored'] === [$alphaArticleId], 'Article restore result was not explicit');
    $repeatedRestore = app(ExecutionContextStore::class)->run(
        articleAdminExecution($alpha, 'test.article.restore.repeat'),
        fn() => $articles->restore($alpha, [$alphaArticleId]),
    );
    expectArticleTenant(
        $repeatedRestore['already_active'] === [$alphaArticleId],
        'repeated Article restore did not return the stable already-active result',
    );

    try {
        app(ExecutionContextStore::class)->run(
            articleAdminExecution($alpha, 'test.article.category.delete.in-use'),
            fn() => $categories->delete($alpha, $alphaCategoryId),
        );
        throw new RuntimeException('category with an active Article was deleted');
    } catch (\app\common\exception\BusinessException $exception) {
        expectArticleTenant(
            $exception->errorCode === 'ARTICLE_CATEGORY_IN_USE',
            'category relation conflict lost its stable error code',
        );
    }
    app(ExecutionContextStore::class)->run(
        articleAdminExecution($alpha, 'test.article.delete.for-category'),
        fn() => $articles->delete($alpha, $alphaArticleId),
    );
    app(ExecutionContextStore::class)->run(
        articleAdminExecution($alpha, 'test.article.category.delete'),
        fn() => $categories->delete($alpha, $alphaCategoryId),
    );
    expectArticleTenant(
        app(ExecutionContextStore::class)->run(
            articleAdminExecution($alpha, 'test.article.category.default-hidden'),
            fn() => ArticleCate::where([])->where('id', $alphaCategoryId)->findOrEmpty()->isEmpty(),
        ),
        'soft-deleted category remained visible to the default query',
    );
    $categoryRecycle = app(ExecutionContextStore::class)->run(
        articleAdminExecution($alpha, 'test.article.category.recycle.list'),
        fn() => $categories->recycleLists($alpha, ['page_size' => 20]),
    );
    expectArticleTenant(
        in_array($alphaCategoryId, array_map('intval', array_column($categoryRecycle->items, 'id')), true),
        'soft-deleted category was missing from the authorized recycle list',
    );
    expectArticleTenant(
        app(ExecutionContextStore::class)->run(
            articleAdminExecution($beta, 'test.article.category.recycle.cross-tenant'),
            fn() => $categories->recycleDetail($beta, $alphaCategoryId),
        ) === [],
        'category recycle detail crossed the Tenant boundary',
    );
    $blockedArticleRestore = app(ExecutionContextStore::class)->run(
        articleAdminExecution($alpha, 'test.article.restore.deleted-category'),
        fn() => $articles->restore($alpha, [$alphaArticleId]),
    );
    expectArticleTenant(
        ($blockedArticleRestore['failed'][0]['code'] ?? null) === 'ARTICLE_CATEGORY_UNAVAILABLE',
        'Article restore did not report its deleted-category conflict',
    );
    $categoryRestored = app(ExecutionContextStore::class)->run(
        articleAdminExecution($alpha, 'test.article.category.restore'),
        fn() => $categories->restore($alpha, [$alphaCategoryId]),
    );
    expectArticleTenant(
        $categoryRestored['restored'] === [$alphaCategoryId],
        'category restore result was not explicit',
    );
    $categoryRepeatedRestore = app(ExecutionContextStore::class)->run(
        articleAdminExecution($alpha, 'test.article.category.restore.repeat'),
        fn() => $categories->restore($alpha, [$alphaCategoryId]),
    );
    expectArticleTenant(
        $categoryRepeatedRestore['already_active'] === [$alphaCategoryId],
        'repeated category restore did not return the stable already-active result',
    );
    $articleRestoredAfterCategory = app(ExecutionContextStore::class)->run(
        articleAdminExecution($alpha, 'test.article.restore.after-category'),
        fn() => $articles->restore($alpha, [$alphaArticleId]),
    );
    expectArticleTenant(
        $articleRestoredAfterCategory['restored'] === [$alphaArticleId],
        'Article did not restore after its category became active again',
    );

    $beforeBeta = $pdo->query('SELECT title, click_actual FROM pa_article WHERE id = 22')->fetch(PDO::FETCH_ASSOC);
    $beforeCollects = (int) $pdo->query('SELECT COUNT(*) FROM pa_article_collect WHERE tenant_id = 202')->fetchColumn();
    expectArticleTenant(
        app(ExecutionContextStore::class)->run(
            articleAdminExecution($alpha, 'test.article.public-detail.cross-tenant'),
            fn() => app(PublicArticleQueries::class)->detail(22, 501),
        ) === [],
        'cross-tenant detail enumerated Beta Article',
    );
    expectArticleTenant(
        app(ExecutionContextStore::class)->run(
            articleAdminExecution($alpha, 'test.article.public-detail.missing'),
            fn() => app(PublicArticleQueries::class)->detail(999999, 501),
        ) === [],
        'missing detail denial shape changed',
    );

    foreach ([22, 999999] as $target) {
        try {
            app(ExecutionContextStore::class)->run(
                articleAdminExecution($alpha, 'test.article.edit.denied'),
                fn() => $articles->edit($alpha, [
                    'id' => $target,
                    'cid' => $alphaCategoryId,
                    'title' => 'denied-write',
                    'is_show' => 1,
                ]),
            );
            throw new RuntimeException('cross/missing Article edit unexpectedly succeeded');
        } catch (RuntimeException $exception) {
            expectArticleTenant($exception->getMessage() === '资讯不存在', 'cross-tenant edit enumerated the target');
        }
    }

    $crossCollectError = deniedShape(fn() => app(ExecutionContextStore::class)->run(
        \app\common\execution\ConsumerExecutionContext::member($alphaMember, 'test.article.collect.cross-tenant'),
        fn() => app(PublicArticleQueries::class)->add(22, 501),
    ));
    $missingCollectError = deniedShape(fn() => app(ExecutionContextStore::class)->run(
        \app\common\execution\ConsumerExecutionContext::member($alphaMember, 'test.article.collect.missing'),
        fn() => app(PublicArticleQueries::class)->add(999999, 501),
    ));
    expectArticleTenant($missingCollectError === $crossCollectError, 'cross-tenant collection enumerated the target');
    expectArticleTenant($crossCollectError[0] === 'ARTICLE_NOT_FOUND', 'collection denial code changed');

    $link = static fn(int $id): array => ['target_type' => 'article', 'target' => $id];
    foreach ([22, 999999] as $target) {
        try {
            app(ExecutionContextStore::class)->run(
                articleAdminExecution($alpha, 'test.article.decoration-link.denied'),
                fn() => DecorationSchemaService::validateLink($alpha, $link($target), false, app(ArticleQueries::class)),
            );
            throw new RuntimeException('invalid decoration Article unexpectedly succeeded');
        } catch (RuntimeException $exception) {
            expectArticleTenant($exception->getMessage() === '文章链接必须指向存在且可见的文章', 'decoration target enumerated Tenant ownership');
        }
    }

    app(ExecutionContextStore::class)->run(
        \app\common\execution\ConsumerExecutionContext::member($alphaMember, 'test.article.collect.add'),
        fn() => app(PublicArticleQueries::class)->add($alphaArticleId, 501),
    );
    // add() 的公开合同返回 void；核验真实持久化效果，不能把无返回值当作布尔成功。
    expectArticleTenant(
        (int) $pdo->query('SELECT COUNT(*) FROM pa_article_collect WHERE tenant_id = 101 AND member_id = 501 AND article_id = '
            . $alphaArticleId . ' AND status = 1 AND delete_time IS NULL')->fetchColumn() === 1,
        'Alpha collection was not persisted exactly once in the owning Tenant',
    );
    expectArticleTenant(
        app(ExecutionContextStore::class)->run(
            articleAdminExecution($alpha, 'test.article.public-detail.owned'),
            fn() => app(PublicArticleQueries::class)->detail($alphaArticleId, 501),
        )['collect'] === true,
        'Alpha Article detail/collection failed',
    );
    expectArticleTenant(
        count(app(ExecutionContextStore::class)->run(
            articleAdminExecution($alpha, 'test.article.public-list'),
            fn() => app(PublicArticleQueries::class)->lists(['page_size' => 20], 501),
        )->items) >= 1,
        'Alpha list lost visible Article',
    );
    expectArticleTenant(
        count(app(ExecutionContextStore::class)->run(
            articleAdminExecution($alpha, 'test.article.info-center'),
            fn() => app(PublicArticleQueries::class)->infoCenter(),
        )) >= 1,
        'Alpha info center lost categories',
    );
    expectArticleTenant(
        count(app(ExecutionContextStore::class)->run(
            articleAdminExecution($alpha, 'test.article.aggregate'),
            fn() => app(PublicArticleQueries::class)->limitArticles('new', 20),
        )) >= 1,
        'Alpha aggregate lost Article',
    );
    app(ExecutionContextStore::class)->run(
        articleAdminExecution($alpha, 'test.article.decoration-link.owned'),
        fn() => DecorationSchemaService::validateLink($alpha, $link($alphaArticleId), false, app(ArticleQueries::class)),
    );
    app(ExecutionContextStore::class)->run(
        \app\common\execution\ConsumerExecutionContext::member($alphaMember, 'test.article.collect.cancel'),
        fn() => app(PublicArticleQueries::class)->cancel($alphaArticleId, 501),
    );

    expectArticleTenant(
        $pdo->query('SELECT title, click_actual FROM pa_article WHERE id = 22')->fetch(PDO::FETCH_ASSOC) === $beforeBeta,
        'cross-tenant denial changed Beta Article',
    );
    expectArticleTenant(
        (int) $pdo->query('SELECT COUNT(*) FROM pa_article_collect WHERE tenant_id = 202')->fetchColumn() === $beforeCollects,
        'cross-tenant denial changed Beta collections',
    );

    echo json_encode([
        'status' => 'passed',
        'scope' => 'mt02-article-tenant-first',
        'schema' => 'fresh-canonical',
        'tenant_first_denials' => ['detail', 'edit', 'collect', 'decoration', 'typed_target'],
        'permission_policy_allowed' => true,
        'checks' => $GLOBALS['articleTenantChecks'] ?? 0,
        'beta_unchanged' => true,
    ], JSON_UNESCAPED_SLASHES) . PHP_EOL;
} finally {
    RegisteredMysqlTestResource::cleanup($pdo, $database, $createdDatabase);
}
