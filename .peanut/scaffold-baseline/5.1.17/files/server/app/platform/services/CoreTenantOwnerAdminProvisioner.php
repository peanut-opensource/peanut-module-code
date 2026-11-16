<?php

declare(strict_types=1);

namespace app\platform\services;

use PeanutAdmin\Modules\Identity\Contract\TenantOwnerAdminProvisioner;
use PeanutAdmin\Modules\Identity\Contract\AdminDirectoryQuery;

/** Verifies that Core already provisioned the first owner and initializes application capabilities. */
final readonly class CoreTenantOwnerAdminProvisioner implements TenantOwnerAdminProvisioner
{
    public function __construct(
        private ApplicationTenantBootstrapService $applicationBootstrap,
        private AdminDirectoryQuery $directory,
    ) {}

    public function provision(
        int $tenantId,
        int $accountId,
        int $memberId,
        int $coreRoleId,
        string $tenantCode,
        string $displayName,
    ): int {
        if (min($tenantId, $accountId, $memberId, $coreRoleId) < 1 || $tenantCode === '' || $displayName === '') {
            throw new \DomainException('TENANT_OWNER_ADMIN_PRINCIPAL_INVALID');
        }

        if (!$this->directory->isProvisionedOwner($tenantId, $accountId, $memberId, $coreRoleId)) {
            throw new \DomainException('TENANT_OWNER_ADMIN_PRINCIPAL_INVALID');
        }

        $this->applicationBootstrap->provision(
            $tenantId,
            $memberId,
            $coreRoleId,
            $tenantCode,
        );

        return $memberId;
    }
}
