<?php

declare(strict_types=1);

namespace app\common\policy\permission;

use app\common\contract\AdminPermissionPolicy;
use PeanutAdmin\Kernel\Authorization\RegisteredAdminPermissionPolicy as CoreRegisteredAdminPermissionPolicy;

/**
 * Exact registered permissions only. Registration is checked before root bypass.
 */
final class RegisteredAdminPermissionPolicy implements AdminPermissionPolicy
{
    public function canAccess(
        bool $isRoot,
        string $accessUri,
        iterable $registeredPermissions,
        iterable $grantedPermissions,
    ): bool {
        return (new CoreRegisteredAdminPermissionPolicy())->canAccess($isRoot, $accessUri, $registeredPermissions, $grantedPermissions);
    }
}
