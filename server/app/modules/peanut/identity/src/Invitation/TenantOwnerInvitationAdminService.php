<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Invitation;

use app\common\services\audit\AuditContractHost;
use PeanutAdmin\Kernel\Context\PlatformContext;
use PeanutAdmin\Kernel\Platform\Authorization\PlatformAuthorizationEvaluator;
use DateTimeImmutable;
use DateTimeZone;
use PeanutAdmin\Kernel\Audit\AuditOutcome;
use PeanutAdmin\Kernel\Authorization\Application\PageRequest;
use PeanutAdmin\Kernel\Identity\EmailAddress;
use PeanutAdmin\Modules\Identity\Platform\Application\PlatformTenantAdminService;
use think\facade\Db;
use PeanutAdmin\Modules\Identity\Tenancy\TenantStatus;

final class TenantOwnerInvitationAdminService
{
    private const OWNER_ROLE = 'core.tenant-owner';
    private const CREATE_PERMISSION = 'platform.tenant.create';
    private const INVITE_PERMISSION = 'platform.tenant.provision-owner';

    public function __construct(
        private readonly PlatformTenantAdminService $tenants,
        private readonly PlatformAuthorizationEvaluator $authorization,
        private readonly OwnerInvitationDeliveryPort $delivery,
        private readonly OwnerInvitationRuntimePolicy $runtimePolicy,
        private readonly AuditContractHost $audit,
    ) {}

    /** @return array<string,mixed> */
    public function provision(
        PlatformContext $context,
        string $tenantCode,
        string $tenantName,
        string $ownerEmail,
        string $ownerDisplayName,
        int $expiresInHours,
    ): array {
        $this->authorization->assertAllowed($context, self::CREATE_PERMISSION);
        $this->authorization->assertAllowed($context, self::INVITE_PERMISSION);
        $this->runtimePolicy->assertIssuanceAllowed($this->delivery);
        $email = EmailAddress::fromString($ownerEmail)->value();
        $token = OneTimeInvitationToken::issue();
        $expiresAt = $this->expiry($expiresInHours);

        $issued = Db::transaction(function () use (
            $context,
            $tenantCode,
            $tenantName,
            $email,
            $ownerDisplayName,
            $token,
            $expiresAt
        ): array {
            $tenant = $this->tenants->createTenant(
                $context,
                $tenantCode,
                $tenantName,
                $tenantName,
                'zh-CN',
                'Asia/Shanghai',
            );
            $tenantId = (int) $tenant['id'];
            $invitation = $this->insertInvitation(
                $tenantId,
                $email,
                $ownerDisplayName,
                $token,
                $expiresAt,
                $context->operatorId,
            );
            $this->audit->recordPlatform(
                'tenant.owner-invitation.created',
                self::INVITE_PERMISSION,
                $context->requestId,
                $context->operatorId,
                $context->accountId,
                ['tenant_id' => $tenantId, 'invitation_id' => $invitation['id']],
                AuditOutcome::Success,
                null,
            );

            return $invitation + [
                'tenant_code' => $tenant['code'],
                'tenant_name' => $tenant['name'],
                'tenant_status' => TenantStatus::Provisioning->value,
            ];
        });

        return $this->deliver($issued, $token);
    }

    /** @return array<string,mixed> */
    public function invite(
        PlatformContext $context,
        int $tenantId,
        string $ownerEmail,
        string $ownerDisplayName,
        int $expiresInHours,
    ): array {
        $this->authorization->assertAllowed($context, self::INVITE_PERMISSION);
        $this->runtimePolicy->assertIssuanceAllowed($this->delivery);
        $email = EmailAddress::fromString($ownerEmail)->value();
        $token = OneTimeInvitationToken::issue();
        $expiresAt = $this->expiry($expiresInHours);

        $issued = Db::transaction(function () use (
            $context,
            $tenantId,
            $email,
            $ownerDisplayName,
            $token,
            $expiresAt
        ): array {
            $tenant = $this->lockInvitableTenant($tenantId);
            $this->expireStalePending($tenantId);
            $this->assertOwnerBaseline($tenantId, (string) $tenant['status']);
            if ($this->pendingInvitationExists($tenantId)) {
                throw TenantOwnerInvitationException::conflict(
                    'TENANT_OWNER_INVITATION_PENDING',
                    'Tenant already has a pending owner invitation.',
                );
            }
            if (Db::name('role')->where('tenant_id', $tenantId)->where('key', self::OWNER_ROLE)->lock(true)->value('id') === null) {
                $now = $this->format($this->now());
                Db::name('role')->insert([
                    'tenant_id' => $tenantId, 'key' => self::OWNER_ROLE, 'name' => 'Tenant Owner',
                    'description' => 'Built-in owner role for tenant governance.', 'is_builtin' => 1,
                    'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
            $invitation = $this->insertInvitation(
                $tenantId,
                $email,
                $ownerDisplayName,
                $token,
                $expiresAt,
                $context->operatorId,
            );
            $this->audit->recordPlatform(
                'tenant.owner-invitation.created',
                self::INVITE_PERMISSION,
                $context->requestId,
                $context->operatorId,
                $context->accountId,
                ['tenant_id' => $tenantId, 'invitation_id' => $invitation['id']],
                AuditOutcome::Success,
                null,
            );

            return $invitation + [
                'tenant_code' => $tenant['code'],
                'tenant_name' => $tenant['name'],
                'tenant_status' => $tenant['status'],
            ];
        });

        return $this->deliver($issued, $token);
    }

    /** @return array{items:list<array<string,mixed>>,total:int} */
    public function invitations(
        PlatformContext $context,
        int $tenantId,
        PageRequest $page,
    ): array {
        $this->authorization->assertAllowed($context, self::INVITE_PERMISSION);
        $query = Db::name('tenant_owner_invitation')->where('tenant_id', $tenantId);
        $items = (clone $query)
            ->field('id,tenant_id,email_normalized AS email,display_name,delivery_status,delivery_provider,delivery_attempts,delivery_error_code,generation,expires_at,accepted_at,revoked_at,accepted_account_id,accepted_member_id,invited_by_operator_id,revoked_by_operator_id,created_at,updated_at')
            ->fieldRaw("CASE WHEN status='pending' AND expires_at<=UTC_TIMESTAMP(3) THEN 'expired' ELSE status END AS status")
            ->order('id', 'desc')->limit($page->offset(), $page->pageSize)->select()->toArray();

        return [
            'items' => $items,
            'total' => (int) $query->count(),
        ];
    }

    /** @return array<string,mixed> */
    public function resend(
        PlatformContext $context,
        int $invitationId,
        int $expiresInHours,
    ): array {
        $this->authorization->assertAllowed($context, self::INVITE_PERMISSION);
        $this->runtimePolicy->assertIssuanceAllowed($this->delivery);
        $token = OneTimeInvitationToken::issue();
        $expiresAt = $this->expiry($expiresInHours);
        $issued = Db::transaction(function () use (
            $context,
            $invitationId,
            $expiresAt,
            $token
        ): array {
            $invitation = $this->lockInvitationById($invitationId);
            $tenantId = (int) $invitation['tenant_id'];
            $tenant = $this->lockInvitableTenant($tenantId);
            if ($invitation['status'] !== 'pending') {
                throw TenantOwnerInvitationException::conflict(
                    'INVITATION_NOT_PENDING',
                    'Only a pending invitation can be resent.',
                );
            }
            $this->assertOwnerBaseline($tenantId, (string) $tenant['status']);
            $now = $this->now();
            Db::name('tenant_owner_invitation')->where('id', $invitationId)->where('status', 'pending')->update([
                'token_hash' => $token->hash(),
                'delivery_status' => 'pending_delivery',
                'delivery_provider' => null,
                'delivery_message_id' => null,
                'delivery_attempts' => 0,
                'delivery_error_code' => null,
                'last_delivery_at' => null,
                'generation' => Db::raw('generation+1'),
                'expires_at' => $this->format($expiresAt),
                'updated_at' => $this->format($now),
            ]);
            $this->audit->recordPlatform(
                'tenant.owner-invitation.resent',
                self::INVITE_PERMISSION,
                $context->requestId,
                $context->operatorId,
                $context->accountId,
                ['tenant_id' => (int) $invitation['tenant_id'], 'invitation_id' => $invitationId],
                AuditOutcome::Success,
                null,
            );

            return [
                'id' => $invitationId,
                'tenant_id' => $tenantId,
                'tenant_code' => $tenant['code'],
                'tenant_name' => $tenant['name'],
                'email' => $invitation['email_normalized'],
                'display_name' => $invitation['display_name'],
                'status' => 'pending',
                'delivery_status' => 'pending_delivery',
                'generation' => (int) $invitation['generation'] + 1,
                'expires_at' => $this->format($expiresAt),
            ];
        });

        return $this->deliver($issued, $token);
    }

    /** @return array{id:int,tenant_id:int,status:string} */
    public function revoke(PlatformContext $context, int $invitationId): array
    {
        $this->authorization->assertAllowed($context, self::INVITE_PERMISSION);

        return Db::transaction(function () use ($context, $invitationId): array {
            $invitation = $this->lockInvitationById($invitationId);
            $this->lockInvitableTenant((int) $invitation['tenant_id']);
            if ($invitation['status'] !== 'pending') {
                throw TenantOwnerInvitationException::conflict(
                    'INVITATION_NOT_PENDING',
                    'Only a pending invitation can be revoked.',
                );
            }
            $now = $this->format($this->now());
            Db::name('tenant_owner_invitation')->where('id', $invitationId)->where('status', 'pending')->update([
                'status' => 'revoked',
                'revoked_at' => $now,
                'revoked_by_operator_id' => $context->operatorId,
                'updated_at' => $now,
            ]);
            $this->audit->recordPlatform(
                'tenant.owner-invitation.revoked',
                self::INVITE_PERMISSION,
                $context->requestId,
                $context->operatorId,
                $context->accountId,
                ['tenant_id' => (int) $invitation['tenant_id'], 'invitation_id' => $invitationId],
                AuditOutcome::Success,
                null,
            );

            return [
                'id' => $invitationId,
                'tenant_id' => (int) $invitation['tenant_id'],
                'status' => 'revoked',
            ];
        });
    }

    /** @return array<string,mixed> */
    private function insertInvitation(
        int $tenantId,
        string $email,
        string $displayName,
        OneTimeInvitationToken $token,
        DateTimeImmutable $expiresAt,
        int $operatorId,
    ): array {
        $now = $this->format($this->now());
        $id = Db::name('tenant_owner_invitation')->insertGetId([
            'tenant_id' => $tenantId,
            'email_normalized' => $email,
            'display_name' => $displayName,
            'token_hash' => $token->hash(),
            'expires_at' => $this->format($expiresAt),
            'invited_by_operator_id' => $operatorId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [
            'id' => $id,
            'tenant_id' => $tenantId,
            'email' => $email,
            'display_name' => $displayName,
            'status' => 'pending',
            'delivery_status' => 'pending_delivery',
            'generation' => 1,
            'expires_at' => $this->format($expiresAt),
        ];
    }

    /** @param array<string,mixed> $issued @return array<string,mixed> */
    private function deliver(array $issued, OneTimeInvitationToken $token): array
    {
        try {
            $result = $this->delivery->deliver(new OwnerInvitationDelivery(
                (int) $issued['id'],
                (int) $issued['generation'],
                (string) $issued['tenant_name'],
                (string) $issued['email'],
                (string) $issued['display_name'],
                new DateTimeImmutable((string) $issued['expires_at'], new DateTimeZone('UTC')),
                $token,
            ));
        } catch (\Throwable) {
            $result = OwnerInvitationDeliveryResult::failed('delivery-port', 'DELIVERY_PROVIDER_ERROR');
        }
        $now = $this->format($this->now());
        $attempted = $result->status === 'pending_delivery' ? 0 : 1;
        $changes = [
            'delivery_status' => $result->status,
            'delivery_provider' => $result->provider,
            'delivery_message_id' => $result->messageId,
            'delivery_attempts' => Db::raw('delivery_attempts+' . $attempted),
            'delivery_error_code' => $result->errorCode,
            'updated_at' => $now,
        ];
        if ($attempted === 1) {
            $changes['last_delivery_at'] = $now;
        }
        Db::name('tenant_owner_invitation')->where('id', (int) $issued['id'])
            ->where('token_hash', $token->hash())->where('status', 'pending')->update($changes);

        $response = array_replace($issued, ['delivery_status' => $result->status]);
        if ($this->runtimePolicy->allowsPlaintextTokenResponse()) {
            $response['accept_token'] = $token->expose();
        }

        return $response;
    }

    /** @return array{id:int,tenant_id:int,email_normalized:string,display_name:string,status:string,generation:int} */
    private function lockInvitationById(int $invitationId): array
    {
        $row = Db::name('tenant_owner_invitation')->where('id', $invitationId)
            ->field('id,tenant_id,email_normalized,display_name,status,generation')->lock(true)->find();
        if ($row === null) {
            throw TenantOwnerInvitationException::notFound();
        }

        return $row;
    }

    /** @return array{id:int,code:string,name:string,status:string} */
    private function lockInvitableTenant(int $tenantId): array
    {
        $row = Db::name('tenant')->where('id', $tenantId)->field('id,code,name,status')->lock(true)->find();
        if ($row === null) {
            throw TenantOwnerInvitationException::conflict('TENANT_NOT_FOUND', 'Tenant was not found.');
        }
        if (!in_array($row['status'], [TenantStatus::Provisioning->value, TenantStatus::Active->value], true)) {
            throw TenantOwnerInvitationException::conflict(
                'TENANT_OWNER_INVITATION_NOT_ALLOWED',
                'Owner invitations require a provisioning or active Tenant.',
            );
        }

        return $row;
    }

    private function assertOwnerBaseline(int $tenantId, string $tenantStatus): void
    {
        if ($tenantStatus === TenantStatus::Provisioning->value) {
            if ($this->ownerMemberExists($tenantId, ['pending', 'active'])) {
                throw TenantOwnerInvitationException::conflict(
                    'TENANT_OWNER_ALREADY_ASSIGNED',
                    'Tenant already has an owner candidate.',
                );
            }
            return;
        }

        if (!$this->ownerMemberExists($tenantId, ['active'])) {
            throw TenantOwnerInvitationException::conflict(
                'TENANT_ACTIVE_OWNER_REQUIRED',
                'An active Tenant must retain an active owner before another owner is invited.',
            );
        }
    }

    private function expireStalePending(int $tenantId): void
    {
        $now = $this->format($this->now());
        Db::name('tenant_owner_invitation')->where('tenant_id', $tenantId)
            ->where('status', 'pending')->where('expires_at', '<=', $now)
            ->update(['status' => 'expired', 'updated_at' => $now]);
    }

    private function pendingInvitationExists(int $tenantId): bool
    {
        return Db::name('tenant_owner_invitation')->where('tenant_id', $tenantId)
            ->where('status', 'pending')->value('id') !== null;
    }

    /** @param list<string> $statuses */
    private function ownerMemberExists(int $tenantId, array $statuses): bool
    {
        return Db::name('tenant_member')->alias('member')
            ->join('member_role membership', 'membership.tenant_id=member.tenant_id AND membership.tenant_member_id=member.id')
            ->join('role role', "role.tenant_id=membership.tenant_id AND role.id=membership.role_id AND role.`key`='core.tenant-owner' AND role.is_builtin=1 AND role.status='active'")
            ->where('member.tenant_id', $tenantId)->whereIn('member.status', $statuses)->value('member.id') !== null;
    }

    private function expiry(int $hours): DateTimeImmutable
    {
        if ($hours < 1 || $hours > 720) {
            throw TenantOwnerInvitationException::invalid('INVITATION_EXPIRY_INVALID');
        }
        return $this->now()->modify("+{$hours} hours");
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
