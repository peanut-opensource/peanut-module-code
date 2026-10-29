<?php

declare(strict_types=1);

namespace app\common\model;

use app\common\tenancy\DataScopePolicy;
use think\Model;
use think\db\BaseQuery;

/** Base model for data owned by exactly one Tenant. */
abstract class TenantOwnedModel extends BaseModel
{
    private const TENANT_GLOBAL_SCOPE = 'tenantOwnership';
    private DataScopePolicy $dataScopePolicy;

    protected function getOptions(): array
    {
        $options = parent::getOptions();
        $options['globalScope'] = array_values(array_unique([
            ...($options['globalScope'] ?? []),
            self::TENANT_GLOBAL_SCOPE,
        ]));

        return $options;
    }

    /** ThinkORM maker callback injection; only the application composition root calls this. */
    final public function setDataScopePolicy(DataScopePolicy $policy): void
    {
        $this->dataScopePolicy = $policy;
        if (!$policy->usesTenantColumn()) {
            $this->setOption('disuse', array_values(array_unique([
                ...(array) $this->getOption('disuse', []),
                'tenant_id',
            ])));
        }
    }

    /** ThinkORM global-scope callback; application code must never call it directly. */
    public function scopeTenantOwnership(BaseQuery $query): void
    {
        $this->policy()->applyTo($query);
    }

    final public static function withoutTenantScope(): BaseQuery
    {
        return static::withoutGlobalScope([self::TENANT_GLOBAL_SCOPE]);
    }

    public static function onBeforeInsert(Model $model): void
    {
        self::tenantModel($model)->policy()->prepareWrite($model);
    }

    public static function onBeforeUpdate(Model $model): void
    {
        self::tenantModel($model)->policy()->prepareWrite($model);
    }

    private static function tenantModel(Model $model): self
    {
        if (!$model instanceof self) {
            throw new \LogicException('TENANT_MODEL_EVENT_TARGET_INVALID');
        }
        return $model;
    }

    private function policy(): DataScopePolicy
    {
        if (!isset($this->dataScopePolicy)) {
            throw new \LogicException('DATA_SCOPE_POLICY_UNAVAILABLE');
        }
        return $this->dataScopePolicy;
    }
}
