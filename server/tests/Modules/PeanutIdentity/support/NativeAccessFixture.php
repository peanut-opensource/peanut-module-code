<?php

declare(strict_types=1);

use app\common\contract\authorization\AdminAuthorizationQuery;
use app\common\dto\authorization\{AdminAccessData, AdminPrincipal, PermissionDecision};
use app\common\model\TenantOwnedModel;
use app\common\tenancy\DataScopePolicy;
use PeanutAdmin\Kernel\Auth\{TenantContext, ValidatedTenantSession};
use think\{Container, DbManager, Model};
use think\db\BaseQuery;

// Only an in-process database; this fixture never loads an application environment.
$server = dirname(__DIR__, 4);
$loader = require $server . '/vendor/autoload.php';
$loader->setPsr4('app\\', $server . '/app');

function businessKey(int $id): string
{
    return sprintf('00000000-0000-4000-8000-%012d', $id);
}

function businessActor(int $tenant = 1, int $member = 1): TenantContext
{
    return TenantContext::fromValidatedSession(
        new ValidatedTenantSession(1, 'fixture', $tenant, 1, $member, 'admin-web', new DateTimeImmutable(), 1, 1),
        'business-native-test',
    );
}

function businessCheck(bool $passed, string $message): void
{
    if (!$passed) {
        throw new RuntimeException($message);
    }
}

function businessReject(callable $operation, string $code): void
{
    try {
        $operation();
    } catch (\app\common\exception\BusinessException $exception) {
        businessCheck($exception->errorCode === $code, 'Unexpected rejection ' . $exception->errorCode);
        return;
    }
    throw new RuntimeException('Expected rejection ' . $code);
}

// Keep service behavior tests independent of the host's directory/license database.
$roles = new class implements AdminAuthorizationQuery {
    public array $authorizedMembers = [];
    public array $grants = [];

    public function principal(TenantContext $tenantContext): AdminPrincipal
    {
        return AdminPrincipal::fromArray([
            'id' => $tenantContext->memberId, 'tenant_id' => $tenantContext->tenantId,
            'account_id' => $tenantContext->accountId, 'authorization_revision' => $tenantContext->authorizationRevision,
        ]);
    }

    public function decide(?TenantContext $tenantContext, AdminPrincipal $admin, string $accessUri): PermissionDecision
    {
        return $tenantContext !== null && in_array($tenantContext->memberId, $this->authorizedMembers, true)
            && in_array($accessUri, $this->grants, true)
            ? PermissionDecision::allow($accessUri) : PermissionDecision::deny($accessUri, 'PERMISSION_NOT_GRANTED');
    }

    public function accessData(TenantContext $tenantContext, AdminPrincipal $admin): AdminAccessData
    {
        throw new LogicException('No shell projection in device commands.');
    }

    public function decisions(TenantContext $tenantContext, array $accessUris): array
    {
        throw new LogicException('Device commands consume the current decision entry.');
    }

    public function assignableMenuRecords(TenantContext $tenantContext): array
    {
        throw new LogicException('No menu projection in device commands.');
    }
};
$authorization = $roles;
$db = new DbManager();
$db->setConfig(['default' => 'fixture', 'connections' => [
    'fixture' => ['type' => 'sqlite', 'database' => ':memory:', 'prefix' => 'pa_'],
]]);
Container::getInstance()->instance(DbManager::class, $db);
$modelPolicy = new class implements DataScopePolicy {
    public function applyTo(BaseQuery $query): void {}
    public function prepareWrite(Model $model): void
    {
        businessCheck(in_array((int) $model->getData('tenant_id'), [1, 2], true), 'Unexpected fixture tenant');
    }
};
Model::maker(static function (Model $model) use ($modelPolicy): void {
    if ($model instanceof TenantOwnedModel) {
        $model->setDataScopePolicy($modelPolicy);
    }
});
