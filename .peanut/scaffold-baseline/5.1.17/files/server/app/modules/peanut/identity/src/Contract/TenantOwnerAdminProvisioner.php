<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Contract;

interface TenantOwnerAdminProvisioner
{
    public function provision(
        int $tenantId,
        int $accountId,
        int $memberId,
        int $coreRoleId,
        string $tenantCode,
        string $displayName,
    ): int;
}
