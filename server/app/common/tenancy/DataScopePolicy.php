<?php

declare(strict_types=1);

namespace app\common\tenancy;

use think\Model;
use think\db\BaseQuery;

interface DataScopePolicy
{
    public function applyTo(BaseQuery $query): void;

    public function prepareWrite(Model $model): void;

    public function usesTenantColumn(): bool;
}
