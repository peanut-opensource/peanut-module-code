<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\access\Infrastructure;

use PeanutAdmin\DataPermission\Constraint\AlwaysFalse;
use PeanutAdmin\DataPermission\Constraint\ColumnIn;
use PeanutAdmin\DataPermission\Constraint\ExistsByContract;
use PeanutAdmin\DataPermission\Constraint\TargetSetConstraintApplier;
use PeanutAdmin\DataPermission\Constraint\ThinkPhpQueryConstraintApplier;
use PeanutAdmin\Modules\Identity\DataPermission\Provider\ThinkPhpTargetSetMembershipProvider;
use think\db\BaseQuery;

final readonly class ThinkPhpTargetSetConstraintApplier implements TargetSetConstraintApplier
{
    public function __construct(private ThinkPhpTargetSetMembershipProvider $targets) {}

    public function apply(BaseQuery $query, ExistsByContract $constraint): void
    {
        $ids = $this->targets->targets($constraint->tenantId, $constraint->targetSetId);
        (new ThinkPhpQueryConstraintApplier())->apply(
            $query,
            $ids === [] ? new AlwaysFalse() : new ColumnIn($constraint->outerColumn, $ids),
        );
    }
}
