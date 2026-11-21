<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Auth;

use DateTimeImmutable;
use DateTimeZone;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;
use PeanutAdmin\Modules\Identity\Audit\AuditService;
use PeanutAdmin\Modules\Identity\Contract\Dto\TenantMemberSummary;
use PeanutAdmin\Modules\Identity\Contract\Dto\TenantSessionSummary;
use PeanutAdmin\Modules\Identity\Contract\TenantMemberDirectory;
use PeanutAdmin\Modules\Identity\Contract\TenantSessionAccess;
use PeanutAdmin\Modules\Identity\Contract\TenantSessionAccessException;
use PeanutAdmin\Modules\Identity\Persistence\Model\TenantSession;
use PeanutAdmin\Modules\Identity\Persistence\Model\TenantSessionToken;
use think\facade\Db;

/** Authoritative self-service session boundary with ownership, audit and token revocation. */
final readonly class TenantSessionAccessService implements TenantSessionAccess
{
    public function __construct(
        private TenantMemberDirectory $members,
        private AuditService $audit,
    ) {}

    public function revalidateSession(int $tenantId, int $accountId, int $memberId, string $sessionKey, string $clientKey, string $requestId): TenantContext
    {
        if ($tenantId < 1 || $accountId < 1 || $memberId < 1
            || preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/D', $sessionKey) !== 1
            || trim($clientKey) === '' || trim($requestId) === '') {
            throw TenantSessionAccessException::denied();
        }
        $member = $this->members->activeMembership($tenantId, $memberId);
        if ($member === null || $member->accountId !== $accountId) {
            throw TenantSessionAccessException::denied();
        }
        $now = $this->format(new DateTimeImmutable('now', new DateTimeZone('UTC')));
        $session = TenantSession::where('tenant_id', $tenantId)
            ->where('account_id', $accountId)->where('tenant_member_id', $memberId)
            ->where('session_key', $sessionKey)->where('client_key', $clientKey)
            ->where('status', 'active')
            ->where('account_security_revision', $member->accountSecurityRevision)
            ->where('tenant_security_revision', $member->tenantSecurityRevision)
            ->where('member_security_revision', $member->securityRevision)
            ->where('idle_expires_at', '>', $now)->where('absolute_expires_at', '>', $now)
            ->find()?->toArray();
        if ($session === null) {
            throw TenantSessionAccessException::denied();
        }
        return TenantContext::fromValidatedSession(new ValidatedTenantSession(
            (int) $session['id'],
            $sessionKey,
            $tenantId,
            $accountId,
            $memberId,
            $clientKey,
            new DateTimeImmutable((string) $session['issued_at'], new DateTimeZone('UTC')),
            $member->authorizationRevision,
            $member->tenantAuthorizationRevision,
        ), $requestId);
    }

    public function revalidateManagementSession(int $tenantId, int $accountId, int $memberId, string $sessionKey, string $clientKey, string $requestId): array
    {
        if ($tenantId < 1 || $accountId < 1 || $memberId < 1
            || preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/D', $sessionKey) !== 1
            || trim($clientKey) === '' || trim($requestId) === '') {
            throw TenantSessionAccessException::denied();
        }
        $now = $this->format(new DateTimeImmutable('now', new DateTimeZone('UTC')));
        $row = TenantSession::alias('session')
            ->join('tenant_member member', "member.id=session.tenant_member_id AND member.tenant_id=session.tenant_id AND member.account_id=session.account_id AND member.status='active'")
            ->join('tenant tenant', "tenant.id=member.tenant_id AND tenant.status='active'")
            ->join('account account', "account.id=member.account_id AND account.status='active'")
            ->leftJoin('credential credential', "credential.account_id=account.id AND credential.kind='email_password' AND credential.identifier_type='email' AND credential.status='active'")
            ->where('session.tenant_id', $tenantId)->where('session.account_id', $accountId)
            ->where('session.tenant_member_id', $memberId)->where('session.session_key', $sessionKey)
            ->where('session.client_key', $clientKey)->where('session.status', 'active')
            ->whereColumn('session.account_security_revision', 'account.security_revision')
            ->whereColumn('session.tenant_security_revision', 'tenant.security_revision')
            ->whereColumn('session.member_security_revision', 'member.security_revision')
            ->where('session.idle_expires_at', '>', $now)->where('session.absolute_expires_at', '>', $now)
            ->field('session.id,session.issued_at,member.authorization_revision,tenant.authorization_revision AS tenant_authorization_revision,credential.id AS credential_id')
            ->find()?->toArray();
        if ($row === null) {
            throw TenantSessionAccessException::denied();
        }
        $execution = app()->make(\app\common\execution\CurrentExecutionContext::class)->current();
        if (!$execution instanceof \app\common\execution\AdminExecutionContext
            || $execution->tenant->tenantId !== $tenantId
            || $execution->tenant->tenantAuthorizationRevision !== (int) $row['tenant_authorization_revision']) {
            \PeanutAdmin\Modules\Identity\Contract\TenantModuleStateQueries::invalidateRequestInstallations();
        }
        $context = TenantContext::fromValidatedSession(new ValidatedTenantSession(
            (int) $row['id'], $sessionKey, $tenantId, $accountId, $memberId, $clientKey,
            new DateTimeImmutable((string) $row['issued_at'], new DateTimeZone('UTC')),
            (int) $row['authorization_revision'], (int) $row['tenant_authorization_revision'],
        ), $requestId);
        return ['context' => $context, 'active_credential' => $row['credential_id'] !== null];
    }

    public function ownedSessions(TenantContext $context): array
    {
        $member = $this->assertActor($context);
        $rows = TenantSession::where('tenant_id', $member->tenantId)
            ->where('account_id', $member->accountId)
            ->where('tenant_member_id', $member->memberId)
            ->order('last_seen_at', 'desc')
            ->order('id', 'desc')
            ->select()
            ->toArray();

        return array_values(array_map(
            fn(array $row): TenantSessionSummary => $this->summary($row, $context, $member),
            $rows,
        ));
    }

    public function revokeOwnedSession(TenantContext $context, string $sessionKey): TenantSessionSummary
    {
        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/D', $sessionKey) !== 1) {
            throw TenantSessionAccessException::notFound();
        }

        return Db::transaction(function () use ($context, $sessionKey): TenantSessionSummary {
            \PeanutAdmin\Modules\Identity\Persistence\Model\Tenant::where('id', $context->tenantId)->lock(true)->find();
            \PeanutAdmin\Modules\Identity\Persistence\Model\Account::where('id', $context->accountId)->lock(true)->find();
            \PeanutAdmin\Modules\Identity\Persistence\Model\TenantMember::where('tenant_id', $context->tenantId)->where('id', $context->memberId)->lock(true)->find();
            $member = $this->assertActor($context);
            $row = TenantSession::where('tenant_id', $member->tenantId)
                ->where('account_id', $member->accountId)
                ->where('tenant_member_id', $member->memberId)
                ->where('session_key', $sessionKey)
                ->lock(true)
                ->find()?->toArray();
            if ($row === null) {
                throw TenantSessionAccessException::notFound();
            }
            if ($row['status'] === 'active') {
                $now = $this->format(new DateTimeImmutable('now', new DateTimeZone('UTC')));
                $updated = TenantSession::where('id', (int) $row['id'])
                    ->where('tenant_id', $member->tenantId)
                    ->where('account_id', $member->accountId)
                    ->where('tenant_member_id', $member->memberId)
                    ->where('status', 'active')
                    ->update([
                        'status' => 'revoked',
                        'revoked_at' => $now,
                        'revoke_reason' => 'user_device_revoked',
                        'updated_at' => $now,
                    ]);
                if ($updated !== 1) {
                    throw TenantSessionAccessException::conflict();
                }
                TenantSessionToken::where('session_id', (int) $row['id'])
                    ->where('status', 'active')
                    ->update(['status' => 'revoked', 'revoked_at' => $now]);
                $this->audit->tenantMember(
                    $context,
                    'tenant.identity.session_revoked',
                    'identity.session.revoke-own',
                    'tenant_session',
                    hash('sha256', $sessionKey),
                    ['current' => hash_equals($context->sessionKey, $sessionKey)],
                );
                $this->audit->recordRevocation(
                    $context,
                    $member->tenantId,
                    $member->memberId,
                    'session',
                    hash('sha256', $sessionKey),
                    'session_revoked',
                    ['status' => 'active'],
                    ['status' => 'revoked'],
                    hash('sha256', $sessionKey),
                );
            }

            $updated = TenantSession::where('id', (int) $row['id'])
                ->where('tenant_id', $member->tenantId)
                ->where('account_id', $member->accountId)
                ->where('tenant_member_id', $member->memberId)
                ->find()?->toArray();
            if ($updated === null) {
                throw TenantSessionAccessException::notFound();
            }

            return $this->summary($updated, $context, $member);
        });
    }

    private function assertActor(TenantContext $context): TenantMemberSummary
    {
        $member = $this->members->current($context);
        if (!$member instanceof TenantMemberSummary) {
            throw TenantSessionAccessException::denied();
        }
        $now = $this->format(new DateTimeImmutable('now', new DateTimeZone('UTC')));
        $current = TenantSession::where('tenant_id', $member->tenantId)
            ->where('account_id', $member->accountId)
            ->where('tenant_member_id', $member->memberId)
            ->where('session_key', $context->sessionKey)
            ->where('client_key', $context->clientKey)
            ->where('status', 'active')
            ->where('account_security_revision', $member->accountSecurityRevision)
            ->where('tenant_security_revision', $member->tenantSecurityRevision)
            ->where('member_security_revision', $member->securityRevision)
            ->where('idle_expires_at', '>', $now)
            ->where('absolute_expires_at', '>', $now)
            ->value('id');
        if ($current === null) {
            throw TenantSessionAccessException::denied();
        }

        return $member;
    }

    /** @param array<string,mixed> $row */
    private function summary(array $row, TenantContext $context, TenantMemberSummary $member): TenantSessionSummary
    {
        $ip = is_string($row['ip_address']) ? $this->maskIp($row['ip_address']) : null;
        $agent = is_string($row['user_agent_hash']) ? substr($row['user_agent_hash'], 0, 12) : null;

        return new TenantSessionSummary(
            (string) $row['session_key'],
            (string) $row['client_key'],
            (string) $row['status'],
            hash_equals($context->sessionKey, (string) $row['session_key']),
            $ip,
            $agent,
            $this->instant((string) $row['issued_at']),
            $this->instant((string) $row['last_seen_at']),
            $this->instant((string) $row['absolute_expires_at']),
            $row['revoked_at'] === null ? null : $this->instant((string) $row['revoked_at']),
            $member->authorizationRevision,
        );
    }

    private function maskIp(string $ip): ?string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ip);
            $parts[3] = '*';
            return implode('.', $parts);
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return implode(':', array_slice(explode(':', $ip), 0, 3)) . ':*';
        }
        return null;
    }

    private function instant(string $value): string
    {
        return (new DateTimeImmutable($value, new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s.v\Z');
    }

    private function format(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
    }
}
