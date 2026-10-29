<?php

declare(strict_types=1);

namespace app\common\contract\authorization;

use app\common\dto\authorization\AdminAccessData;
use app\common\dto\authorization\AdminPrincipal;
use app\common\dto\authorization\PermissionDecision;
use PeanutAdmin\Kernel\Auth\TenantContext;

interface AdminAuthorizationQuery
{
    public function principal(TenantContext $tenantContext): AdminPrincipal;

    public function accessData(TenantContext $tenantContext, AdminPrincipal $admin): AdminAccessData;

    public function decide(
        ?TenantContext $tenantContext,
        AdminPrincipal $admin,
        string $accessUri,
    ): PermissionDecision;

    /** @return list<array<string,mixed>> */
    public function assignableMenuRecords(TenantContext $tenantContext): array;
}
