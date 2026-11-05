<?php

declare(strict_types=1);

use app\adminapi\services\decoration\DecorationTabbarApplicationService;
use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use app\common\services\decoration\DecorationReadService;
use app\common\model\decoration\DecorateTabbar;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;
use PeanutAdmin\Kernel\Context\TenantSystemContext;

require_once __DIR__ . '/../Support/CiBootstrap.php';
require __DIR__ . '/../Support/IsolatedBackendEnvironment.php';
require_once __DIR__ . '/../Support/IdentityMysqlFixture.php';
require_once __DIR__ . '/../Support/ThinkPhpTestConnection.php';
require_once __DIR__ . '/../Support/RegisteredMysqlTestResource.php';

function expectTabbarTenant(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function tabbarTenantContext(int $tenantId, int $memberId, string $requestId): TenantContext
{
    return TenantContext::fromValidatedSession(new ValidatedTenantSession(
        $memberId,
        '01JMT03TABBAR' . str_pad((string) $memberId, 13, '0', STR_PAD_LEFT),
        $tenantId,
        $memberId + 10000,
        $memberId,
        'admin-web',
        new DateTimeImmutable('2031-01-01T00:00:00Z'),
        1,
        1,
    ), $requestId);
}

function tabbarFreshSchema(PDO $pdo, string $serverRoot): void
{
    IdentityMysqlFixture::install($pdo, [
        $serverRoot . '/app/modules/peanut/file/database/migrations',
        $serverRoot . '/app/modules/peanut/settings/database/migrations',
        $serverRoot . '/database/installation',
    ]);
    $pdo->exec(<<<'SQL'
INSERT INTO pa_tenant
  (id, code, name, display_name, status, activated_at, created_at, updated_at)
VALUES (101, 'default', 'Alpha', 'Alpha', 'active', UTC_TIMESTAMP(3), UTC_TIMESTAMP(3), UTC_TIMESTAMP(3));
SQL);
    $pdo->exec(<<<'SQL'
INSERT INTO pa_decorate_tabbar (tenant_id, position, name, selected, unselected, link, is_show, create_time, update_time)
VALUES (101, 0, '首页', '', '', '{"target_type":"shop","target":"home"}', 1, 0, 0);
SQL);
}

/** @return list<array<string,mixed>> */
function tabbarItems(string $prefix): array
{
    return [
        [
            'name' => $prefix . ' Home', 'selected' => '', 'unselected' => '', 'is_show' => 1,
            'link' => ['target_type' => 'shop', 'target' => 'home'],
        ],
        [
            'name' => $prefix . ' News', 'selected' => '', 'unselected' => '', 'is_show' => 1,
            'link' => ['target_type' => 'shop', 'target' => 'news'],
        ],
    ];
}

$serverRoot = dirname(__DIR__, 2);
$host = IsolatedBackendEnvironment::required('DB_HOST');
$port = (int) IsolatedBackendEnvironment::required('DB_PORT');
$user = IsolatedBackendEnvironment::required('DB_USER');
$password = IsolatedBackendEnvironment::required('DB_PASS');
$database = RegisteredMysqlTestResource::configuredDatabaseName();
[$pdo, $createdDatabase] = RegisteredMysqlTestResource::openEmptyDatabase($database);

try {
    IsolatedBackendEnvironment::activateDatabase($host, $port, $database, $user, $password, 'multi-tenant');
    $fixtureApp = new think\App($serverRoot);
    $fixtureApp->initialize();
    ThinkPhpTestConnection::fromPdo($pdo);
    tabbarFreshSchema($pdo, $serverRoot);
    $pdo->exec(<<<'SQL'
INSERT INTO pa_tenant
  (id, code, name, display_name, status, activated_at, created_at, updated_at)
VALUES
  (202, 'beta', 'Beta', 'Beta', 'active', UTC_TIMESTAMP(3), UTC_TIMESTAMP(3), UTC_TIMESTAMP(3));
INSERT INTO pa_decorate_tabbar_setting (tenant_id, style, create_time, update_time) VALUES
  (202, '{"default_color":"#222222","selected_color":"#22AA44"}', 0, 0);
INSERT INTO pa_decorate_tabbar (tenant_id, position, name, selected, unselected, link, is_show) VALUES
  (202, 0, 'Beta Home', '', '', '{"target_type":"shop","target":"home"}', 1),
  (202, 1, 'Beta News', '', '', '{"target_type":"shop","target":"news"}', 1);
SQL);

    expectTabbarTenant(
        (int) $pdo->query('SELECT COUNT(*) FROM pa_decorate_tabbar WHERE tenant_id = 101')->fetchColumn() === 1,
        'current Home Tabbar fixture is missing',
    );
    try {
        $pdo->exec("INSERT INTO pa_decorate_tabbar (tenant_id, position, name, link) VALUES (202, 1, 'Duplicate', '{}')");
        throw new RuntimeException('same-Tenant position duplicate unexpectedly succeeded');
    } catch (PDOException $exception) {
        expectTabbarTenant($exception->getCode() === '23000', 'Tabbar position uniqueness failed unexpectedly');
    }
    try {
        $pdo->exec("INSERT INTO pa_decorate_tabbar (tenant_id, position, name, link) VALUES (999, 9, 'Orphan', '{}')");
        throw new RuntimeException('orphan Tabbar row unexpectedly succeeded');
    } catch (PDOException $exception) {
        expectTabbarTenant($exception->getCode() === '23000', 'Tabbar Tenant foreign key failed unexpectedly');
    }

    IsolatedBackendEnvironment::activateDatabase($host, $port, $database, $user, $password, 'multi-tenant');
    $app = new think\App($serverRoot);
    $app->initialize();
    ThinkPhpTestConnection::fromPdo($pdo);
    $decorationReads = app(DecorationReadService::class);
    $alpha = tabbarTenantContext(101, 11, 'fresh-tabbar-alpha');
    $beta = tabbarTenantContext(202, 22, 'fresh-tabbar-beta');
    expectTabbarTenant(
        app(ExecutionContextStore::class)->run(
            new \app\common\execution\AdminExecutionContext($alpha, 'test.decoration.tabbar.default-style.alpha'),
            fn() => app(DecorationTabbarApplicationService::class)->detail($alpha),
        )['style'] === ['default_color' => '#666666', 'selected_color' => '#2F80ED'],
        'fresh Tabbar read did not provide the current default style',
    );
    $alphaFirstId = (int) $pdo->query(
        'SELECT id FROM pa_decorate_tabbar WHERE tenant_id = 101 ORDER BY position, id LIMIT 1',
    )->fetchColumn();

    expectTabbarTenant(
        app(ExecutionContextStore::class)->run(
            new \app\common\execution\AdminExecutionContext($alpha, 'test.decoration.tabbar.detail.alpha'),
            fn() => app(DecorationTabbarApplicationService::class)->detail($alpha),
        )['list'][0]['name'] === '首页',
        'Alpha detail crossed Tenant boundary',
    );
    expectTabbarTenant(
        app(ExecutionContextStore::class)->run(
            new \app\common\execution\AdminExecutionContext($beta, 'test.decoration.tabbar.detail.beta'),
            fn() => app(DecorationTabbarApplicationService::class)->detail($beta),
        )['list'][0]['name'] === 'Beta Home',
        'Beta detail crossed Tenant boundary',
    );
    expectTabbarTenant(
        app(ExecutionContextStore::class)->run(
            new \app\common\execution\AdminExecutionContext($alpha, 'test.decoration.tabbar.read.alpha'),
            fn() => $decorationReads->tabbar($alpha, true),
        )['list'][0]['id'] === $alphaFirstId,
        'same order read selected another Tenant',
    );

    $betaBefore = $pdo->query('SELECT id, tenant_id, position, name, link, is_show FROM pa_decorate_tabbar WHERE tenant_id = 202 ORDER BY position')
        ->fetchAll(PDO::FETCH_ASSOC);
    expectTabbarTenant(
        app(ExecutionContextStore::class)->run(
            new \app\common\execution\AdminExecutionContext($alpha, 'test.decoration.tabbar.save.alpha'),
            fn() => app(DecorationTabbarApplicationService::class)->save(
                $alpha,
                ['default_color' => '#333333', 'selected_color' => '#CC5500'],
                tabbarItems('Saved Alpha'),
            ),
        ),
        'Alpha Tabbar save failed',
    );
    expectTabbarTenant(
        app(ExecutionContextStore::class)->run(
            new \app\common\execution\AdminExecutionContext($alpha, 'test.decoration.tabbar.detail.saved-alpha'),
            fn() => app(DecorationTabbarApplicationService::class)->detail($alpha),
        )['list'][0]['name'] === 'Saved Alpha Home',
        'Alpha Tabbar save did not persist',
    );
    expectTabbarTenant(
        $pdo->query('SELECT id, tenant_id, position, name, link, is_show FROM pa_decorate_tabbar WHERE tenant_id = 202 ORDER BY position')
            ->fetchAll(PDO::FETCH_ASSOC) === $betaBefore,
        'Alpha save changed Beta rows',
    );
    expectTabbarTenant(
        app(ExecutionContextStore::class)->run(
            new \app\common\execution\AdminExecutionContext($beta, 'test.decoration.tabbar.detail.saved-beta'),
            fn() => app(DecorationTabbarApplicationService::class)->detail($beta),
        )['style']['default_color'] === '#222222',
        'Alpha save changed Beta style',
    );

    $publicAlpha = new TenantSystemContext(
        101,
        'peanut.decoration.public-read',
        'decoration.config',
        'fresh-tabbar-public-alpha',
    );
    expectTabbarTenant(
        app(ExecutionContextStore::class)->run(
            \app\common\execution\ConsumerExecutionContext::publicTenant($publicAlpha),
            fn() => $decorationReads->tabbar($publicAlpha, true, 'decoration.config'),
        )['list'][0]['name'] === 'Saved Alpha Home',
        'trusted public Tabbar read selected another Tenant',
    );
    try {
        DecorateTabbar::where([])->count();
        throw new RuntimeException('untrusted public Tabbar context unexpectedly succeeded');
    } catch (Throwable $exception) {
        expectTabbarTenant($exception->getMessage() !== '', 'untrusted context denial lost shape');
    }
    try {
        app(CurrentExecutionContext::class)->tenantAdmin();
        throw new RuntimeException('missing TenantContext unexpectedly reached Tabbar Runtime');
    } catch (Throwable $exception) {
        expectTabbarTenant($exception->getMessage() !== '', 'missing context denial lost shape');
    }

    echo "MT03-DECORATION-TABBAR-TENANT-ISOLATION-001 passed\n";
} finally {
    RegisteredMysqlTestResource::cleanup($pdo, $database, $createdDatabase);
}
