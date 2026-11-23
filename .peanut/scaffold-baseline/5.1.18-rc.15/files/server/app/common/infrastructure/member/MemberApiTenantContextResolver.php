<?php

declare(strict_types=1);

namespace app\common\infrastructure\member;

use PeanutAdmin\Modules\Member\Contract\MemberSubjectLookup;
use PeanutAdmin\Kernel\Context\AuthenticatedMemberContext;
use PeanutAdmin\Modules\Identity\Contract\AdminDirectoryQuery;

/** Restores application-member identity from a verified JWT subject and authoritative ownership. */
final class MemberApiTenantContextResolver
{
    public function __construct(
        private readonly MemberSubjectLookup $members,
        private readonly AdminDirectoryQuery $tenants,
    ) {}

    public function resolve(
        int $memberId,
        string $token,
        string $requestId,
        int $verifiedTenantId,
    ): AuthenticatedMemberContext {
        if ($memberId < 1 || $token === '' || $requestId === '' || $verifiedTenantId < 1) {
            throw new \DomainException('MEMBER_TENANT_CONTEXT_UNAVAILABLE');
        }

        $tenantId = $this->members->tenantId($memberId);
        if ($tenantId === null || $tenantId !== $verifiedTenantId) {
            throw new \DomainException('MEMBER_TENANT_CONTEXT_UNAVAILABLE');
        }
        if ($this->tenants->tenantStatus($tenantId) !== 'active') {
            throw new \DomainException('MEMBER_TENANT_CONTEXT_UNAVAILABLE');
        }

        return new AuthenticatedMemberContext(
            $tenantId,
            $memberId,
            hash('sha256', $token),
            $requestId,
        );
    }
}
