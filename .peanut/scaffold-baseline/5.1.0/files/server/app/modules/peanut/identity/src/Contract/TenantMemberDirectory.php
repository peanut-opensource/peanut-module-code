<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Contract;

use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Modules\Identity\Contract\Dto\TenantMemberSummary;

/** Public membership boundary; callers never receive Identity persistence models. */
interface TenantMemberDirectory
{
    /**
     * Returns an active membership without requiring a live employee session.
     *
     * This projection is for trusted workers and cross-Module actor attribution. It does not
     * grant an operation; callers must still enforce their own permission and Module checks.
     */
    public function activeMembership(int $tenantId, int $memberId): ?TenantMemberSummary;

    /** Counts active Tenant memberships for one active Account without granting switch access. */
    public function activeMembershipCount(int $accountId): int;

    /** Returns the current active actor only when identity and authorization revision still match. */
    public function current(TenantContext $context): ?TenantMemberSummary;

    /** Returns an active member in the actor's Tenant after revalidating the actor. */
    public function activeMember(TenantContext $context, int $memberId): ?TenantMemberSummary;
}
