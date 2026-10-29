<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Membership\Query;

use DateTimeImmutable;
use DateTimeZone;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Modules\Identity\Contract\Dto\TenantMemberSummary;
use PeanutAdmin\Modules\Identity\Contract\TenantMemberDirectory;
use PeanutAdmin\Modules\Identity\Persistence\Model\TenantMember;
use PeanutAdmin\Modules\Identity\Persistence\Model\TenantSession;

/** ThinkPHP projection for the public, fail-closed membership contract. */
final readonly class ThinkPhpTenantMemberDirectory implements TenantMemberDirectory
{
    public function activeMembership(int $tenantId, int $memberId): ?TenantMemberSummary
    {
        if ($tenantId < 1 || $memberId < 1) {
            return null;
        }

        return $this->findActive($tenantId, $memberId);
    }

    public function activeMembershipCount(int $accountId): int
    {
        if ($accountId < 1) {
            return 0;
        }

        return TenantMember::alias('member')
            ->join('tenant tenant', "tenant.id=member.tenant_id AND tenant.status='active'")
            ->join('account account', "account.id=member.account_id AND account.status='active'")
            ->where('member.account_id', $accountId)
            ->where('member.status', 'active')
            ->count();
    }

    public function current(TenantContext $context): ?TenantMemberSummary
    {
        if ($context->tenantId < 1 || $context->memberId < 1 || $context->accountId < 1
            || $context->authorizationRevision < 1 || trim($context->requestId) === ''
        ) {
            return null;
        }

        $member = $this->findActive(
            $context->tenantId,
            $context->memberId,
            $context->accountId,
            $context->authorizationRevision,
        );
        if ($member === null) {
            return null;
        }
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.v');
        $sessionId = TenantSession::where('tenant_id', $member->tenantId)
            ->where('account_id', $member->accountId)
            ->where('tenant_member_id', $member->memberId)
            ->where('session_key', $context->sessionKey)
            ->where('status', 'active')
            ->where('account_security_revision', $member->accountSecurityRevision)
            ->where('tenant_security_revision', $member->tenantSecurityRevision)
            ->where('member_security_revision', $member->securityRevision)
            ->where('idle_expires_at', '>', $now)
            ->where('absolute_expires_at', '>', $now)
            ->value('id');

        return $sessionId === null ? null : $member;
    }

    public function activeMember(TenantContext $context, int $memberId): ?TenantMemberSummary
    {
        if ($memberId < 1 || $this->current($context) === null) {
            return null;
        }

        return $this->activeMembership($context->tenantId, $memberId);
    }

    private function findActive(
        int $tenantId,
        int $memberId,
        ?int $accountId = null,
        ?int $authorizationRevision = null,
    ): ?TenantMemberSummary {
        $query = TenantMember::alias('member')
            ->join('tenant tenant', "tenant.id=member.tenant_id AND tenant.status='active'")
            ->join('account account', "account.id=member.account_id AND account.status='active'")
            ->where('member.tenant_id', $tenantId)
            ->where('member.id', $memberId)
            ->where('member.status', 'active');
        if ($accountId !== null) {
            $query->where('member.account_id', $accountId);
        }
        if ($authorizationRevision !== null) {
            $query->where('member.authorization_revision', $authorizationRevision);
        }
        $row = $query->field(
            'member.tenant_id,member.id,member.account_id,member.display_name,member.primary_department_id,'
            . 'member.security_revision,member.authorization_revision,account.display_name AS account_display_name,'
            . 'account.security_revision AS account_security_revision,tenant.security_revision AS tenant_security_revision',
        )->find()?->toArray();
        if ($row === null) {
            return null;
        }

        $displayName = trim((string) ($row['display_name'] ?: $row['account_display_name']));
        if ($displayName === '') {
            return null;
        }

        return new TenantMemberSummary(
            (int) $row['tenant_id'],
            (int) $row['id'],
            (int) $row['account_id'],
            $displayName,
            (int) $row['account_security_revision'],
            (int) $row['tenant_security_revision'],
            (int) $row['security_revision'],
            (int) $row['authorization_revision'],
            $row['primary_department_id'] === null ? null : (int) $row['primary_department_id'],
        );
    }
}
