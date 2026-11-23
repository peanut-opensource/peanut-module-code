<?php

declare(strict_types=1);

namespace app\common\contract;

interface AdminPermissionPolicy
{
    /**
     * @param iterable<string> $registeredPermissions
     * @param iterable<string> $grantedPermissions
     */
    public function canAccess(
        bool $isRoot,
        string $accessUri,
        iterable $registeredPermissions,
        iterable $grantedPermissions,
    ): bool;
}
