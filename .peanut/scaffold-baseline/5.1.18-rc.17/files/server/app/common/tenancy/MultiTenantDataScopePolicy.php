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
        $table = self::rootTable($query->getTable(true));
        $aliases = $query->getOption('alias');
        $prefix = is_array($aliases) && isset($aliases[$table]) && is_string($aliases[$table]) && $aliases[$table] !== ''
            ? $aliases[$table]
            : $table;
        $query->where($prefix . '.tenant_id', $this->executionContext->tenantId());
    }

    private static function rootTable(mixed $table): string
    {
        if (!is_string($table) || $table === '') {
            throw new \LogicException('TENANT_MODEL_ROOT_TABLE_REQUIRED');
        }
        return $table;
    }

    public function prepareWrite(Model $model): void
    {
        $tenantId = $this->executionContext->tenantId();
        $data = $model->getData();
        if (array_key_exists('tenant_id', $data) && $data['tenant_id'] !== null) {
            $provided = $data['tenant_id'];
            if ((!is_int($provided) && !(is_string($provided) && ctype_digit($provided)))
                || (int) $provided !== $tenantId) {
                throw new \DomainException('TENANT_WRITE_OWNERSHIP_MISMATCH');
            }
        }
        $model->setAttr('tenant_id', $tenantId);
    }

}
