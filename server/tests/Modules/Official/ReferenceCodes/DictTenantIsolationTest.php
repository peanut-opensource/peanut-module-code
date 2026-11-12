<?php

declare(strict_types=1);

use PeanutAdmin\Modules\ReferenceCodes\Service\DictDataApplicationService;
use PeanutAdmin\Modules\ReferenceCodes\Service\DictTypeApplicationService;
use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;

require dirname(__DIR__, 4) . '/vendor/autoload.php';
require_once dirname(__DIR__, 3) . '/Support/CiBootstrap.php';
require_once dirname(__DIR__, 3) . '/Support/RegisteredMysqlTestResource.php';
require_once dirname(__DIR__, 3) . '/Support/ThinkPhpTestConnection.php';
require_once dirname(__DIR__, 3) . '/Support/IdentityMysqlFixture.php';

function expectDictTenant(bool $condition, string $message): void
{
    $GLOBALS['dictTenantChecks'] = ($GLOBALS['dictTenantChecks'] ?? 0) + 1;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function dictFailure(callable $operation): array
{
    try {
        $operation();
    } catch (Throwable $exception) {
        return [property_exists($exception, 'errorCode') ? $exception->errorCode : null, $exception->getMessage()];
    }
    throw new RuntimeException('expected dictionary operation to fail');
}

function dictTenantContext(int $tenantId, int $memberId, string $requestId): TenantContext
{
    return TenantContext::fromValidatedSession(new ValidatedTenantSession(
        $memberId,
        '01JMT02DICT' . str_pad((string) $memberId, 16, '0', STR_PAD_LEFT),
        $tenantId,
        $memberId + 10000,
        $memberId,
        'admin-web',
        new DateTimeImmutable('2031-01-01T00:00:00Z'),
        1,
        1,
    ), $requestId);
}

function createDictTenantSchema(PDO $pdo): void
{
    IdentityMysqlFixture::install($pdo, [
        dirname(__DIR__, 4) . '/app/modules/peanut/reference_codes/database/migrations',
    ]);
}

function seedDictTenantSchema(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
INSERT INTO pa_tenant(id,code,name,display_name,status,activated_at,created_at,updated_at) VALUES
 (101,'alpha','Alpha','Alpha','active',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),UTC_TIMESTAMP(3)),
 (202,'beta','Beta','Beta','active',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),UTC_TIMESTAMP(3));
INSERT INTO pa_dict_type (id, tenant_id, name, type) VALUES
  (11, 101, 'Alpha', 'shared_key'),
  (12, 202, 'Beta', 'shared_key');
INSERT INTO pa_dict_data (id, tenant_id, name, value, type_id, type_value, sort) VALUES
  (21, 101, 'Alpha item', 'alpha', 11, 'shared_key', 10),
  (22, 202, 'Beta item', 'beta', 12, 'shared_key', 20);
SQL);
}

$database = RegisteredMysqlTestResource::configuredDatabaseName();
[$pdo, $databaseCreated] = RegisteredMysqlTestResource::openEmptyDatabase($database);

try {
    createDictTenantSchema($pdo);
    seedDictTenantSchema($pdo);
    try {
        $pdo->exec("INSERT INTO pa_dict_type (tenant_id, name, type) VALUES (202, 'Duplicate Beta', 'shared_key')");
        throw new RuntimeException('same-Tenant active dictionary type duplicate unexpectedly succeeded');
    } catch (PDOException $exception) {
        expectDictTenant($exception->getCode() === '23000', 'dictionary uniqueness failed with an unexpected shape');
    }

    $app = think\Container::getInstance();
    $app->env->set(['DEPLOYMENT_MODE' => 'multi-tenant', 'APP_ENV' => 'development', 'APP_DEBUG' => true]);
    $app->initialize();
    $app->cache->clear();
    ThinkPhpTestConnection::fromPdo($pdo);

    $alpha = dictTenantContext(101, 501, 'mt02-dict-alpha');
    $beta = dictTenantContext(202, 502, 'mt02-dict-beta');
    try {
        app(CurrentExecutionContext::class)->tenantAdmin();
        throw new RuntimeException('missing TenantContext unexpectedly reached dictionary Runtime');
    } catch (Throwable $exception) {
        expectDictTenant($exception->getMessage() !== '', 'missing context denial lost its shape');
    }

    expectDictTenant(
        app(ExecutionContextStore::class)->run(
            new \app\common\execution\AdminExecutionContext($alpha, 'test.dict.type.list.alpha'),
            fn() => app(DictTypeApplicationService::class)->lists($alpha, []),
        )->total === 1,
        'Alpha type list crossed Tenant boundary',
    );
    expectDictTenant(
        app(ExecutionContextStore::class)->run(
            new \app\common\execution\AdminExecutionContext($beta, 'test.dict.type.list.beta'),
            fn() => app(DictTypeApplicationService::class)->lists($beta, []),
        )->total === 1,
        'Beta type list crossed Tenant boundary',
    );
    expectDictTenant(
        app(ExecutionContextStore::class)->run(
            new \app\common\execution\AdminExecutionContext($alpha, 'test.dict.data.list.alpha'),
            fn() => app(DictDataApplicationService::class)->lists($alpha, []),
        )->total === 1,
        'Alpha data list crossed Tenant boundary',
    );
    expectDictTenant(
        app(ExecutionContextStore::class)->run(
            new \app\common\execution\AdminExecutionContext($beta, 'test.dict.data.list.beta'),
            fn() => app(DictDataApplicationService::class)->lists($beta, []),
        )->total === 1,
        'Beta data list crossed Tenant boundary',
    );
    expectDictTenant(
        app(ExecutionContextStore::class)->run(
            new \app\common\execution\AdminExecutionContext($alpha, 'test.dict.type.detail.cross-tenant'),
            fn() => app(DictTypeApplicationService::class)->detail($alpha, 12),
        ) === [],
        'cross-Tenant type detail was visible',
    );
    expectDictTenant(
        app(ExecutionContextStore::class)->run(
            new \app\common\execution\AdminExecutionContext($alpha, 'test.dict.data.detail.cross-tenant'),
            fn() => app(DictDataApplicationService::class)->detail($alpha, 22),
        ) === [],
        'cross-Tenant data detail was visible',
    );
    expectDictTenant(
        app(ExecutionContextStore::class)->run(
            new \app\common\execution\AdminExecutionContext($alpha, 'test.dict.type.detail.missing'),
            fn() => app(DictTypeApplicationService::class)->detail($alpha, 999999),
        ) === [],
        'missing type detail shape changed',
    );
    expectDictTenant(
        app(ExecutionContextStore::class)->run(
            new \app\common\execution\AdminExecutionContext($alpha, 'test.dict.data.detail.missing'),
            fn() => app(DictDataApplicationService::class)->detail($alpha, 999999),
        ) === [],
        'missing data detail shape changed',
    );

    expectDictTenant(
        app(ExecutionContextStore::class)->run(
            new \app\common\execution\AdminExecutionContext($alpha, 'test.dict.type.add'),
            fn() => app(DictTypeApplicationService::class)->add($alpha, [
                'tenant_id' => 202,
                'name' => 'Alpha status',
                'type' => 'status',
            ]),
        ),
        'Alpha dictionary type add failed',
    );
    $alphaTypeId = (int) $pdo->query("SELECT id FROM pa_dict_type WHERE tenant_id = 101 AND type = 'status' LIMIT 1")->fetchColumn();
    expectDictTenant($alphaTypeId > 0, 'Alpha type was not created');
    expectDictTenant(
        (int) $pdo->query("SELECT tenant_id FROM pa_dict_type WHERE id = {$alphaTypeId}")->fetchColumn() === 101,
        'payload tenant_id overrode trusted dictionary owner',
    );
    $crossParentDenied = dictFailure(fn() => app(ExecutionContextStore::class)->run(
        new \app\common\execution\AdminExecutionContext($alpha, 'test.dict.data.add.cross-tenant'),
        fn() => app(DictDataApplicationService::class)->add($alpha, [
            'tenant_id' => 202,
            'type_id' => 12,
            'name' => 'Cross Tenant',
            'value' => 'forbidden',
        ]),
    ));
    expectDictTenant($crossParentDenied[1] === '字典类型不存在', 'cross-Tenant dictionary parent denial changed');

    expectDictTenant(
        app(ExecutionContextStore::class)->run(
            new \app\common\execution\AdminExecutionContext($alpha, 'test.dict.data.add'),
            fn() => app(DictDataApplicationService::class)->add($alpha, [
                'tenant_id' => 202,
                'type_id' => $alphaTypeId,
                'name' => 'Enabled',
                'value' => '1',
                'sort' => 30,
            ]),
        ),
        'Alpha dictionary data add failed',
    );
    $alphaDataId = (int) $pdo->query("SELECT id FROM pa_dict_data WHERE tenant_id = 101 AND type_id = {$alphaTypeId} LIMIT 1")->fetchColumn();
    expectDictTenant($alphaDataId > 0, 'Alpha dictionary data was not created');
    expectDictTenant(
        array_column(app(ExecutionContextStore::class)->run(
            new \app\common\execution\AdminExecutionContext($alpha, 'test.dict.data.by-type.alpha'),
            fn() => app(DictDataApplicationService::class)->byType($alpha, 'status'),
        ), 'value') === ['1'],
        'Alpha byType lost owned data',
    );
    expectDictTenant(
        app(ExecutionContextStore::class)->run(
            new \app\common\execution\AdminExecutionContext($beta, 'test.dict.data.by-type.beta'),
            fn() => app(DictDataApplicationService::class)->byType($beta, 'status'),
        ) === [],
        'Beta byType leaked Alpha data',
    );

    $betaBefore = $pdo->query('SELECT name, type, is_disable FROM pa_dict_type WHERE id = 12')->fetch(PDO::FETCH_ASSOC);
    $betaDataBefore = $pdo->query('SELECT name, value, type_value, is_disable FROM pa_dict_data WHERE id = 22')->fetch(PDO::FETCH_ASSOC);
    $typeDenied = dictFailure(fn() => app(ExecutionContextStore::class)->run(
        new \app\common\execution\AdminExecutionContext($alpha, 'test.dict.type.edit.cross-tenant'),
        fn() => app(DictTypeApplicationService::class)->edit($alpha, [
            'id' => 12, 'name' => 'Cross Tenant', 'type' => 'cross_tenant',
        ]),
    ));
    $missingTypeDenied = dictFailure(fn() => app(ExecutionContextStore::class)->run(
        new \app\common\execution\AdminExecutionContext($alpha, 'test.dict.type.edit.missing'),
        fn() => app(DictTypeApplicationService::class)->edit($alpha, [
            'id' => 999999, 'name' => 'Missing', 'type' => 'missing',
        ]),
    ));
    expectDictTenant($missingTypeDenied === $typeDenied, 'type edit enumerated Tenant ownership');

    $dataDenied = dictFailure(fn() => app(ExecutionContextStore::class)->run(
        new \app\common\execution\AdminExecutionContext($alpha, 'test.dict.data.edit.cross-tenant'),
        fn() => app(DictDataApplicationService::class)->edit($alpha, [
            'id' => 22, 'name' => 'Cross Tenant', 'value' => 'forbidden',
        ]),
    ));
    $missingDataDenied = dictFailure(fn() => app(ExecutionContextStore::class)->run(
        new \app\common\execution\AdminExecutionContext($alpha, 'test.dict.data.edit.missing'),
        fn() => app(DictDataApplicationService::class)->edit($alpha, [
            'id' => 999999, 'name' => 'Missing', 'value' => 'missing',
        ]),
    ));
    expectDictTenant($missingDataDenied === $dataDenied, 'data edit enumerated Tenant ownership');

    expectDictTenant(
        app(ExecutionContextStore::class)->run(
            new \app\common\execution\AdminExecutionContext($alpha, 'test.dict.type.edit'),
            fn() => app(DictTypeApplicationService::class)->edit($alpha, [
                'id' => $alphaTypeId, 'name' => 'Alpha state', 'type' => 'state',
            ]),
        ),
        'Alpha dictionary type edit failed',
    );
    expectDictTenant(
        (string) $pdo->query("SELECT type_value FROM pa_dict_data WHERE id = {$alphaDataId}")->fetchColumn() === 'state',
        'Alpha type rename did not synchronize owned data',
    );
    expectDictTenant(
        (string) $pdo->query('SELECT type_value FROM pa_dict_data WHERE id = 22')->fetchColumn() === 'shared_key',
        'Alpha type rename changed Beta data',
    );
    $occupiedTypeDenied = dictFailure(fn() => app(ExecutionContextStore::class)->run(
        new \app\common\execution\AdminExecutionContext($alpha, 'test.dict.type.delete.occupied'),
        fn() => app(DictTypeApplicationService::class)->delete($alpha, $alphaTypeId),
    ));
    expectDictTenant($occupiedTypeDenied[1] === '字典类型已被数据项使用，请先删除数据项', 'occupied delete lost its failure shape');
    $typeStatusDenied = dictFailure(fn() => app(ExecutionContextStore::class)->run(
        new \app\common\execution\AdminExecutionContext($alpha, 'test.dict.type.status.cross-tenant'),
        fn() => app(DictTypeApplicationService::class)->updateStatus($alpha, 12, 1),
    ));
    $missingTypeStatusDenied = dictFailure(fn() => app(ExecutionContextStore::class)->run(
        new \app\common\execution\AdminExecutionContext($alpha, 'test.dict.type.status.missing'),
        fn() => app(DictTypeApplicationService::class)->updateStatus($alpha, 999999, 1),
    ));
    expectDictTenant($missingTypeStatusDenied === $typeStatusDenied, 'type status enumerated Tenant ownership');
    $dataStatusDenied = dictFailure(fn() => app(ExecutionContextStore::class)->run(
        new \app\common\execution\AdminExecutionContext($alpha, 'test.dict.data.status.cross-tenant'),
        fn() => app(DictDataApplicationService::class)->updateStatus($alpha, 22, 1),
    ));
    $missingDataStatusDenied = dictFailure(fn() => app(ExecutionContextStore::class)->run(
        new \app\common\execution\AdminExecutionContext($alpha, 'test.dict.data.status.missing'),
        fn() => app(DictDataApplicationService::class)->updateStatus($alpha, 999999, 1),
    ));
    expectDictTenant($missingDataStatusDenied === $dataStatusDenied, 'data status enumerated Tenant ownership');
    dictFailure(fn() => app(ExecutionContextStore::class)->run(
        new \app\common\execution\AdminExecutionContext($alpha, 'test.dict.type.delete.cross-tenant'),
        fn() => app(DictTypeApplicationService::class)->delete($alpha, 12),
    ));
    dictFailure(fn() => app(ExecutionContextStore::class)->run(
        new \app\common\execution\AdminExecutionContext($alpha, 'test.dict.data.delete.cross-tenant'),
        fn() => app(DictDataApplicationService::class)->delete($alpha, 22),
    ));

    expectDictTenant(
        $pdo->query('SELECT name, type, is_disable FROM pa_dict_type WHERE id = 12')->fetch(PDO::FETCH_ASSOC) === $betaBefore,
        'cross-Tenant denial changed Beta type',
    );
    expectDictTenant(
        $pdo->query('SELECT name, value, type_value, is_disable FROM pa_dict_data WHERE id = 22')->fetch(PDO::FETCH_ASSOC) === $betaDataBefore,
        'cross-Tenant denial changed Beta data',
    );
} finally {
    RegisteredMysqlTestResource::cleanup($pdo, $database, $databaseCreated);
}

echo 'MT02-DICT-TENANT-ISOLATION-001 passed checks=' . $GLOBALS['dictTenantChecks'] . PHP_EOL;
