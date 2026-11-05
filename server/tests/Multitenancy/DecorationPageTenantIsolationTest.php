<?php

declare(strict_types=1);

use app\adminapi\services\decoration\DecorationPageApplicationService;
use app\common\enum\decoration\DecorationEnum;
use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use app\common\services\decoration\DecorationReadService;
use app\common\model\decoration\DecoratePage;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;
use PeanutAdmin\Kernel\Context\TenantSystemContext;

require_once __DIR__ . '/../Support/CiBootstrap.php';
require __DIR__ . '/../Support/IsolatedBackendEnvironment.php';
require_once __DIR__ . '/../Support/IdentityMysqlFixture.php';
require_once __DIR__ . '/../Support/ThinkPhpTestConnection.php';
require_once __DIR__ . '/../Support/RegisteredMysqlTestResource.php';

function expectDecorationTenant(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function decorationFailure(callable $operation): array
{
    try {
        $operation();
    } catch (Throwable $exception) {
        return [property_exists($exception, 'errorCode') ? $exception->errorCode : null, $exception->getMessage()];
    }
    throw new RuntimeException('expected decoration operation to fail');
}

function decorationTenantContext(int $tenantId, int $memberId, string $requestId): TenantContext
{
    return TenantContext::fromValidatedSession(new ValidatedTenantSession(
        $memberId,
        '01JMT02DECORATE' . str_pad((string) $memberId, 12, '0', STR_PAD_LEFT),
        $tenantId,
        $memberId + 10000,
        $memberId,
        'admin-web',
        new DateTimeImmutable('2031-01-01T00:00:00Z'),
        1,
        1,
    ), $requestId);
}

function decorationFreshSchema(PDO $pdo, string $serverRoot): void
{
    IdentityMysqlFixture::install($pdo, [
        $serverRoot . '/app/modules/peanut/file/database/migrations',
        $serverRoot . '/app/modules/peanut/settings/database/migrations',
        $serverRoot . '/app/modules/peanut/member/database/migrations',
        $serverRoot . '/app/modules/peanut/document/database/migrations',
        $serverRoot . '/app/modules/peanut/article/database/migrations',
        $serverRoot . '/database/installation',
    ]);
    $pdo->exec(<<<'SQL'
INSERT INTO pa_tenant
  (id, code, name, display_name, status, activated_at, created_at, updated_at)
VALUES (101, 'default', 'Alpha', 'Alpha', 'active', UTC_TIMESTAMP(3), UTC_TIMESTAMP(3), UTC_TIMESTAMP(3));
SQL);
    $pdo->exec("INSERT INTO pa_decorate_page (tenant_id, type, name, data, meta) VALUES (101, 4, 'Alpha PC', '[]', '[]')");
}

/** @return array<int,array<string,mixed>> */
function decorationPcPage(string $name, int $articleId): array
{
    return [[
        'title' => 'PC Banner',
        'name' => 'pc-banner',
        'content' => [
            'enabled' => 1,
            'data' => [[
                'image' => '',
                'name' => $name,
                'link' => ['target_type' => 'article', 'target' => $articleId],
            ]],
        ],
        'styles' => [
            'position' => 'absolute',
            'left' => '40px',
            'top' => '75px',
            'width' => '750px',
            'height' => '340px',
        ],
    ]];
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
    decorationFreshSchema($pdo, $serverRoot);
    $pdo->exec(<<<'SQL'
INSERT INTO pa_tenant
  (id, code, name, display_name, status, activated_at, created_at, updated_at)
VALUES
  (202, 'beta', 'Beta', 'Beta', 'active', UTC_TIMESTAMP(3), UTC_TIMESTAMP(3), UTC_TIMESTAMP(3));
INSERT INTO pa_article_cate (id, tenant_id, name, is_show) VALUES
  (11, 101, 'Alpha Category', 1),
  (12, 202, 'Beta Category', 1);
INSERT INTO pa_article (id, tenant_id, cid, title, is_show) VALUES
  (21, 101, 11, 'Alpha Article', 1),
  (22, 202, 12, 'Beta Article', 1);
UPDATE pa_decorate_page SET name = 'Alpha PC' WHERE tenant_id = 101 AND type = 4;
SQL);
    $alphaPageId = (int) $pdo->query(
        'SELECT id FROM pa_decorate_page WHERE tenant_id = 101 AND type = 4',
    )->fetchColumn();
    $betaData = json_encode(decorationPcPage('Beta original', 22), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $insertBeta = $pdo->prepare(
        'INSERT INTO pa_decorate_page (tenant_id, type, name, data, meta) VALUES (202, 4, ?, ?, ?)',
    );
    $insertBeta->execute(['Beta PC', $betaData, '[]']);
    $betaPageId = (int) $pdo->lastInsertId();

    expectDecorationTenant($alphaPageId > 0, 'fresh canonical Alpha PC page is missing');
    try {
        $pdo->prepare('INSERT INTO pa_decorate_page (tenant_id, type, name, data, meta) VALUES (202, 4, ?, ?, ?)')
            ->execute(['Duplicate Beta PC', $betaData, '[]']);
        throw new RuntimeException('same-Tenant decoration type duplicate unexpectedly succeeded');
    } catch (PDOException $exception) {
        expectDecorationTenant($exception->getCode() === '23000', 'decoration uniqueness failed unexpectedly');
    }
    try {
        $pdo->prepare('INSERT INTO pa_decorate_page (tenant_id, type, name, data, meta) VALUES (999, 5, ?, ?, ?)')
            ->execute(['Orphan page', $betaData, '[]']);
        throw new RuntimeException('orphan decoration page unexpectedly succeeded');
    } catch (PDOException $exception) {
        expectDecorationTenant($exception->getCode() === '23000', 'decoration Tenant foreign key failed unexpectedly');
    }

    IsolatedBackendEnvironment::activateDatabase($host, $port, $database, $user, $password, 'multi-tenant');
    $app = new think\App($serverRoot);
    $app->initialize();
    ThinkPhpTestConnection::fromPdo($pdo);
    $decorationReads = app(DecorationReadService::class);

    $alpha = decorationTenantContext(101, 501, 'fresh-decoration-alpha');
    $beta = decorationTenantContext(202, 502, 'fresh-decoration-beta');
    try {
        app(CurrentExecutionContext::class)->tenantAdmin();
        throw new RuntimeException('missing TenantContext unexpectedly reached decoration Runtime');
    } catch (Throwable $exception) {
        expectDecorationTenant($exception->getMessage() !== '', 'missing context denial lost its shape');
    }

    expectDecorationTenant(
        count(app(ExecutionContextStore::class)->run(
            new \app\common\execution\AdminExecutionContext($alpha, 'test.decoration.page.list.alpha'),
            fn() => app(DecorationPageApplicationService::class)->lists($alpha, [DecorationEnum::PC_HOME]),
        )) === 1,
        'Alpha page list crossed Tenant boundary',
    );
    expectDecorationTenant(
        count(app(ExecutionContextStore::class)->run(
            new \app\common\execution\AdminExecutionContext($beta, 'test.decoration.page.list.beta'),
            fn() => app(DecorationPageApplicationService::class)->lists($beta, [DecorationEnum::PC_HOME]),
        )) === 1,
        'Beta page list crossed Tenant boundary',
    );
    $detailDenied = decorationFailure(fn() => app(ExecutionContextStore::class)->run(
        new \app\common\execution\AdminExecutionContext($alpha, 'test.decoration.page.detail.cross-tenant'),
        fn() => app(DecorationPageApplicationService::class)->detail($alpha, $betaPageId, [DecorationEnum::PC_HOME]),
    ));
    $missingDetailDenied = decorationFailure(fn() => app(ExecutionContextStore::class)->run(
        new \app\common\execution\AdminExecutionContext($alpha, 'test.decoration.page.detail.missing'),
        fn() => app(DecorationPageApplicationService::class)->detail($alpha, 999999, [DecorationEnum::PC_HOME]),
    ));
    expectDecorationTenant($detailDenied === $missingDetailDenied, 'page detail enumerated Tenant ownership');
    expectDecorationTenant($detailDenied[0] === 'DECORATION_PAGE_NOT_FOUND', 'page detail denial code changed');
    expectDecorationTenant(
        app(ExecutionContextStore::class)->run(
            new \app\common\execution\AdminExecutionContext($alpha, 'test.decoration.page.type.alpha'),
            fn() => app(DecorationPageApplicationService::class)->detailByType($alpha, DecorationEnum::PC_HOME),
        )['name'] === 'Alpha PC',
        'Alpha type read selected the wrong Tenant page',
    );
    expectDecorationTenant(
        app(ExecutionContextStore::class)->run(
            new \app\common\execution\AdminExecutionContext($beta, 'test.decoration.page.type.beta'),
            fn() => app(DecorationPageApplicationService::class)->detailByType($beta, DecorationEnum::PC_HOME),
        )['name'] === 'Beta PC',
        'Beta type read selected the wrong Tenant page',
    );

    $betaBefore = $pdo->query("SELECT name, data, meta FROM pa_decorate_page WHERE id = {$betaPageId}")->fetch(PDO::FETCH_ASSOC);
    $saveDenied = decorationFailure(fn() => app(ExecutionContextStore::class)->run(
        new \app\common\execution\AdminExecutionContext($alpha, 'test.decoration.page.save.cross-tenant'),
        fn() => app(DecorationPageApplicationService::class)->save($alpha, [
            'id' => $betaPageId,
            'tenant_id' => 101,
            'type' => DecorationEnum::PC_HOME,
            'data' => decorationPcPage('Cross Tenant', 21),
            'meta' => [],
        ], [DecorationEnum::PC_HOME]),
    ));
    $missingSaveDenied = decorationFailure(fn() => app(ExecutionContextStore::class)->run(
        new \app\common\execution\AdminExecutionContext($alpha, 'test.decoration.page.save.missing'),
        fn() => app(DecorationPageApplicationService::class)->save($alpha, [
            'id' => 999999,
            'tenant_id' => 101,
            'type' => DecorationEnum::PC_HOME,
            'data' => decorationPcPage('Missing', 21),
            'meta' => [],
        ], [DecorationEnum::PC_HOME]),
    ));
    expectDecorationTenant($saveDenied === $missingSaveDenied, 'decoration save enumerated Tenant ownership');
    expectDecorationTenant($saveDenied[0] === 'DECORATION_PAGE_NOT_FOUND', 'decoration save denial code changed');

    $articleDenied = decorationFailure(fn() => app(ExecutionContextStore::class)->run(
        new \app\common\execution\AdminExecutionContext($alpha, 'test.decoration.page.save.cross-tenant-link'),
        fn() => app(DecorationPageApplicationService::class)->save($alpha, [
            'id' => $alphaPageId,
            'tenant_id' => 202,
            'type' => DecorationEnum::PC_HOME,
            'data' => decorationPcPage('Forged Article', 22),
            'meta' => [],
        ], [DecorationEnum::PC_HOME]),
    ));
    $missingArticleDenied = decorationFailure(fn() => app(ExecutionContextStore::class)->run(
        new \app\common\execution\AdminExecutionContext($alpha, 'test.decoration.page.save.missing-link'),
        fn() => app(DecorationPageApplicationService::class)->save($alpha, [
            'id' => $alphaPageId,
            'tenant_id' => 202,
            'type' => DecorationEnum::PC_HOME,
            'data' => decorationPcPage('Missing Article', 999999),
            'meta' => [],
        ], [DecorationEnum::PC_HOME]),
    ));
    expectDecorationTenant($articleDenied === $missingArticleDenied, 'Article link validation enumerated Tenant ownership');
    expectDecorationTenant($articleDenied[0] === 'DECORATION_PAGE_INVALID', 'Article link denial code changed');

    expectDecorationTenant(
        app(ExecutionContextStore::class)->run(
            new \app\common\execution\AdminExecutionContext($alpha, 'test.decoration.page.save.alpha'),
            fn() => app(DecorationPageApplicationService::class)->save($alpha, [
                'id' => $alphaPageId,
                'tenant_id' => 202,
                'type' => DecorationEnum::PC_HOME,
                'data' => decorationPcPage('Alpha saved', 21),
                'meta' => [],
            ], [DecorationEnum::PC_HOME]),
        ),
        'Alpha decoration save failed',
    );
    expectDecorationTenant(
        (int) $pdo->query("SELECT tenant_id FROM pa_decorate_page WHERE id = {$alphaPageId}")->fetchColumn() === 101,
        'payload tenant_id overrode trusted decoration owner',
    );
    expectDecorationTenant(
        $pdo->query("SELECT name, data, meta FROM pa_decorate_page WHERE id = {$betaPageId}")->fetch(PDO::FETCH_ASSOC) === $betaBefore,
        'cross-Tenant denials changed Beta decoration page',
    );

    $publicAlpha = new TenantSystemContext(
        101,
        'peanut.decoration.public-read',
        'decoration.pc-page',
        'fresh-decoration-public-alpha',
    );
    $publicBeta = new TenantSystemContext(
        202,
        'peanut.decoration.public-read',
        'decoration.pc-page',
        'fresh-decoration-public-beta',
    );
    expectDecorationTenant(
        app(ExecutionContextStore::class)->run(
            \app\common\execution\ConsumerExecutionContext::publicTenant($publicAlpha),
            fn() => $decorationReads->pageByType($publicAlpha, DecorationEnum::PC_HOME, 'decoration.pc-page'),
        )['name'] === 'Alpha PC',
        'public Alpha read selected another Tenant page',
    );
    expectDecorationTenant(
        app(ExecutionContextStore::class)->run(
            \app\common\execution\ConsumerExecutionContext::publicTenant($publicBeta),
            fn() => $decorationReads->pageByType($publicBeta, DecorationEnum::PC_HOME, 'decoration.pc-page'),
        )['name'] === 'Beta PC',
        'public Beta read selected another Tenant page',
    );
    try {
        DecoratePage::where([])->count();
        throw new RuntimeException('untrusted public decoration context unexpectedly succeeded');
    } catch (Throwable $exception) {
        expectDecorationTenant($exception->getMessage() !== '', 'untrusted context denial lost its shape');
    }

    echo "MT02-DECORATION-PAGE-TENANT-ISOLATION-001 passed\n";
} finally {
    RegisteredMysqlTestResource::cleanup($pdo, $database, $createdDatabase);
}
