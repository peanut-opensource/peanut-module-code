<?php

declare(strict_types=1);

namespace app\common\tenancy;

use app\common\execution\CurrentExecutionContext;
use think\Model;
use think\db\BaseQuery;

final readonly class MultiTenantDataScopePolicy implements DataScopePolicy
{
    public function __construct(private CurrentExecutionContext $executionContext) {}

    public function applyTo(BaseQuery $query): void
    {
        $table = $query->getTable(true);
        if (!is_string($table) || $table === '') {
            throw new \LogicException('TENANT_MODEL_ROOT_TABLE_REQUIRED');
        }
        $query->where($table . '.tenant_id', $this->executionContext->tenantId());
    }

    public function prepareWrite(Model $model): void
    {
        $tenantId = $this->executionContext->tenantId();
        $data = $model->getData();
        if (array_key_exists('tenant_id', $data)
            && $data['tenant_id'] !== null
            && (int) $data['tenant_id'] !== $tenantId) {
            throw new \DomainException('TENANT_WRITE_OWNERSHIP_MISMATCH');
        }
        $model->setAttr('tenant_id', $tenantId);
    }

    public function usesTenantColumn(): bool
    {
        return true;
    }
}
