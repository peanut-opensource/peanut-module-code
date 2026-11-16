<?php

declare(strict_types=1);

namespace app\common\services\authorization;

use PeanutAdmin\Modules\Identity\Contract\TenantAuthorizationQuery;

/** Read boundary for checking whether a menu permission is assigned to a role. */
final class MenuPermissionUsageQuery
{
    public function __construct(private readonly TenantAuthorizationQuery $identityAuthorization) {}

    public function assigned(string $permission): bool
    {
        return $this->identityAuthorization->permissionAssigned($permission);
    }
}
