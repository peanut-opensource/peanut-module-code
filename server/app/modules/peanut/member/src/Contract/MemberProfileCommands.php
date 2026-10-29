<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Member\Contract;

use PeanutAdmin\Kernel\Context\AuthenticatedMemberContext;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Context\TenantSystemContext;

interface MemberProfileCommands
{
    /** The caller owns the surrounding transaction when profile and tags change together. */
    public function createAdminMember(TenantContext $context, array $profile, array $tagIds): void;

    /** The caller owns the surrounding transaction when profile and tags change together. */
    public function updateAdminMember(TenantContext $context, int $memberId, array $profile, ?array $tagIds): void;

    public function updateAdminField(TenantContext $context, int $memberId, string $field, mixed $value): void;

    public function updateStatus(TenantContext $context, int $memberId, int $status): void;

    public function updateSelfField(AuthenticatedMemberContext|TenantContext $context, int $memberId, string $field, mixed $value): void;

    /** Runs inside OAuth's existing transaction and does not resolve avatar URLs. */
    public function completeOAuthProfile(
        TenantContext|TenantSystemContext $context,
        int $memberId,
        ?string $nickname,
        ?string $avatar,
        int $loginTime,
        string $loginIp,
    ): void;

    /** Runs inside OAuth's existing transaction and only fills missing profile fields. */
    public function fillOAuthProfile(AuthenticatedMemberContext|TenantContext|TenantSystemContext $context, int $memberId, string $nickname, string $avatar): void;
}
