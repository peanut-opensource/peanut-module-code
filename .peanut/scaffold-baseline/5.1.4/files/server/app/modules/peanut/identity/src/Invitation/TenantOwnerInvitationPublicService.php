<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Invitation;

use app\common\services\audit\AuditContractHost;
use PeanutAdmin\Modules\Identity\Contract\TenantOwnerAdminProvisioner;
use DateTimeImmutable;
use DateTimeZone;
use PeanutAdmin\Kernel\Audit\AuditOutcome;
use PeanutAdmin\Kernel\Identity\EmailAddress;
use PeanutAdmin\Kernel\Identity\PasswordHasher;
use PeanutAdmin\Kernel\Identity\PasswordPolicy;
use think\facade\Db;
use PeanutAdmin\Modules\Identity\Tenancy\TenantStatus;

final class TenantOwnerInvitationPublicService
{
    private const OWNER_ROLE = 'core.tenant-owner';

    public function __construct(
        private readonly TenantOwnerAdminProvisioner $applicationBootstrap,
        private readonly AuditContractHost $audit,
        private readonly PasswordHasher $passwords,
        private readonly PasswordPolicy $passwordPolicy,
    ) {}

    /** @return array<string,mixed> */
    public function inspect(#[\SensitiveParameter] string $plaintextToken): array
    {
        $token = OneTimeInvitationToken::fromPlaintext($plaintextToken);
        $result = Db::transaction(function () use ($token): array {
            $invitation = $this->lockInvitation($token);
            if ($invitation['status'] === 'pending' && $this->isExpired((string) $invitation['expires_at'])) {
                $this->markExpired((int) $invitation['id']);
                $invitation['status'] = 'expired';
            }

            return $invitation;
        });

        return [
            'tenant_name' => $result['tenant_name'],
            'display_name' => $result['display_name'],
            'email_hint' => $this->maskEmail((string) $result['email_normalized']),
            'status' => $result['status'],
            'delivery_status' => $result['delivery_status'],
            'expires_at' => $result['expires_at'],
            'requires_password' => $result['status'] === 'pending'
                && !$this->credentialExists((string) $result['email_normalized']),
        ];
    }

    /** @return array<string,mixed> */
    public function accept(
        #[\SensitiveParameter]
        string $plaintextToken,
        #[\SensitiveParameter]
        ?string $newAccountPassword,
    ): array {
        $token = OneTimeInvitationToken::fromPlaintext($plaintextToken);
        $result = Db::transaction(function () use ($token, $newAccountPassword): array {
            $invitation = $this->lockInvitation($token);
            $this->lockTenant((int) $invitation['tenant_id']);
            if ($invitation['status'] === 'accepted') {
                return ['_error' => 'INVITATION_ALREADY_ACCEPTED'];
            }
            if ($invitation['status'] === 'revoked') {
                return ['_error' => 'INVITATION_REVOKED'];
            }
            if ($invitation['status'] === 'expired'
                || $this->isExpired((string) $invitation['expires_at'])) {
                if ($invitation['status'] === 'pending') {
                    $this->markExpired((int) $invitation['id']);
                }
                return ['_error' => 'INVITATION_EXPIRED'];
            }
            if ($invitation['status'] !== 'pending') {
                return ['_error' => 'INVITATION_NOT_PENDING'];
            }
            $tenantId = (int) $invitation['tenant_id'];
            $tenantStatus = (string) $invitation['tenant_status'];
            if (!in_array($tenantStatus, [TenantStatus::Provisioning->value, TenantStatus::Active->value], true)) {
                return ['_error' => 'TENANT_OWNER_INVITATION_NOT_ALLOWED'];
            }
            if ($tenantStatus === TenantStatus::Provisioning->value) {
                if ($this->ownerMemberExists($tenantId, ['pending', 'active'])) {
                    return ['_error' => 'TENANT_OWNER_ALREADY_ASSIGNED'];
                }
            } elseif (!$this->ownerMemberExists($tenantId, ['active'])) {
                return ['_error' => 'TENANT_ACTIVE_OWNER_REQUIRED'];
            }
            $roleId = Db::name('role')->where('tenant_id', $tenantId)->where('key', self::OWNER_ROLE)
                ->where('is_builtin', 1)->where('status', 'active')->lock(true)->value('id');
            if ($roleId === null) {
                return ['_error' => 'TENANT_OWNER_ROLE_UNAVAILABLE'];
            }

            $email = EmailAddress::fromString((string) $invitation['email_normalized'])->value();
            $credential = Db::name('credential')->where('identifier_type', 'email')
                ->where('identifier_normalized', $email)->lock(true)->field('id,account_id,status')->find();
            if ($credential === null) {
                if ($newAccountPassword === null || $newAccountPassword === '') {
                    return ['_error' => 'NEW_ACCOUNT_PASSWORD_REQUIRED'];
                }
                $this->passwordPolicy->assertValid($newAccountPassword);
                $now = $this->format($this->now());
                // MySQL BIGINT 自增值可能返回字符串，在进入严格身份合同前归一化。
                $accountId = (int) Db::name('account')->insertGetId([
                    'display_name' => (string) $invitation['display_name'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                Db::name('credential')->insert([
                    'account_id' => $accountId,
                    'kind' => 'email_password',
                    'identifier_type' => 'email',
                    'identifier_normalized' => $email,
                    'secret_hash' => $this->passwords->hash($newAccountPassword),
                    'verified_at' => $now,
                    'secret_changed_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } else {
                if ($newAccountPassword !== null && $newAccountPassword !== '') {
                    return ['_error' => 'EXISTING_ACCOUNT_PASSWORD_FORBIDDEN'];
                }
                $accountId = (int) $credential['account_id'];
                if (Db::name('account')->where('id', $accountId)->where('status', 'active')->lock(true)->value('id') === null) {
                    return ['_error' => 'OWNER_ACCOUNT_INACTIVE'];
                }
                if ((string) $credential['status'] !== 'active') {
                    return ['_error' => 'OWNER_CREDENTIAL_INACTIVE'];
                }
            }

            $member = Db::name('tenant_member')->where('tenant_id', $tenantId)->where('account_id', $accountId)
                ->lock(true)->field('id,status')->find();
            $memberId = $member === null ? null : (int) $member['id'];
            if ($memberId !== null && $this->memberHasRole($tenantId, $memberId, (int) $roleId)) {
                return ['_error' => 'ACCOUNT_ALREADY_TENANT_OWNER'];
            }
            if ($member === null) {
                $now = $this->format($this->now());
                $memberId = (int) Db::name('tenant_member')->insertGetId([
                    'tenant_id' => $tenantId,
                    'account_id' => $accountId,
                    'display_name' => (string) $invitation['display_name'],
                    'status' => 'pending',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $member = ['id' => $memberId, 'status' => 'pending'];
            }
            if ($member['status'] === 'pending') {
                Db::name('tenant_member')->where('tenant_id', $tenantId)->where('id', $memberId)->update([
                    'status' => 'active',
                    'joined_at' => Db::raw('UTC_TIMESTAMP(3)'),
                    'security_revision' => Db::raw('security_revision+1'),
                    'authorization_revision' => Db::raw('authorization_revision+1'),
                    'updated_at' => Db::raw('UTC_TIMESTAMP(3)'),
                ]);
            } elseif ($member['status'] !== 'active') {
                return ['_error' => 'TENANT_MEMBER_INACTIVE'];
            }
            if (!$this->memberHasRole($tenantId, $memberId, (int) $roleId)) {
                Db::name('member_role')->insert([
                    'tenant_id' => $tenantId,
                    'tenant_member_id' => $memberId,
                    'role_id' => (int) $roleId,
                    'assigned_at' => Db::raw('UTC_TIMESTAMP(3)'),
                ]);
                Db::name('tenant_member')->where('tenant_id', $tenantId)->where('id', $memberId)->update([
                    'authorization_revision' => Db::raw('authorization_revision+1'),
                    'updated_at' => Db::raw('UTC_TIMESTAMP(3)'),
                ]);
                Db::name('tenant')->where('id', $tenantId)->update([
                    'authorization_revision' => Db::raw('authorization_revision+1'),
                    'updated_at' => Db::raw('UTC_TIMESTAMP(3)'),
                ]);
            }
            $this->applicationBootstrap->provision(
                $tenantId,
                $accountId,
                $memberId,
                (int) $roleId,
                (string) $invitation['tenant_code'],
                (string) $invitation['display_name'],
            );

            $now = $this->format($this->now());
            $updated = Db::name('tenant_owner_invitation')->where('id', (int) $invitation['id'])
                ->where('status', 'pending')->update([
                    'token_hash' => hash('sha256', random_bytes(32)),
                    'status' => 'accepted',
                    'accepted_at' => $now,
                    'accepted_account_id' => $accountId,
                    'accepted_member_id' => $memberId,
                    'updated_at' => $now,
                ]);
            if ($updated !== 1) {
                throw TenantOwnerInvitationException::conflict(
                    'INVITATION_ACCEPT_RACE',
                    'Invitation acceptance lost its concurrency guard.',
                );
            }
            $this->audit->recordTenantSystem(
                $tenantId,
                'tenant.owner-invitation.accepted',
                'platform.tenant.provision-owner',
                'owner-invitation:' . (int) $invitation['id'],
                ['invitation_id' => (int) $invitation['id'], 'member_id' => $memberId],
                AuditOutcome::Success,
                null,
            );

            return [
                'invitation_id' => (int) $invitation['id'],
                'tenant_id' => $tenantId,
                'account_id' => $accountId,
                'member_id' => $memberId,
                'role_id' => (int) $roleId,
                'status' => 'accepted',
                'tenant_status' => $tenantStatus,
            ];
        });

        if (isset($result['_error'])) {
            $this->throwAcceptanceError((string) $result['_error']);
        }

        return $result;
    }

    /** @return array<string,mixed> */
    private function lockInvitation(OneTimeInvitationToken $token): array
    {
        $row = Db::name('tenant_owner_invitation')->alias('invitation')
            ->join('tenant tenant', 'tenant.id=invitation.tenant_id')
            ->where('invitation.token_hash', $token->hash())
            ->field('invitation.id,invitation.tenant_id,invitation.email_normalized,invitation.display_name,invitation.status,invitation.delivery_status,invitation.expires_at,invitation.accepted_account_id,invitation.accepted_member_id')
            ->field('tenant.code AS tenant_code,tenant.name AS tenant_name,tenant.status AS tenant_status')
            ->lock(true)->find();
        if ($row === null) {
            throw TenantOwnerInvitationException::notFound();
        }

        return $row;
    }

    private function markExpired(int $invitationId): void
    {
        Db::name('tenant_owner_invitation')->where('id', $invitationId)->where('status', 'pending')->update([
            'status' => 'expired',
            'updated_at' => $this->format($this->now()),
        ]);
    }

    /** @return array{id:int,status:string} */
    private function lockTenant(int $tenantId): array
    {
        $row = Db::name('tenant')->where('id', $tenantId)->field('id,status')->lock(true)->find();
        if ($row === null) {
            throw TenantOwnerInvitationException::conflict('TENANT_NOT_FOUND', 'Tenant was not found.');
        }
        return $row;
    }

    private function credentialExists(string $email): bool
    {
        return Db::name('credential')->where('identifier_type', 'email')
            ->where('identifier_normalized', $email)->value('id') !== null;
    }

    /** @param list<string> $statuses */
    private function ownerMemberExists(int $tenantId, array $statuses): bool
    {
        return Db::name('tenant_member')->alias('member')
            ->join('member_role membership', 'membership.tenant_id=member.tenant_id AND membership.tenant_member_id=member.id')
            ->join('role role', "role.tenant_id=membership.tenant_id AND role.id=membership.role_id AND role.`key`='core.tenant-owner' AND role.is_builtin=1 AND role.status='active'")
            ->where('member.tenant_id', $tenantId)->whereIn('member.status', $statuses)->value('member.id') !== null;
    }

    private function memberHasRole(int $tenantId, int $memberId, int $roleId): bool
    {
        return Db::name('member_role')->where('tenant_id', $tenantId)
            ->where('tenant_member_id', $memberId)->where('role_id', $roleId)->value('role_id') !== null;
    }

    private function isExpired(string $expiresAt): bool
    {
        return new DateTimeImmutable($expiresAt, new DateTimeZone('UTC')) <= $this->now();
    }

    private function maskEmail(string $email): string
    {
        [$local, $domain] = explode('@', $email, 2);
        $visible = mb_substr($local, 0, 1);
        return $visible . str_repeat('*', max(2, mb_strlen($local) - 1)) . '@' . $domain;
    }

    /** @return never */
    private function throwAcceptanceError(string $error): void
    {
        throw match ($error) {
            'INVITATION_REVOKED' => TenantOwnerInvitationException::gone($error, 'Invitation was revoked.'),
            'INVITATION_EXPIRED' => TenantOwnerInvitationException::gone($error, 'Invitation expired.'),
            'INVITATION_ALREADY_ACCEPTED' => TenantOwnerInvitationException::gone(
                $error,
                'Invitation was already accepted.',
            ),
            'NEW_ACCOUNT_PASSWORD_REQUIRED' => TenantOwnerInvitationException::invalid(
                $error,
                'A password is required for a new Account.',
            ),
            'EXISTING_ACCOUNT_PASSWORD_FORBIDDEN' => TenantOwnerInvitationException::conflict(
                $error,
                'A password cannot be supplied for an existing Account.',
            ),
            'ACCOUNT_ALREADY_TENANT_OWNER' => TenantOwnerInvitationException::conflict(
                $error,
                'The Account already holds the Tenant owner role.',
            ),
            default => TenantOwnerInvitationException::conflict(
                $error,
                'Invitation acceptance was rejected.',
            ),
        };
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    private function format(DateTimeImmutable $dateTime): string
    {
        return $dateTime->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
    }
}
