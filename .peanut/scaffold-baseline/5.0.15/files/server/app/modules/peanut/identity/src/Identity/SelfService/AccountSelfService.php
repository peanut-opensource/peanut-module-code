<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Identity\SelfService;

use DateTimeImmutable;
use DateTimeZone;
use PeanutAdmin\Modules\Identity\Audit\AuditService;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Authorization\Application\AdminAccessException;
use PeanutAdmin\Kernel\Identity\PasswordHasher;
use PeanutAdmin\Kernel\Identity\PasswordPolicy;
use PeanutAdmin\Modules\Identity\Persistence\Model\Account;
use PeanutAdmin\Modules\Identity\Persistence\Model\AuthSecurityEvent;
use PeanutAdmin\Modules\Identity\Persistence\Model\Credential;
use PeanutAdmin\Modules\Identity\Persistence\Model\LoginChallenge;
use PeanutAdmin\Modules\Identity\Persistence\Model\PlatformSession;
use PeanutAdmin\Modules\Identity\Persistence\Model\PlatformSessionToken;
use PeanutAdmin\Modules\Identity\Persistence\Model\TenantMember;
use PeanutAdmin\Modules\Identity\Persistence\Model\TenantSession;
use PeanutAdmin\Modules\Identity\Persistence\Model\TenantSessionToken;
use SensitiveParameter;
use Throwable;
use RuntimeException;
use think\db\PDOConnection;
use think\db\Raw;
use think\facade\Db;

final readonly class AccountSelfService
{
    private const PASSWORD_CHANGE_RATE_LIMIT = 5;
    private const PASSWORD_CHANGE_RATE_WINDOW = '-15 minutes';
    public const PASSWORD_CHANGE_RETRY_AFTER_SECONDS = 900;

    public function __construct(
        private AuditService $audit,
        private PasswordHasher $passwords,
        private PasswordPolicy $passwordPolicy,
    ) {}

    /** @return array<string, mixed> */
    public function profile(TenantContext $context): array
    {
        $row = $this->profileRow($context);
        if ($row === null) {
            throw AdminAccessException::conflict(
                'ACCOUNT_CREDENTIAL_UNAVAILABLE',
                'The account credential is not available.',
            );
        }

        return $this->profileFromRow($row);
    }

    /** @return array<string, mixed>|null */
    private function profileRow(TenantContext $context, bool $forUpdate = false): ?array
    {
        $query = Account::alias('account')
            ->join('credential credential', 'credential.account_id = account.id')
            ->join('tenant_member member', 'member.account_id = account.id')
            ->join('tenant tenant', 'tenant.id = member.tenant_id')
            ->where('account.id', $context->accountId)
            ->where('member.tenant_id', $context->tenantId)
            ->where('member.id', $context->memberId)
            ->where('member.status', 'active')
            ->where('tenant.status', 'active')
            ->where('account.status', 'active')
            ->where('credential.kind', 'email_password')
            ->where('credential.identifier_type', 'email')
            ->where('credential.status', 'active')
            ->field([
                'account.id', 'member.display_name', 'account.avatar_uri', 'credential.kind',
                'credential.identifier_type', 'credential.identifier_normalized',
                'credential.verified_at', 'credential.secret_changed_at',
            ])->order('credential.id')->limit(1);
        if ($forUpdate) {
            $query->lock(true);
        }

        return $query->find()?->toArray();
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function profileFromRow(
        array $row,
        ?string $displayName = null,
        string|null|false $avatarUri = false,
    ): array {
        return [
            'account_id' => (string) $row['id'],
            'display_name' => $displayName ?? (string) $row['display_name'],
            'avatar_uri' => $avatarUri === false
                ? ($row['avatar_uri'] === null ? null : (string) $row['avatar_uri'])
                : $avatarUri,
            'credential' => [
                'kind' => (string) $row['kind'],
                'identifier_type' => (string) $row['identifier_type'],
                'identifier_masked' => $this->maskEmail((string) $row['identifier_normalized']),
                'verified_at' => (string) $row['verified_at'],
                'secret_changed_at' => (string) $row['secret_changed_at'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function updateProfile(TenantContext $actor, string $displayName, ?string $avatarUri): array
    {
        $displayName = $this->displayName($displayName);
        $avatarUri = $this->avatarUri($avatarUri);

        return Db::transaction(function () use ($actor, $displayName, $avatarUri): array {
            $this->lockAccountAuthority($actor, false);
            $current = $this->profileRow($actor, true);
            if ($current === null) {
                throw AdminAccessException::conflict(
                    'ACCOUNT_CREDENTIAL_UNAVAILABLE',
                    'The account credential is not available.',
                );
            }
            $now = $this->now();
            TenantMember::where('tenant_id', $actor->tenantId)
                ->where('id', $actor->memberId)
                ->where('account_id', $actor->accountId)
                ->where('status', 'active')
                ->update([
                    'display_name' => $displayName,
                    'updated_at' => $now,
                ]);
            Account::where('id', $actor->accountId)->where('status', 'active')->update([
                'avatar_uri' => $avatarUri,
                'updated_at' => $now,
            ]);
            $changedFields = [];
            if ((string) $current['display_name'] !== $displayName) {
                $changedFields[] = 'display_name';
            }
            $currentAvatar = $current['avatar_uri'] === null ? null : (string) $current['avatar_uri'];
            if ($currentAvatar !== $avatarUri) {
                $changedFields[] = 'avatar_uri';
            }
            $this->audit->tenantMember(
                context: $actor,
                eventType: 'account.profile.changed',
                action: 'account.profile.changed',
                targetResourceType: 'account',
                targetResourceId: (string) $actor->accountId,
                metadata: ['changed_fields' => implode(',', $changedFields)],
            );

            return $this->profileFromRow($current, $displayName, $avatarUri);
        });
    }

    public function changePassword(
        TenantContext $actor,
        #[SensitiveParameter]
        string $currentPassword,
        #[SensitiveParameter]
        string $newPassword,
        string $ipAddress,
        ?string $userAgent,
    ): void {
        if ($currentPassword === '' || strlen($currentPassword) > PasswordHasher::MAXIMUM_INPUT_BYTES) {
            throw AdminAccessException::invalid('CURRENT_PASSWORD_INVALID', 'The current password is invalid.');
        }
        try {
            $this->passwordPolicy->assertValid($newPassword);
        } catch (\RuntimeException) {
            throw AdminAccessException::invalid(
                'NEW_PASSWORD_INVALID',
                sprintf(
                    'The new password must contain between %d and %d bytes.',
                    $this->passwordPolicy->minimumLength(),
                    $this->passwordPolicy->maximumLength(),
                ),
            );
        }

        $ipLockName = $this->passwordChangeIpLockName($ipAddress);
        $this->acquirePasswordChangeIpLock($ipLockName);
        try {
            $error = Db::transaction(function () use (
                $actor,
                $currentPassword,
                $newPassword,
                $ipAddress,
                $userAgent,
            ): ?AdminAccessException {
                $this->lockAccountAuthority($actor, true);
                $credential = Credential::alias('credential')
                    ->join('account account', 'account.id = credential.account_id')
                    ->join('tenant_member member', 'member.account_id = account.id')
                    ->join('tenant tenant', 'tenant.id = member.tenant_id')
                    ->where('credential.account_id', $actor->accountId)
                    ->where('member.tenant_id', $actor->tenantId)
                    ->where('member.id', $actor->memberId)
                    ->where('member.status', 'active')
                    ->where('tenant.status', 'active')
                    ->where('credential.kind', 'email_password')
                    ->where('credential.identifier_type', 'email')
                    ->where('credential.status', 'active')
                    ->where('account.status', 'active')
                    ->field(['credential.id', 'credential.secret_hash', 'account.security_revision AS account_security_revision'])
                    ->order('credential.id')->lock(true)->find();
                if ($credential === null) {
                    throw AdminAccessException::conflict(
                        'ACCOUNT_CREDENTIAL_UNAVAILABLE',
                        'The account credential is not available.',
                    );
                }
                $now = $this->now();
                $deniedCounts = $this->passwordChangeDeniedCounts($actor->accountId, $ipAddress, $now);
                if ($deniedCounts['account'] >= self::PASSWORD_CHANGE_RATE_LIMIT
                    || $deniedCounts['ip'] >= self::PASSWORD_CHANGE_RATE_LIMIT) {
                    $this->authEvent(
                        'password_change_rate_limited',
                        'denied',
                        'rate_limited',
                        $actor,
                        (int) $credential['id'],
                        $ipAddress,
                        $userAgent,
                        $now,
                    );

                    return new AdminAccessException(
                        'PASSWORD_CHANGE_RATE_LIMITED',
                        429,
                        'Too many password change attempts. Try again later.',
                    );
                }
                if (!$this->passwords->verify($currentPassword, (string) $credential['secret_hash'])) {
                    $this->authEvent(
                        'password_change_denied',
                        'denied',
                        'current_password_invalid',
                        $actor,
                        (int) $credential['id'],
                        $ipAddress,
                        $userAgent,
                        $now,
                    );

                    return AdminAccessException::invalid('CURRENT_PASSWORD_INVALID', 'The current password is invalid.');
                }
                if (hash_equals($currentPassword, $newPassword)) {
                    return AdminAccessException::invalid('PASSWORD_UNCHANGED', 'The new password must be different.');
                }
                Credential::where('id', (int) $credential['id'])->where('status', 'active')->update([
                    'secret_hash' => $this->passwords->hash($newPassword),
                    'failed_attempts' => 0,
                    'locked_until' => null,
                    'secret_changed_at' => $now,
                    'revision' => new Raw('revision + 1'),
                    'updated_at' => $now,
                ]);
                Account::where('id', $actor->accountId)->where('status', 'active')->update([
                    'security_revision' => new Raw('security_revision + 1'),
                    'updated_at' => $now,
                ]);
                $tenantSessionIds = TenantSession::where('account_id', $actor->accountId)->column('id');
                if ($tenantSessionIds !== []) {
                    TenantSessionToken::whereIn('session_id', $tenantSessionIds)->where('status', 'active')->update([
                        'status' => 'revoked',
                        'revoked_at' => $now,
                    ]);
                }
                $platformSessionIds = PlatformSession::where('account_id', $actor->accountId)->column('id');
                if ($platformSessionIds !== []) {
                    PlatformSessionToken::whereIn('session_id', $platformSessionIds)->where('status', 'active')->update([
                        'status' => 'revoked',
                        'revoked_at' => $now,
                    ]);
                }
                TenantSession::where('account_id', $actor->accountId)->where('status', 'active')->update([
                    'status' => 'revoked',
                    'revoked_at' => $now,
                    'revoke_reason' => 'credential_changed',
                    'updated_at' => $now,
                ]);
                PlatformSession::where('account_id', $actor->accountId)->where('status', 'active')->update([
                    'status' => 'revoked',
                    'revoked_at' => $now,
                    'revoke_reason' => 'credential_changed',
                    'updated_at' => $now,
                ]);
                $this->revokeLoginChallenges($actor->accountId, $now);
                $this->authEvent(
                    'password_changed',
                    'success',
                    null,
                    $actor,
                    (int) $credential['id'],
                    $ipAddress,
                    $userAgent,
                    $now,
                );
                $this->audit->tenantMember(
                    context: $actor,
                    eventType: 'account.password.changed',
                    action: 'account.password.changed',
                    targetResourceType: 'account',
                    targetResourceId: (string) $actor->accountId,
                    metadata: ['revoked_all_sessions' => true],
                );
                $cursor = 0;
                do {
                    $affected = TenantMember::where('account_id', $actor->accountId)->where('id', '>', $cursor)
                        ->field('id,tenant_id')->order('id')->limit(100)->select()->toArray();
                    foreach ($affected as $member) {
                        $cursor = (int) $member['id'];
                        $this->audit->recordRevocation(
                            $actor,
                            (int) $member['tenant_id'],
                            $cursor,
                            'tenant_scope_account',
                            (string) $actor->accountId,
                            'credential_changed',
                            ['account_security_revision' => (int) $credential['account_security_revision']],
                            ['account_security_revision' => (int) $credential['account_security_revision'] + 1],
                        );
                    }
                } while (count($affected) === 100);

                return null;
            });
        } finally {
            $this->releasePasswordChangeIpLock($ipLockName);
        }

        if ($error !== null) {
            throw $error;
        }
    }

    /** Only these existing writes lock authority; ordinary profile reads remain nonlocking. */
    private function lockAccountAuthority(TenantContext $actor, bool $allTenants): void
    {
        $tenants = $allTenants ? $this->accountTenants($actor->accountId, false) : [$actor->tenantId];
        foreach ($tenants as $tenantId) {
            \PeanutAdmin\Modules\Identity\Persistence\Model\Tenant::where('id', $tenantId)->lock(true)->find();
        }
        Account::where('id', $actor->accountId)->lock(true)->find();
        if ($allTenants) {
            if ($tenants !== $this->accountTenants($actor->accountId, true)) {
                throw new \DomainException('ACCOUNT_AUTHORITY_TENANT_SET_CHANGED');
            }
        } else {
            TenantMember::where('tenant_id', $actor->tenantId)->where('id', $actor->memberId)->where('account_id', $actor->accountId)->lock(true)->find();
        }
    }

    private function accountTenants(int $accountId, bool $lock): array
    {
        $cursor = 0;
        $tenants = [];
        do {
            $query = TenantMember::where('account_id', $accountId)->where('id', '>', $cursor)->field('id,tenant_id')->order('id')->limit(100);
            if ($lock) {
                $query->lock('FOR UPDATE NOWAIT');
            }
            $rows = $query->select()->toArray();
            foreach ($rows as $row) {
                $cursor = (int) $row['id'];
                $tenants[(int) $row['tenant_id']] = true;
            }
        } while (count($rows) === 100);
        $ids = array_keys($tenants);
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    private function displayName(string $displayName): string
    {
        $displayName = trim($displayName);
        if ($displayName === '' || preg_match('//u', $displayName) !== 1 || mb_strlen($displayName, 'UTF-8') > 120) {
            throw AdminAccessException::invalid(
                'ACCOUNT_PROFILE_INVALID',
                'The display name must contain between 1 and 120 characters.',
            );
        }

        return $displayName;
    }

    private function avatarUri(?string $avatarUri): ?string
    {
        if ($avatarUri === null || trim($avatarUri) === '') {
            return null;
        }
        $avatarUri = trim($avatarUri);
        // The HTTP adapter verifies ownership of canonical file keys before calling this service.
        if (preg_match('/^file_[0-9a-f]{32}$/D', $avatarUri) === 1) {
            return $avatarUri;
        }
        $parts = parse_url($avatarUri);
        if (strlen($avatarUri) > 512
            || filter_var($avatarUri, FILTER_VALIDATE_URL) === false
            || !is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || !is_string($parts['host'] ?? null)
            || isset($parts['user'])
            || isset($parts['pass'])) {
            throw AdminAccessException::invalid('AVATAR_URI_INVALID', 'The avatar URI must be an absolute HTTPS URL.');
        }

        return $avatarUri;
    }

    private function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return ($local === '' ? '*' : substr($local, 0, 1)) . '***@' . $domain;
    }

    private function revokeLoginChallenges(int $accountId, string $now): void
    {
        LoginChallenge::where('account_id', $accountId)->where('status', 'active')->update([
            'status' => 'revoked',
            'revoked_at' => $now,
        ]);
    }

    /** @return array{account: int, ip: int} */
    private function passwordChangeDeniedCounts(int $accountId, string $ipAddress, string $now): array
    {
        $since = (new DateTimeImmutable($now, new DateTimeZone('UTC')))
            ->modify(self::PASSWORD_CHANGE_RATE_WINDOW)
            ->format('Y-m-d H:i:s.v');
        $base = AuthSecurityEvent::where('event_type', 'password_change_denied')
            ->where('outcome', 'denied')
            ->where('occurred_at', '>=', $since);

        return [
            'account' => (int) (clone $base)->where('account_id', $accountId)->count(),
            'ip' => (int) (clone $base)->where('ip_address', $ipAddress)->count(),
        ];
    }

    private function passwordChangeIpLockName(string $ipAddress): string
    {
        return 'pa-pwd:' . substr(hash('sha256', $ipAddress), 0, 57);
    }

    private function acquirePasswordChangeIpLock(string $lockName): void
    {
        $row = $this->driverConnection()->query('SELECT GET_LOCK(?, 10) AS acquired', [$lockName])[0] ?? null;
        if (!is_array($row) || (int) ($row['acquired'] ?? 0) !== 1) {
            throw new AdminAccessException('DATABASE_ERROR', 500, 'Could not serialize password change attempts.');
        }
    }

    private function releasePasswordChangeIpLock(string $lockName): void
    {
        try {
            $row = $this->driverConnection()->query('SELECT RELEASE_LOCK(?) AS released', [$lockName])[0] ?? null;
            if (is_array($row) && (int) ($row['released'] ?? 0) === 1) {
                return;
            }
        } catch (Throwable) {
            // Continue to connection-wide driver cleanup without replacing the committed outcome.
        }
        try {
            $this->driverConnection()->query('SELECT RELEASE_ALL_LOCKS()');
        } catch (Throwable) {
            // A broken request connection releases its advisory locks when it closes.
        }
    }

    /** MySQL advisory locks serialize password attempts across application workers. */
    private function driverConnection(): PDOConnection
    {
        $connection = Db::connect();
        if (!$connection instanceof PDOConnection) {
            throw new RuntimeException('PASSWORD_CHANGE_LOCK_DRIVER_UNSUPPORTED');
        }

        return $connection;
    }

    private function authEvent(
        string $eventType,
        string $outcome,
        ?string $reasonCode,
        TenantContext $actor,
        int $credentialId,
        string $ipAddress,
        ?string $userAgent,
        string $now,
    ): void {
        AuthSecurityEvent::insert([
            'audience' => 'tenant',
            'event_type' => $eventType,
            'outcome' => $outcome,
            'reason_code' => $reasonCode,
            'account_id' => $actor->accountId,
            'credential_id' => $credentialId,
            'session_key' => $actor->sessionKey,
            'request_id' => $actor->requestId,
            'ip_address' => $ipAddress,
            'user_agent_hash' => $userAgent === null ? null : hash('sha256', $userAgent),
            'occurred_at' => $now,
        ]);
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.v');
    }
}
