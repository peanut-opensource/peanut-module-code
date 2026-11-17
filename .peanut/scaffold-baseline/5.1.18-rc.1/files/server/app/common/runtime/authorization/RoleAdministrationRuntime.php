<?php

declare(strict_types=1);

namespace app\common\runtime\authorization;

use PeanutAdmin\Modules\Identity\Authorization\Application\RoleAdminService;
use PeanutAdmin\Modules\Identity\Contract\TenantAuthorizationQuery;

/** Container-owned assembly and read projections for native Tenant roles. */
final readonly class RoleAdministrationRuntime
{
    public function __construct(
        private RoleAdminService $roles,
        private TenantAuthorizationQuery $identityAuthorization,
    ) {}

    public function service(): RoleAdminService
    {
        return $this->roles;
    }

    public function memberCount(int $tenantId, int $roleId): int
    {
        return $this->identityAuthorization->roleMemberCount($tenantId, $roleId);
    }

    /** @param list<int> $roleIds @return array<int,int> */
    public function batchMemberCounts(int $tenantId, array $roleIds): array
    {
        return $this->identityAuthorization->batchRoleMemberCounts($tenantId, $roleIds);
    }

}
