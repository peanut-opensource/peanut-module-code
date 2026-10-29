<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\access\Infrastructure;

use PeanutAdmin\DataPermission\Constraint\ExistsByContract;
use PeanutAdmin\DataPermission\Constraint\TargetSetConstraintApplier;
use PeanutAdmin\DataPermission\Exception\DataAuthorizationException;
use PeanutAdmin\Modules\Identity\DataPermission\Model\DataPermissionTargetRecord;
use think\db\BaseQuery;
use think\db\Query;
use think\db\Raw;

final readonly class ThinkPhpTargetSetConstraintApplier implements TargetSetConstraintApplier
{
    public function apply(BaseQuery $query, ExistsByContract $constraint): void
    {
        if ($constraint->contractKey !== 'data_permission.target-set') {
            throw new DataAuthorizationException('AUTHZ_CONSTRAINT_UNSUPPORTED', 'The EXISTS contract is not registered.');
        }
        $outerColumn = $constraint->outerColumn->value;
        $targetTable = (new DataPermissionTargetRecord())->getTable();
        $subquery = (new Query($query->getConnection()))
            ->table($targetTable)
            ->fieldRaw('1')
            ->where('tenant_id', $constraint->tenantId)
            ->where('target_set_id', $constraint->targetSetId)
            ->where('status', 'active')
            ->whereRaw(
                "target_id COLLATE utf8mb4_0900_ai_ci = (CAST({$outerColumn} AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_0900_ai_ci)",
            );
        $subquery->parseOptions();
        $sql = $query->getConnection()->getBuilder()->select($subquery);
        $query->whereExists(new Raw($sql, $subquery->getBind(false)));
    }
}
