<?php

declare(strict_types=1);

use app\adminapi\services\setting\HotSearchApplicationService as AdminHotSearchLogic;
use app\api\services\SearchApplicationService as ApiSearchLogic;
use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;
use PeanutAdmin\Kernel\Context\TenantSystemContext;

require_once __DIR__ . '/../Support/CiBootstrap.php';
require __DIR__ . '/../Support/IsolatedBackendEnvironment.php';
require_once __DIR__ . '/../Support/IdentityMysqlFixture.php';
require_once __DIR__ . '/../Support/ThinkPhpTestConnection.php';
require_once __DIR__ . '/../Support/RegisteredMysqlTestResource.php';

function expectHotSearchTenant(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function hotSearchTenantContext(int $tenantId, int $memberId, string $requestId): TenantContext
{
    return TenantContext::fromValidatedSession(new ValidatedTenantSession(
        $memberId,
        '01JMT03HOTSEARCH' . str_pad((string) $memberId, 11, '0', STR_PAD_LEFT),
        $tenantId,
        $memberId + 10000,
        $memberId,
        'admin-web',
        new DateTimeImmutable('2031-01-01T00:00:00Z'),
        1,
        1,
    ), $requestId);
}

function createHotSearchTenantSchema(PDO $pdo): void
{
    IdentityMysqlFixture::install($pdo, [
        dirname(__DIR__, 2) . '/app/modules/peanut/file/database/migrations',
        dirname(__DIR__, 2) . '/app/modules/peanut/settings/database/migrations',
        dirname(__DIR__, 2) . '/database/installation',
    ]);
}

function seedHotSearchTenantSchema(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
INSERT INTO pa_tenant (id,code,name,display_name,status,created_at,updated_at) VALUES (101,'alpha','Alpha','Alpha','active',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3)),(202,'beta','Beta','Beta','active',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3));
INSERT INTO pa_hot_search (id, tenant_id, name, sort) VALUES
  (11, 101, 'Alpha seed', 10),
  (21, 202, 'Same term', 30),
  (22, 202, 'Beta only', 20);
INSERT INTO pa_tenant_setting (tenant_id, namespace, config_json) VALUES
  (101, 'hot-search', JSON_OBJECT('status', 1)),
  (202, 'hot-search', JSON_OBJECT('status', 1));
SQL);
}

$host = IsolatedBackendEnvironment::required('DB_HOST');
$port = (int) IsolatedBackendEnvironment::required('DB_PORT');
$user = IsolatedBackendEnvironment::required('DB_USER');
$password = IsolatedBackendEnvironment::required('DB_PASS');
$runId = strtolower(bin2hex(random_bytes(5)));
$database = RegisteredMysqlTestResource::configuredDatabaseName();
[$pdo, $createdDatabase] = RegisteredMysqlTestResource::openEmptyDatabase($database);

try {
    IsolatedBackendEnvironment::activateDatabase($host, $port, $database, $user, $password, 'multi-tenant');
    $fixtureApp = new think\App(dirname(__DIR__, 2));
    $fixtureApp->initialize();
    ThinkPhpTestConnection::fromPdo($pdo);
    createHotSearchTenantSchema($pdo);
    seedHotSearchTenantSchema($pdo);
    IsolatedBackendEnvironment::activateDatabase($host, $port, $database, $user, $password, 'multi-tenant');
    $app = new think\App();
    $app->initialize();
    ThinkPhpTestConnection::fromPdo($pdo);

    $alpha = hotSearchTenantContext(101, 501, 'mt03-hot-search-alpha-' . $runId);
    $beta = hotSearchTenantContext(202, 502, 'mt03-hot-search-beta-' . $runId);
    $before = $pdo->query("SELECT id, name, sort FROM pa_hot_search WHERE tenant_id = 202 ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    $statusBefore = (string) $pdo->query("SELECT config_json FROM pa_tenant_setting WHERE tenant_id = 202 AND namespace = 'hot-search'")->fetchColumn();

    try {
        app(CurrentExecutionContext::class)->tenantAdmin();
        throw new RuntimeException('missing TenantContext unexpectedly reached hot-search write path');
    } catch (Throwable $exception) {
        expectHotSearchTenant($exception->getMessage() !== '', 'missing context denial lost its shape');
    }
    expectHotSearchTenant(
        $pdo->query("SELECT id, name, sort FROM pa_hot_search WHERE tenant_id = 202 ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) === $before,
        'missing context changed Beta terms',
    );
    expectHotSearchTenant(
        (string) $pdo->query("SELECT config_json FROM pa_tenant_setting WHERE tenant_id = 202 AND namespace = 'hot-search'")->fetchColumn() === $statusBefore,
        'missing context changed Beta status',
    );
    try {
        app(ApiSearchLogic::class)->hotLists();
        throw new RuntimeException('missing public TenantContext unexpectedly read hot-search terms');
    } catch (Throwable $exception) {
        expectHotSearchTenant($exception->getMessage() !== '', 'missing public context denial lost its shape');
    }

    try {
        app(ExecutionContextStore::class)->run(
            new \app\common\execution\AdminExecutionContext($alpha, 'test.hot-search.reject-owner'),
            fn() => app(AdminHotSearchLogic::class)->setConfig($alpha, ['status' => 0, 'data' => [['tenant_id' => 202, 'name' => 'Forged owner']]]),
        );
        throw new RuntimeException('protected hot-search tenant field was accepted');
    } catch (\app\common\exception\BusinessException $exception) {
        expectHotSearchTenant($exception->errorCode === 'HOT_SEARCH_INPUT_INVALID', 'protected field denial changed');
    }
    expectHotSearchTenant(
        app(ExecutionContextStore::class)->run(
            new \app\common\execution\AdminExecutionContext($alpha, 'test.hot-search.set.alpha'),
            fn() => app(AdminHotSearchLogic::class)->setConfig($alpha, [
                'status' => 0,
                'data' => [
                    ['name' => 'Same term', 'sort' => 90],
                    ['name' => 'Alpha only', 'sort' => 80],
                ],
            ]),
        ),
        'Alpha hot-search replacement failed',
    );
    expectHotSearchTenant(
        $pdo->query("SELECT id, name, sort FROM pa_hot_search WHERE tenant_id = 202 ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) === $before,
        'Alpha full replacement changed Beta terms',
    );
    expectHotSearchTenant((int) $pdo->query("SELECT COUNT(*) FROM pa_hot_search WHERE tenant_id = 101")->fetchColumn() === 2, 'Alpha replacement did not remain Tenant-scoped');
    expectHotSearchTenant((int) $pdo->query("SELECT COUNT(*) FROM pa_hot_search WHERE tenant_id = 202 AND name = 'Same term'")->fetchColumn() === 1, 'Beta same-name term was deleted');
    expectHotSearchTenant((int) $pdo->query("SELECT COUNT(*) FROM pa_hot_search WHERE tenant_id = 202 AND name = 'Alpha only'")->fetchColumn() === 0, 'payload forged hot-search owner');
    expectHotSearchTenant(
        (int) $pdo->query("SELECT JSON_UNQUOTE(JSON_EXTRACT(config_json, '$.status')) FROM pa_tenant_setting WHERE tenant_id = 101 AND namespace = 'hot-search'")->fetchColumn() === 0,
        'Alpha status was not updated',
    );
    expectHotSearchTenant(
        (int) $pdo->query("SELECT JSON_UNQUOTE(JSON_EXTRACT(config_json, '$.status')) FROM pa_tenant_setting WHERE tenant_id = 202 AND namespace = 'hot-search'")->fetchColumn() === 1,
        'Alpha status update changed Beta status',
    );

    $adminAlpha = app(ExecutionContextStore::class)->run(
        new \app\common\execution\AdminExecutionContext($alpha, 'test.hot-search.get.alpha'),
        fn() => app(AdminHotSearchLogic::class)->getConfig($alpha),
    );
    $adminBeta = app(ExecutionContextStore::class)->run(
        new \app\common\execution\AdminExecutionContext($beta, 'test.hot-search.get.beta'),
        fn() => app(AdminHotSearchLogic::class)->getConfig($beta),
    );
    expectHotSearchTenant(array_column($adminAlpha['data'], 'name') === ['Same term', 'Alpha only'], 'admin Alpha list leaked or lost terms');
    expectHotSearchTenant(array_column($adminBeta['data'], 'name') === ['Same term', 'Beta only'], 'admin Beta list leaked or lost terms');
    expectHotSearchTenant($adminAlpha['status'] === 0 && $adminBeta['status'] === 1, 'status is not Tenant-owned');

    $publicAlpha = new TenantSystemContext(101, 'peanut.hot-search.public-read', 'hot-search.lists', 'public-alpha-' . $runId);
    $publicBeta = new TenantSystemContext(202, 'peanut.hot-search.public-read', 'hot-search.lists', 'public-beta-' . $runId);
    $publicAlphaResult = app(ExecutionContextStore::class)->run(
        \app\common\execution\ConsumerExecutionContext::publicTenant($publicAlpha),
        fn() => app(ApiSearchLogic::class)->hotLists($publicAlpha),
    );
    $publicBetaResult = app(ExecutionContextStore::class)->run(
        \app\common\execution\ConsumerExecutionContext::publicTenant($publicBeta),
        fn() => app(ApiSearchLogic::class)->hotLists($publicBeta),
    );
    expectHotSearchTenant(array_column($publicAlphaResult['data'], 'name') === ['Same term', 'Alpha only'], 'public Alpha read crossed Tenant');
    expectHotSearchTenant(array_column($publicBetaResult['data'], 'name') === ['Same term', 'Beta only'], 'public Beta read crossed Tenant');
    expectHotSearchTenant($publicAlphaResult['status'] === 0 && $publicBetaResult['status'] === 1, 'public status crossed Tenant');
    try {
        app(ApiSearchLogic::class)->hotLists(new TenantSystemContext(101, 'untrusted.actor', 'hot-search.lists', 'forged-' . $runId));
        throw new RuntimeException('untrusted public context unexpectedly read hot-search terms');
    } catch (Throwable $exception) {
        expectHotSearchTenant($exception->getMessage() !== '', 'untrusted public denial lost its shape');
    }
} finally {
    RegisteredMysqlTestResource::cleanup($pdo, $database, $createdDatabase);
}

echo "MT03-HOT-SEARCH-TENANT-ISOLATION-001 passed\n";
