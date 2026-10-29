<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Contract\Dto;

/** Stable cross-Module projection of one active Tenant membership. */
final readonly class TenantMemberSummary
{
    public function __construct(
        public int $tenantId,
        public int $memberId,
        public int $accountId,
        public string $displayName,
        public int $accountSecurityRevision,
        public int $tenantSecurityRevision,
        public int $securityRevision,
        public int $authorizationRevision,
        public ?int $primaryDepartmentId = null,
    ) {
        if ($tenantId < 1 || $memberId < 1 || $accountId < 1
            || $displayName === '' || mb_strlen($displayName) > 160
            || $accountSecurityRevision < 1 || $tenantSecurityRevision < 1
            || $securityRevision < 1 || $authorizationRevision < 1
            || ($primaryDepartmentId !== null && $primaryDepartmentId < 1)
        ) {
            throw new \InvalidArgumentException('TENANT_MEMBER_SUMMARY_INVALID');
        }
    }
}
