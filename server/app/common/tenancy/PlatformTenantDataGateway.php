<?php

declare(strict_types=1);

namespace app\common\tenancy;

use app\common\model\TenantOwnedModel;
use app\common\execution\CurrentExecutionContext;
use app\common\infrastructure\runtime\OperationalLog;
use think\db\BaseQuery;

/** The only application-owned path allowed to bypass Tenant model scope. */
final class PlatformTenantDataGateway
{
    public function __construct(private readonly CurrentExecutionContext $executionContext) {}

    /** @param class-string<TenantOwnedModel> $modelClass */
    public function query(string $modelClass, string $actor, string $operation): BaseQuery
    {
        $actor = trim($actor);
        $operation = trim($operation);
        if (!is_subclass_of($modelClass, TenantOwnedModel::class)
            || $actor === ''
            || $operation === '') {
            throw new \InvalidArgumentException('TENANT_SCOPE_BYPASS_INVALID');
        }

        OperationalLog::notice($this->executionContext, 'tenant_scope_bypass', [
            'model' => $modelClass,
            'actor' => $actor,
            'operation' => $operation,
        ]);
        return $modelClass::withoutTenantScope();
    }
}
