<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Member\Contract;

use PeanutAdmin\Modules\Member\Contract\Dto\MemberBalanceSnapshot;
use PeanutAdmin\Modules\Member\Contract\Dto\MemberIdentitySnapshot;
use app\common\http\PageResult;
use PeanutAdmin\Kernel\Context\AuthenticatedMemberContext;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Context\TenantSystemContext;

interface MemberQueries
{
    public function identity(
        AuthenticatedMemberContext|TenantContext|TenantSystemContext $context,
        int $memberId,
    ): ?MemberIdentitySnapshot;

    /** The caller owns the transaction containing this row lock. */
    public function lockedIdentity(
        AuthenticatedMemberContext|TenantContext|TenantSystemContext $context,
        int $memberId,
    ): ?MemberIdentitySnapshot;

    /**
     * Returns Tenant-scoped member fields without resolving file URLs.
     * Consumers keep presentation and storage URL handling at their boundary.
     */
    public function memberFields(
        AuthenticatedMemberContext|TenantContext|TenantSystemContext $context,
        int $memberId,
        array $fields,
    ): array;

    /** @return list<array<string, mixed>> */
    public function tags(TenantContext|TenantSystemContext $context): array;

    public function balanceSnapshot(
        AuthenticatedMemberContext|TenantContext|TenantSystemContext $context,
        int $memberId,
    ): ?MemberBalanceSnapshot;

    public function balanceLogsForCurrentMember(int $page, int $pageSize): PageResult;
}
