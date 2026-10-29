<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Member\Infrastructure\Persistence;

use PeanutAdmin\Modules\Member\Contract\MemberSubjectLookup;
use PeanutAdmin\Modules\Member\Contract\Dto\MemberSessionSubject;
use PeanutAdmin\Modules\Member\Model\Member;
use app\common\tenancy\PlatformTenantDataGateway;

final readonly class ThinkPhpMemberSubjectLookup implements MemberSubjectLookup
{
    public function __construct(private PlatformTenantDataGateway $tenantData) {}

    public function tenantId(int $memberId): ?int
    {
        return $this->sessionSubject($memberId)?->tenantId;
    }

    public function sessionSubject(int $memberId): ?MemberSessionSubject
    {
        if ($memberId < 1) {
            return null;
        }
        $member = $this->tenantData
            ->query(Member::class, 'api.member-auth', 'resolve-tenant-context')
            ->where('id', $memberId)
            ->where('status', 1)
            ->whereNull('delete_time')
            ->field(['id', 'tenant_id', 'session_revision'])
            ->find();
        if ($member === null || (int) $member->getData('id') !== $memberId) {
            return null;
        }
        $tenantId = (int) $member->getData('tenant_id');
        $sessionRevision = (int) $member->getData('session_revision');
        return $tenantId > 0 && $sessionRevision > 0
            ? new MemberSessionSubject($tenantId, $memberId, $sessionRevision)
            : null;
    }
}
