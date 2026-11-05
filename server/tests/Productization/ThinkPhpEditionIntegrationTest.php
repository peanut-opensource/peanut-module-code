<?php

declare(strict_types=1);

use PeanutAdmin\Modules\Article\Model\Article;
use PeanutAdmin\Modules\Article\Model\ArticleCate;
use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use app\common\infrastructure\module\ModuleExecutionBoundary;
use app\common\support\PaginationInput;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;
use PeanutAdmin\Kernel\Context\TenantSystemContext;
use PeanutAdmin\Kernel\Module\ModuleException;
use think\facade\Db;

require_once __DIR__ . '/../Support/CiBootstrap.php';
require __DIR__ . '/../Support/IsolatedBackendEnvironment.php';
require_once __DIR__ . '/../Support/RegisteredMysqlTestResource.php';
require_once __DIR__ . '/../Support/IdentityMysqlFixture.php';
require_once __DIR__ . '/../Support/ThinkPhpTestConnection.php';

function expectTpq52(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function tpq52TenantContext(int $tenantId, int $accountId, int $memberId): TenantContext
{
    return TenantContext::fromValidatedSession(new ValidatedTenantSession(
        $memberId,
        '01JTPQ52EDITION' . str_pad((string) $memberId, 11, '0', STR_PAD_LEFT),
        $tenantId,
        $accountId,
        $memberId,
        'admin-web',
        new DateTimeImmutable('2031-01-01T00:00:00Z'),
        1,
        1,
    ), 'tpq52-request-' . $tenantId);
}

function tpq52DatabaseName(string $edition, array $arguments): string
{
    $registered = RegisteredMysqlTestResource::configuredDatabaseName();
    foreach ($arguments as $argument) {
        if (str_starts_with((string) $argument, '--database=')
            && !hash_equals($registered, substr((string) $argument, strlen('--database=')))) {
            throw new RuntimeException('TPQ52_DATABASE_NAME_INVALID');
        }
    }
    return $registered;
}

function tpq52Activate(string $database, string $edition): void
{
    IsolatedBackendEnvironment::activate([
        'APP_ENV' => 'development',
        'APP_DEBUG' => 'true',
        'PEANUT_DEPLOYMENT_TARGET' => 'local-development',
        'PEANUT_DATABASE_CONSUMER' => 'host',
        'DEPLOYMENT_MODE' => $edition === 'multi_tenant' ? 'multi-tenant' : 'standalone',
        'DB_HOST' => IsolatedBackendEnvironment::required('DB_HOST'),
        'DB_PORT' => IsolatedBackendEnvironment::required('DB_PORT'),
        'DB_NAME' => $database,
        'DB_USER' => IsolatedBackendEnvironment::required('DB_USER'),
        'DB_PASS' => IsolatedBackendEnvironment::required('DB_PASS'),
        'DB_PREFIX' => 'pa_',
    ]);
}

function tpq52CreateMultiTenantSchema(PDO $pdo): void
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
INSERT INTO pa_plan (id,code,name,status,created_at,updated_at)
 VALUES (1,'article-fixture','Article fixture','active',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3));
INSERT INTO pa_plan_module (plan_id,module_key) VALUES (1,'peanut.article');
INSERT INTO pa_tenant_license (tenant_id,plan_id,status,effective_at,created_at,updated_at) VALUES
 (101,1,'active',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),UTC_TIMESTAMP(3)),
 (202,1,'suspended',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),UTC_TIMESTAMP(3));
INSERT INTO pa_article_cate (id,tenant_id,name,sort,is_show) VALUES
 (11,101,'Alpha',10,1),(12,202,'Beta',10,1);
INSERT INTO pa_article (id,tenant_id,cid,title,is_show) VALUES
 (21,101,11,'Alpha article',1),(22,202,12,'Beta article',1);
SQL);
    $manifest = app(\PeanutAdmin\Kernel\Module\CompiledModuleRegistry::class)->requireManifest('peanut.article');
    $installation = $pdo->prepare('INSERT INTO pa_module_installation (module_key,installed_version,manifest_schema_version,manifest_digest,status,created_at,updated_at) VALUES (?,?,?,?,?,UTC_TIMESTAMP(3),UTC_TIMESTAMP(3))');
    $installation->execute(['peanut.article', $manifest->data['version'], $manifest->data['schema_version'], $manifest->digest, 'active']);
}

function tpq52CreateStandaloneSchema(PDO $pdo): void
{
    tpq52CreateMultiTenantSchema($pdo);
}

/** @param list<string> $sql */
function tpq52RelevantSql(array $sql): array
{
    return array_values(array_filter(
        $sql,
        static fn(string $statement): bool => preg_match(
            '/^(?:SELECT|INSERT|UPDATE|DELETE)\s/i',
            ltrim($statement),
        ) === 1,
    ));
}

function tpq52RunMultiTenant(PDO $pdo, array &$sql): array
{
    $store = app(ExecutionContextStore::class);
    expectTpq52($store instanceof ExecutionContextStore, 'Execution context store is unavailable');
    $alpha = new \app\common\execution\AdminExecutionContext(tpq52TenantContext(101, 1001, 501), 'tpq52.alpha');
    $beta = new \app\common\execution\AdminExecutionContext(tpq52TenantContext(202, 2002, 502), 'tpq52.beta');

    $payloadRejected = false;
    try {
        $store->run($alpha, static fn() => (new ArticleCate([
            'tenant_id' => 202,
            'name' => 'Rejected cross-Tenant payload',
            'sort' => 20,
            'is_show' => 1,
        ]))->save());
    } catch (DomainException $exception) {
        $payloadRejected = $exception->getMessage() === 'TENANT_WRITE_OWNERSHIP_MISMATCH';
    }
    expectTpq52($payloadRejected, 'request payload overrode the trusted Alpha Tenant');

    $created = new ArticleCate([
        'name' => 'Alpha created',
        'sort' => 20,
        'is_show' => 1,
    ]);
    expectTpq52($store->run($alpha, static fn() => $created->save()), 'Alpha create failed');
    $createdId = (int) $created->getData('id');
    expectTpq52($createdId > 0, 'Alpha create did not return an id');
    expectTpq52(
        (int) $pdo->query("SELECT tenant_id FROM pa_article_cate WHERE id = {$createdId}")->fetchColumn() === 101,
        'trusted Alpha Tenant was not injected on create',
    );

    $alphaNames = $store->run(
        $alpha,
        static fn() => ArticleCate::alias('category')->order('category.id')->column('category.name'),
    );
    $betaNames = $store->run(
        $beta,
        static fn() => ArticleCate::alias('category')->order('category.id')->column('category.name'),
    );
    expectTpq52($alphaNames === ['Alpha', 'Alpha created'], 'Alpha read crossed Tenant boundary');
    expectTpq52($betaNames === ['Beta'], 'Beta read crossed Tenant boundary');

    expectTpq52(
        $store->run(
            $alpha,
            static fn() => ArticleCate::where('id', 12)->update(['name' => 'cross-update']),
        ) === 0,
        'Alpha updated the Beta category',
    );
    expectTpq52($store->run($alpha, static fn() => ArticleCate::destroy(12)), 'Alpha delete path failed');
    $betaDelete = $pdo->query(
        'SELECT delete_time FROM pa_article_cate WHERE id = 12',
    )->fetch(PDO::FETCH_ASSOC);
    expectTpq52(
        is_array($betaDelete) && $betaDelete['delete_time'] === null,
        'Alpha soft-deleted the Beta category',
    );

    $articles = $store->run(
        $alpha,
        static fn() => Article::with('cate')->order('id')->select(),
    );
    expectTpq52(count($articles) === 1, 'Alpha relation root crossed Tenant boundary');
    expectTpq52(
        (string) $articles[0]->title === 'Alpha article'
            && (string) $articles[0]->cate->name === 'Alpha',
        'Alpha relation resolved a category from another Tenant',
    );

    try {
        $pdo->exec(
            "INSERT INTO pa_article (tenant_id, cid, title, is_show) VALUES (101, 12, 'invalid relation', 1)",
        );
        throw new RuntimeException('cross-Tenant relation unexpectedly satisfied the composite foreign key');
    } catch (PDOException $exception) {
        expectTpq52($exception->getCode() === '23000', 'cross-Tenant relation failed for an unexpected reason');
    }

    $page = $store->run(
        $alpha,
        static fn() => PaginationInput::from(['page_no' => 1, 'page_size' => 1])
            ->result(ArticleCate::order('id')),
    );
    $pageData = $page->responseData();
    expectTpq52(
        $pageData['count'] === 2
            && $pageData['pageNo'] === 1
            && $pageData['pageSize'] === 1
            && count($pageData['lists']) === 1,
        'Tenant pagination envelope or count changed',
    );

    $boundary = app(ModuleExecutionBoundary::class);
    $store->run(
        new \app\common\execution\SystemExecutionContext(new TenantSystemContext(101, 'callback', 'tpq52.callback', 'alpha-callback')),
        static fn() => $boundary->assertExternalCallback('peanut.article'),
    );
    $disabled = false;
    try {
        $store->run(
            new \app\common\execution\SystemExecutionContext(new TenantSystemContext(202, 'callback', 'tpq52.callback', 'beta-callback')),
            static fn() => $boundary->assertExternalCallback('peanut.article'),
        );
    } catch (ModuleException $exception) {
        $disabled = $exception->errorCode === 'MODULE_TENANT_DISABLED';
    }
    expectTpq52($disabled, 'Beta callback bypassed its disabled Module boundary');

    try {
        $store->run($alpha, static function (): void {
            throw new RuntimeException('tpq52-worker-failure');
        });
    } catch (RuntimeException $exception) {
        expectTpq52($exception->getMessage() === 'tpq52-worker-failure', 'worker failure identity changed');
    }
    expectTpq52($store->isEmpty(), 'worker context leaked after failure');
    expectTpq52(
        $store->run($beta, static fn() => ArticleCate::count()) === 1 && $store->isEmpty(),
        'next worker reused the previous Tenant context',
    );

    expectTpq52(
        $store->run($alpha, static fn() => ArticleCate::destroy($createdId)),
        'Alpha soft-delete path failed',
    );
    expectTpq52(
        $pdo->query("SELECT delete_time FROM pa_article_cate WHERE id = {$createdId}")->fetchColumn() !== null,
        'Alpha soft-delete did not affect its own row',
    );
    expectTpq52(
        (string) $pdo->query('SELECT name FROM pa_article_cate WHERE id = 12')->fetchColumn() === 'Beta',
        'Beta data changed during Alpha CRUD',
    );

    $tenantSql = array_values(array_filter(
        tpq52RelevantSql($sql),
        static fn(string $statement): bool => str_contains($statement, 'pa_article'),
    ));
    expectTpq52($tenantSql !== [], 'no Article SQL was captured');
    foreach ($tenantSql as $statement) {
        if (str_starts_with(ltrim($statement), 'INSERT INTO `pa_article')) {
            continue;
        }
        expectTpq52(str_contains($statement, 'tenant_id'), 'Tenant SQL lost its scope: ' . $statement);
    }

    return [
        'alpha_visible_categories' => 2,
        'beta_visible_categories' => 1,
        'captured_sql' => count($tenantSql),
        'callback_boundary' => 'enabled-alpha-disabled-beta',
        'worker_context' => 'cleared',
    ];
}

function tpq52RunStandalone(PDO $pdo, array &$sql): array
{
    return tpq52RunMultiTenant($pdo, $sql);
}

$edition = in_array('--multi-tenant', $argv, true)
    ? 'multi_tenant'
    : (in_array('--standalone', $argv, true) ? 'standalone' : '');
if ($edition === '') {
    throw new RuntimeException('TPQ52_EDITION_REQUIRED');
}
$database = tpq52DatabaseName($edition, $argv);
[$pdo, $createdDatabase] = RegisteredMysqlTestResource::openEmptyDatabase($database);

$passed = false;
$failure = null;
$summary = null;
try {
    tpq52Activate($database, $edition);
    $app = new think\App(dirname(__DIR__, 2));
    $app->initialize();
    ThinkPhpTestConnection::fromPdo($pdo);
    $edition === 'multi_tenant'
        ? tpq52CreateMultiTenantSchema($pdo)
        : tpq52CreateStandaloneSchema($pdo);

    $app = new think\App();
    $app->initialize();
    ThinkPhpTestConnection::fromPdo($pdo);
    $sql = [];
    Db::listen(static function (string $statement) use (&$sql): void {
        $sql[] = $statement;
    });
    $result = $edition === 'multi_tenant'
        ? tpq52RunMultiTenant($pdo, $sql)
        : tpq52RunStandalone($pdo, $sql);

    $summary = [
        'gate' => 'TPQ52-EDITION-INTEGRATION',
        'edition' => str_replace('_', '-', $edition),
        'database_resource_id' => IsolatedBackendEnvironment::required('PEANUT_DATABASE_RESOURCE_ID'),
        'database' => $database,
        'result' => $result,
        'status' => 'passed',
    ];
    $passed = true;
} catch (Throwable $exception) {
    $failure = $exception;
} finally {
    try {
        RegisteredMysqlTestResource::cleanup($pdo, $database, $createdDatabase);
    } catch (Throwable $exception) {
        fwrite(STDERR, sprintf("TPQ52_CLEANUP_FAILED %s %s database=%s\n", $exception::class, $exception->getMessage(), $database));
        $failure ??= $exception;
        $passed = false;
    }
}

if (!$passed) {
    fwrite(STDERR, sprintf(
        "TPQ52_FAILED %s %s database=%s\n",
        $failure instanceof Throwable ? $failure::class : 'RuntimeException',
        $failure instanceof Throwable ? $failure->getMessage() : 'UNKNOWN',
        $database,
    ));
    exit(1);
}

echo json_encode(
    $summary,
    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
) . "\n";
