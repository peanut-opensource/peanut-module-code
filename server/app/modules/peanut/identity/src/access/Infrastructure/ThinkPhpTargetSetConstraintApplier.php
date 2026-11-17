<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\access\Infrastructure;

use PeanutAdmin\DataPermission\Constraint\ExistsByContract;
use PeanutAdmin\DataPermission\Constraint\TargetSetConstraintApplier;
use PeanutAdmin\Modules\Identity\DataPermission\Model\DataPermissionTargetRecord;
use think\db\BaseQuery;
use think\db\Query;
use think\db\Raw;

final readonly class ThinkPhpTargetSetConstraintApplier implements TargetSetConstraintApplier
{
    public function apply(BaseQuery $query, ExistsByContract $constraint): void
    {
        $builder = $query->getConnection()->getBuilder();
        $outerColumn = $builder->parseKey($query, $constraint->outerColumn->value, true);
        $targetTable = (new DataPermissionTargetRecord())->getTable();
        $subquery = (new Query($query->getConnection()))
            ->table($targetTable)
            ->field('target_id')
            ->where('tenant_id', $constraint->tenantId)
            ->where('target_set_id', $constraint->targetSetId)
            ->where('status', 'active');
        $subquery->parseOptions();
        $query->whereFieldRaw(
            "CAST({$outerColumn} AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_0900_ai_ci",
            'IN',
            new Raw($builder->select($subquery), $subquery->getBind(false)),
        );
    }
}
