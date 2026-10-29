<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Member\Contract;

use PeanutAdmin\Modules\Member\Contract\Dto\MemberIdentitySnapshot;
use PeanutAdmin\Kernel\Context\AuthenticatedMemberContext;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Context\TenantSystemContext;

interface MemberIdentityCommands
{
    public function register(TenantSystemContext $context, string $account, string $password, string $avatar): void;

    /** @throws \runtimeException when the identity is absent, disabled, or the password is invalid. */
    public function login(TenantSystemContext $context, string $identifier, string $password, string $loginIp): MemberIdentitySnapshot;

    public function loginByVerifiedMobile(
        TenantContext|TenantSystemContext $context,
        string $mobile,
        string $avatar,
        string $loginIp,
    ): MemberIdentitySnapshot;

    public function resetPasswordByVerifiedMobile(
        TenantContext|TenantSystemContext $context,
        string $mobile,
        string $password,
    ): void;

    /** Verifies the current Tenant owns a member with this mobile before code consumption. */
    public function assertMobileBound(TenantContext|TenantSystemContext $context, string $mobile): void;

    /** Verifies the mobile is free in this Tenant before a verification code is consumed. */
    public function assertMobileAvailable(
        AuthenticatedMemberContext|TenantContext|TenantSystemContext $context,
        int $memberId,
        string $mobile,
    ): void;

    public function changePassword(AuthenticatedMemberContext $context, int $memberId, string $oldPassword, string $newPassword): void;

    public function bindVerifiedMobile(AuthenticatedMemberContext|TenantContext|TenantSystemContext $context, int $memberId, string $mobile): void;

    /** Creates a passwordless OAuth identity. The OAuth caller owns its outer transaction. */
    public function createOAuthMember(TenantContext|TenantSystemContext $context, array $profile): MemberIdentitySnapshot;

    /** The OAuth caller owns its outer transaction. */
    public function recordLogin(TenantContext|TenantSystemContext $context, int $memberId, string $loginIp): void;
}
