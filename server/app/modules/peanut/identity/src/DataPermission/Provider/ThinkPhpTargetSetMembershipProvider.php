<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\DataPermission\Provider;

use PeanutAdmin\DataPermission\Provider\TargetSetMembershipProvider;

final class ThinkPhpTargetSetMembershipProvider implements TargetSetMembershipProvider
{
    public function containsAll(int $tenantId, int $targetSetId, array $targetIds): bool
    {
        return false;
    }
}
