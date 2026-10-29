<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Auth;

use DateTimeImmutable;
use PeanutAdmin\Modules\Identity\Identity\AccountStatus;
use PeanutAdmin\Modules\Identity\Identity\CredentialStatus;
use PeanutAdmin\Kernel\Identity\EmailAddress;
use PeanutAdmin\Kernel\Identity\PasswordHasher;
use PeanutAdmin\Modules\Identity\Membership\TenantMemberStatus;
use think\facade\Db;
use PeanutAdmin\Modules\Identity\Tenancy\TenantStatus;
use SensitiveParameter;

final class TenantAuthService
{
    private const ACCESS_LIFETIME = '+15 minutes';
    private const REFRESH_LIFETIME = '+14 days';
    private const CHALLENGE_LIFETIME = '+5 minutes';
    private const RATE_WINDOW = '-15 minutes';
    private const RATE_LIMIT = 20;

    private readonly \PeanutAdmin\Kernel\Auth\TenantClient $client;

    public function __construct(
        private readonly TenantAuthRepository $repository,
        private readonly PasswordHasher $passwords,
        private readonly \PeanutAdmin\Kernel\Auth\Clock $clock,
        private readonly \PeanutAdmin\Kernel\Auth\TokenIssuer $tokens,
        private readonly string $identifierHmacKey,
        ?\PeanutAdmin\Kernel\Auth\TenantClientRegistry $clients = null,
        string $clientKey = 'admin-web',
    ) {
        $this->client = ($clients ?? \PeanutAdmin\Kernel\Auth\TenantClientRegistry::adminWeb())->require($clientKey);
    }

    public function client(): \PeanutAdmin\Kernel\Auth\TenantClient
    {
        return $this->client;
    }

    public function login(
        string $email,
        #[SensitiveParameter]
        string $plainPassword,
        ?string $tenantCode,
        string $ipAddress,
        ?string $userAgent,
        string $requestId,
        bool $autoEnterSingleTenant = true,
    ): TenantSelectionRequired|TenantAuthentication {
        $normalizedEmail = EmailAddress::fromString($email);
        $identifierHmac = hash_hmac(
            'sha256',
            $normalizedEmail->value(),
            $this->identifierHmacKey,
        );
        $now = $this->clock->now();
        $rateWindowStart = $now->modify(self::RATE_WINDOW);
        if (
            $this->repository->failedLoginCountByIp($ipAddress, $rateWindowStart) >= self::RATE_LIMIT
            || $this->repository->failedLoginCountByIdentifier(
                $identifierHmac,
                $rateWindowStart,
            ) >= self::RATE_LIMIT
        ) {
            $this->repository->recordSecurityEvent(
                'login_rate_limited',
                'denied',
                'rate_limited',
                null,
                null,
                null,
                $identifierHmac,
                $requestId,
                $ipAddress,
                $this->userAgentHash($userAgent),
                $now,
            );
            throw new \PeanutAdmin\Kernel\Auth\AuthException('AUTH_RATE_LIMITED', 429);
        }

        // Password padding belongs only to admitted password logins, not Token requests.
        // Keep the configured hasher and generate before acquiring credential locks.
        $dummyPasswordHash = $this->passwords->hash('peanut-admin-invalid-credential-pad');
        $result = Db::transaction(function () use (
            $normalizedEmail,
            $dummyPasswordHash,
            $plainPassword,
            $tenantCode,
            $ipAddress,
            $userAgent,
            $requestId,
            $identifierHmac,
            $now,
            $autoEnterSingleTenant,
        ): TenantSelectionRequired|TenantAuthentication|\PeanutAdmin\Kernel\Auth\AuthException {
            $credential = $this->repository->credentialByEmail($normalizedEmail, true);
            $hash = $credential === null ? $dummyPasswordHash : $credential->secretHash;
            $passwordValid = $this->passwords->verify($plainPassword, $hash);
            if ($credential === null || !$passwordValid || !$this->credentialCanAuthenticate($credential, $now)) {
                $this->repository->registerFailedLogin(
                    $credential,
                    $identifierHmac,
                    $ipAddress,
                    $this->userAgentHash($userAgent),
                    $requestId,
                    $now,
                );

                return new \PeanutAdmin\Kernel\Auth\AuthException('AUTH_INVALID_CREDENTIALS', 401);
            }

            $replacementSecretHash = $this->passwords->needsRehash($credential->secretHash)
                ? $this->passwords->hash($plainPassword)
                : null;
            $this->repository->registerSuccessfulLogin($credential, $replacementSecretHash, $now);
            if ($replacementSecretHash !== null) {
                $this->repository->recordSecurityEvent(
                    'credential_rehashed',
                    'success',
                    null,
                    $credential->accountId,
                    $credential->credentialId,
                    null,
                    $identifierHmac,
                    $requestId,
                    $ipAddress,
                    $this->userAgentHash($userAgent),
                    $now,
                );
            }
            $choices = $this->repository->availableTenants($credential->accountId, $tenantCode);
            if ($choices === []) {
                $this->repository->recordSecurityEvent(
                    'login_failed',
                    'denied',
                    'no_available_tenant',
                    $credential->accountId,
                    $credential->credentialId,
                    null,
                    $identifierHmac,
                    $requestId,
                    $ipAddress,
                    $this->userAgentHash($userAgent),
                    $now,
                );

                return new \PeanutAdmin\Kernel\Auth\AuthException('AUTH_NO_AVAILABLE_TENANT', 403);
            }

            if (count($choices) === 1 && $autoEnterSingleTenant) {
                $authentication = $this->createAuthentication(
                    $choices[0],
                    $ipAddress,
                    $userAgent,
                    $requestId,
                    $now,
                );
                $this->repository->recordSecurityEvent(
                    'login_succeeded',
                    'success',
                    null,
                    $credential->accountId,
                    $credential->credentialId,
                    $authentication->context->sessionKey,
                    $identifierHmac,
                    $requestId,
                    $ipAddress,
                    $this->userAgentHash($userAgent),
                    $now,
                );

                return $authentication;
            }

            return $this->createSelection(
                $credential->accountId,
                $choices,
                'tenant_login',
                null,
                $ipAddress,
                $userAgent,
                $requestId,
                $now,
            );
        });

        if ($result instanceof \PeanutAdmin\Kernel\Auth\AuthException) {
            throw $result;
        }

        return $result;
    }

    public function selectTenant(
        #[SensitiveParameter]
        string $challengeToken,
        int $tenantId,
        string $ipAddress,
        ?string $userAgent,
        string $requestId,
    ): TenantAuthentication {
        $this->assertPrefix($challengeToken, \PeanutAdmin\Kernel\Auth\TokenIssuer::CHALLENGE_PREFIX, 'AUTH_CHALLENGE_INVALID');
        $now = $this->clock->now();
        $result = Db::transaction(function () use (
            $challengeToken,
            $tenantId,
            $ipAddress,
            $userAgent,
            $requestId,
            $now,
        ): TenantAuthentication|\PeanutAdmin\Kernel\Auth\AuthException {
            $challenge = $this->repository->challengeByHash(hash('sha256', $challengeToken), true);
            if ($challenge === null) {
                $this->recordChallengeDenied(
                    null,
                    'challenge_not_found',
                    $requestId,
                    $ipAddress,
                    $userAgent,
                    $now,
                );

                return new \PeanutAdmin\Kernel\Auth\AuthException('AUTH_CHALLENGE_INVALID', 401);
            }
            if ($challenge->status === 'used') {
                $this->recordChallengeDenied(
                    $challenge,
                    'challenge_reused',
                    $requestId,
                    $ipAddress,
                    $userAgent,
                    $now,
                );

                return new \PeanutAdmin\Kernel\Auth\AuthException('AUTH_CHALLENGE_USED', 401);
            }
            if ($challenge->status !== 'active') {
                $this->recordChallengeDenied(
                    $challenge,
                    'challenge_inactive',
                    $requestId,
                    $ipAddress,
                    $userAgent,
                    $now,
                );

                return new \PeanutAdmin\Kernel\Auth\AuthException('AUTH_CHALLENGE_INVALID', 401);
            }
            if ($now >= $challenge->expiresAt) {
                $this->recordChallengeDenied(
                    $challenge,
                    'challenge_expired',
                    $requestId,
                    $ipAddress,
                    $userAgent,
                    $now,
                );

                return new \PeanutAdmin\Kernel\Auth\AuthException('AUTH_CHALLENGE_EXPIRED', 401);
            }
            if (!in_array($challenge->purpose, ['tenant_login', 'tenant_switch'], true)) {
                $this->recordChallengeDenied(
                    $challenge,
                    'challenge_purpose_invalid',
                    $requestId,
                    $ipAddress,
                    $userAgent,
                    $now,
                );

                return new \PeanutAdmin\Kernel\Auth\AuthException('AUTH_CHALLENGE_INVALID', 401);
            }
            if ($challenge->clientKey !== $this->client->key) {
                $this->recordChallengeDenied(
                    $challenge,
                    'challenge_client_mismatch',
                    $requestId,
                    $ipAddress,
                    $userAgent,
                    $now,
                );

                return new \PeanutAdmin\Kernel\Auth\AuthException('AUTH_CHALLENGE_INVALID', 401);
            }
            if (
                $challenge->ipAddress !== $ipAddress
                || $challenge->userAgentHash !== $this->userAgentHash($userAgent)
            ) {
                $this->recordChallengeDenied(
                    $challenge,
                    'challenge_risk_context_changed',
                    $requestId,
                    $ipAddress,
                    $userAgent,
                    $now,
                );

                return new \PeanutAdmin\Kernel\Auth\AuthException('AUTH_CHALLENGE_INVALID', 401);
            }

            $choice = null;
            foreach ($this->repository->availableTenants($challenge->accountId) as $candidate) {
                if ($candidate->tenantId === $tenantId) {
                    $choice = $candidate;
                    break;
                }
            }
            if ($choice === null) {
                return new \PeanutAdmin\Kernel\Auth\AuthException('AUTH_TENANT_UNAVAILABLE', 403);
            }

            $this->repository->markChallengeUsed($challenge->id, $now);
            $authentication = $this->createAuthentication(
                $choice,
                $ipAddress,
                $userAgent,
                $requestId,
                $now,
            );
            if ($challenge->purpose === 'tenant_switch' && $challenge->sourceSessionKey !== null) {
                $this->repository->revokeSessionByKey(
                    $challenge->sourceSessionKey,
                    'tenant_switched',
                    $now,
                );
            }
            $this->repository->recordSecurityEvent(
                'challenge_consumed',
                'success',
                null,
                $challenge->accountId,
                null,
                $authentication->context->sessionKey,
                null,
                $requestId,
                $ipAddress,
                $this->userAgentHash($userAgent),
                $now,
            );

            return $authentication;
        });

        if ($result instanceof \PeanutAdmin\Kernel\Auth\AuthException) {
            throw $result;
        }

        return $result;
    }

    public function refresh(
        #[SensitiveParameter]
        string $refreshToken,
        #[SensitiveParameter]
        string $expectedAccessToken,
        string $ipAddress,
        ?string $userAgent,
        string $requestId,
        int $expectedTenantId,
    ): TenantAuthentication {
        $this->assertTenantTokenPrefix($refreshToken, \PeanutAdmin\Kernel\Auth\TokenIssuer::TENANT_REFRESH_PREFIX);
        $this->assertTenantTokenPrefix($expectedAccessToken, \PeanutAdmin\Kernel\Auth\TokenIssuer::TENANT_ACCESS_PREFIX);
        $now = $this->clock->now();
        $result = Db::transaction(function () use (
            $refreshToken,
            $expectedAccessToken,
            $ipAddress,
            $userAgent,
            $requestId,
            $expectedTenantId,
            $now,
        ): TenantAuthentication|\PeanutAdmin\Kernel\Auth\AuthException {
            // Access expiry/rotation is why refresh is needed. Only its immutable
            // session/tenant binding is used; current validity is checked on the locked refresh record.
            $expected = $this->repository->sessionByTokenHash(
                hash('sha256', $expectedAccessToken),
                'access',
            );
            if ($expected === null || $expected->clientKey !== $this->client->key
                || $expected->tenantId !== $expectedTenantId) {
                return new \PeanutAdmin\Kernel\Auth\AuthException('AUTH_TOKEN_INVALID', 401);
            }
            $record = $this->repository->sessionByTokenHash(
                hash('sha256', $refreshToken),
                'refresh',
                true,
            );
            if ($record === null) {
                return new \PeanutAdmin\Kernel\Auth\AuthException('AUTH_TOKEN_INVALID', 401);
            }
            if ($record->clientKey !== $this->client->key || $record->sessionId !== $expected->sessionId) {
                return new \PeanutAdmin\Kernel\Auth\AuthException('AUTH_TOKEN_INVALID', 401);
            }
            if ($record->tokenStatus === 'used') {
                $this->repository->revokeSession($record->sessionId, 'refresh_reused', $now);
                $this->repository->recordSecurityEvent(
                    'token_reused',
                    'denied',
                    'refresh_reused',
                    $record->accountId,
                    null,
                    $record->sessionKey,
                    null,
                    $requestId,
                    $ipAddress,
                    $this->userAgentHash($userAgent),
                    $now,
                );

                return new \PeanutAdmin\Kernel\Auth\AuthException('AUTH_REFRESH_REUSED', 401);
            }

            $invalid = $this->tokenFailure($record, 'refresh', $now)
                ?? $this->sessionFailure($record, $now);
            if ($invalid !== null) {
                $this->repository->revokeSession($record->sessionId, 'session_invalid', $now);

                return $invalid;
            }

            $tokens = $this->issuePair($now, $record->absoluteExpiresAt);
            $this->repository->rotateTokens($record, $tokens, $now);
            $context = \PeanutAdmin\Kernel\Auth\TenantContext::fromValidatedSession($record->validated(), $requestId);
            $this->repository->recordSecurityEvent(
                'token_refreshed',
                'success',
                null,
                $record->accountId,
                null,
                $record->sessionKey,
                null,
                $requestId,
                $ipAddress,
                $this->userAgentHash($userAgent),
                $now,
            );

            return new TenantAuthentication($tokens, $context);
        });

        if ($result instanceof \PeanutAdmin\Kernel\Auth\AuthException) {
            throw $result;
        }

        return $result;
    }

    public function context(
        #[SensitiveParameter]
        string $accessToken,
        string $requestId,
    ): \PeanutAdmin\Kernel\Auth\TenantContext {
        return \PeanutAdmin\Kernel\Auth\TenantContext::fromValidatedSession(
            $this->validatedAccessSession($accessToken, false),
            $requestId,
        );
    }

    public function logout(#[SensitiveParameter] string $accessToken, string $requestId): void
    {
        $session = $this->validatedAccessSession($accessToken);
        $now = $this->clock->now();
        Db::transaction(function () use ($session, $requestId, $now): void {
            $this->repository->revokeSession($session->sessionId, 'logout', $now);
            $this->repository->recordSecurityEvent(
                'session_revoked',
                'success',
                'logout',
                $session->accountId,
                null,
                $session->sessionKey,
                null,
                $requestId,
                null,
                null,
                $now,
            );
        });
    }

    public function logoutAll(#[SensitiveParameter] string $accessToken, string $requestId): void
    {
        $session = $this->validatedAccessSession($accessToken);
        $now = $this->clock->now();
        Db::transaction(function () use ($session, $requestId, $now): void {
            $this->repository->revokeSessionsForAccount($session->accountId, 'logout_all', $now);
            $this->repository->recordSecurityEvent(
                'session_revoked',
                'success',
                'logout_all',
                $session->accountId,
                null,
                $session->sessionKey,
                null,
                $requestId,
                null,
                null,
                $now,
            );
        });
    }

    public function switchChallenge(
        #[SensitiveParameter]
        string $accessToken,
        string $ipAddress,
        ?string $userAgent,
        string $requestId,
    ): TenantSelectionRequired {
        $result = Db::transaction(function () use (
            $accessToken,
            $ipAddress,
            $userAgent,
            $requestId,
        ): TenantSelectionRequired|\PeanutAdmin\Kernel\Auth\AuthException {
            try {
                $session = $this->validatedAccessSession($accessToken);
            } catch (\PeanutAdmin\Kernel\Auth\AuthException $exception) {
                return $exception;
            }
            $choices = array_values(array_filter(
                $this->repository->availableTenants($session->accountId),
                static fn(TenantChoice $choice): bool => $choice->tenantId !== $session->tenantId,
            ));
            if ($choices === []) {
                throw new \PeanutAdmin\Kernel\Auth\AuthException('AUTH_NO_AVAILABLE_TENANT', 403);
            }

            return $this->createSelection(
                $session->accountId,
                $choices,
                'tenant_switch',
                $session->sessionKey,
                $ipAddress,
                $userAgent,
                $requestId,
                $this->clock->now(),
            );
        });

        if ($result instanceof \PeanutAdmin\Kernel\Auth\AuthException) {
            throw $result;
        }

        return $result;
    }

    private function validatedAccessSession(string $accessToken, bool $forUpdate = true): \PeanutAdmin\Kernel\Auth\ValidatedTenantSession
    {
        $this->assertTenantTokenPrefix($accessToken, \PeanutAdmin\Kernel\Auth\TokenIssuer::TENANT_ACCESS_PREFIX);
        // Normal HTTP context reads never lock authority rows. Existing write callers
        // and calls inside transactions retain current locking reads, not MVCC snapshots.
        $pdo = Db::connect()->getPdo();
        if (!$forUpdate && (!$pdo instanceof \PDO || !$pdo->inTransaction())) {
            $record = $this->repository->sessionByTokenHash(hash('sha256', $accessToken), 'access');
            $now = $this->clock->now();
            if ($record === null || $record->clientKey !== $this->client->key) {
                throw new \PeanutAdmin\Kernel\Auth\AuthException('AUTH_TOKEN_INVALID', 401);
            }
            $tokenFailure = $this->tokenFailure($record, 'access', $now);
            if ($tokenFailure !== null) {
                throw $tokenFailure;
            }
            if ($this->sessionFailure($record, $now) === null) {
                return $record->validated();
            }
        }

        $result = Db::transaction(function () use ($accessToken): \PeanutAdmin\Kernel\Auth\ValidatedTenantSession|\PeanutAdmin\Kernel\Auth\AuthException {
            $record = $this->repository->sessionByTokenHash(
                hash('sha256', $accessToken),
                'access',
                true,
            );
            $now = $this->clock->now();
            if ($record === null) {
                return new \PeanutAdmin\Kernel\Auth\AuthException('AUTH_TOKEN_INVALID', 401);
            }
            if ($record->clientKey !== $this->client->key) {
                return new \PeanutAdmin\Kernel\Auth\AuthException('AUTH_TOKEN_INVALID', 401);
            }

            $tokenFailure = $this->tokenFailure($record, 'access', $now);
            if ($tokenFailure !== null) {
                return $tokenFailure;
            }
            $failure = $this->sessionFailure($record, $now);
            if ($failure !== null) {
                $this->repository->revokeSession($record->sessionId, 'session_invalid', $now);
                return $failure;
            }
            return $record->validated();
        });

        if ($result instanceof \PeanutAdmin\Kernel\Auth\AuthException) {
            throw $result;
        }

        return $result;
    }

    private function credentialCanAuthenticate(AuthCredential $credential, DateTimeImmutable $now): bool
    {
        if ($credential->accountStatus !== AccountStatus::Active) {
            return false;
        }
        if ($credential->expiresAt !== null && $now >= $credential->expiresAt) {
            return false;
        }

        return match ($credential->credentialStatus) {
            CredentialStatus::Active => true,
            CredentialStatus::Locked => $credential->lockedUntil !== null
                && $now >= $credential->lockedUntil,
            CredentialStatus::Revoked => false,
        };
    }

    private function sessionFailure(
        SessionAuthenticationRecord $record,
        DateTimeImmutable $now,
    ): ?\PeanutAdmin\Kernel\Auth\AuthException {
        if (
            $now >= $record->idleExpiresAt
            || $now >= $record->absoluteExpiresAt
        ) {
            return new \PeanutAdmin\Kernel\Auth\AuthException('AUTH_SESSION_EXPIRED', 401);
        }
        if ($record->sessionStatus !== 'active') {
            return new \PeanutAdmin\Kernel\Auth\AuthException('AUTH_SESSION_REVOKED', 401);
        }
        if (
            $record->accountStatus !== AccountStatus::Active
            || $record->accountSecurityRevision !== $record->currentAccountSecurityRevision
        ) {
            return new \PeanutAdmin\Kernel\Auth\AuthException('AUTH_ACCOUNT_UNAVAILABLE', 401);
        }
        if (
            $record->tenantStatus !== TenantStatus::Active
            || $record->tenantSecurityRevision !== $record->currentTenantSecurityRevision
        ) {
            return new \PeanutAdmin\Kernel\Auth\AuthException('AUTH_TENANT_UNAVAILABLE', 403);
        }
        if (
            $record->memberStatus !== TenantMemberStatus::Active
            || $record->memberSecurityRevision !== $record->currentMemberSecurityRevision
        ) {
            return new \PeanutAdmin\Kernel\Auth\AuthException('AUTH_MEMBER_UNAVAILABLE', 403);
        }

        return null;
    }

    private function tokenFailure(
        SessionAuthenticationRecord $record,
        string $expectedType,
        DateTimeImmutable $now,
    ): ?\PeanutAdmin\Kernel\Auth\AuthException {
        if ($record->tokenType !== $expectedType || $record->tokenStatus !== 'active') {
            return new \PeanutAdmin\Kernel\Auth\AuthException('AUTH_TOKEN_INVALID', 401);
        }
        if ($now >= $record->tokenExpiresAt) {
            return new \PeanutAdmin\Kernel\Auth\AuthException('AUTH_SESSION_EXPIRED', 401);
        }

        return null;
    }

    /** @param non-empty-list<TenantChoice> $choices */
    private function createSelection(
        int $accountId,
        array $choices,
        string $purpose,
        ?string $sourceSessionKey,
        string $ipAddress,
        ?string $userAgent,
        string $requestId,
        DateTimeImmutable $now,
    ): TenantSelectionRequired {
        $challenge = $this->tokens->challenge();
        $expiresAt = $now->modify(self::CHALLENGE_LIFETIME);
        $this->repository->createChallenge(
            $accountId,
            $this->tokens->key($now),
            $challenge->hash(),
            $purpose,
            $this->client->key,
            $sourceSessionKey,
            $ipAddress,
            $this->userAgentHash($userAgent),
            $expiresAt,
            $now,
        );
        $this->repository->recordSecurityEvent(
            'challenge_issued',
            'success',
            null,
            $accountId,
            null,
            $sourceSessionKey,
            null,
            $requestId,
            $ipAddress,
            $this->userAgentHash($userAgent),
            $now,
        );

        return new TenantSelectionRequired($challenge, $expiresAt, $choices);
    }

    private function createAuthentication(
        TenantChoice $choice,
        string $ipAddress,
        ?string $userAgent,
        string $requestId,
        DateTimeImmutable $now,
    ): TenantAuthentication {
        $tokens = $this->issuePair($now, $now->modify(self::REFRESH_LIFETIME));
        $session = $this->repository->createSession(
            $choice,
            $this->tokens->key($now),
            $tokens,
            $this->client->key,
            $ipAddress,
            $this->userAgentHash($userAgent),
            $now,
        );

        return new TenantAuthentication(
            $tokens,
            \PeanutAdmin\Kernel\Auth\TenantContext::fromValidatedSession($session, $requestId),
        );
    }

    private function recordChallengeDenied(
        ?LoginChallengeRecord $challenge,
        string $reasonCode,
        string $requestId,
        string $ipAddress,
        ?string $userAgent,
        DateTimeImmutable $now,
    ): void {
        $this->repository->recordSecurityEvent(
            'challenge_denied',
            'denied',
            $reasonCode,
            $challenge?->accountId,
            null,
            $challenge?->sourceSessionKey,
            null,
            $requestId,
            $ipAddress,
            $this->userAgentHash($userAgent),
            $now,
        );
    }

    private function issuePair(DateTimeImmutable $now, DateTimeImmutable $absoluteExpiresAt): TenantTokenPair
    {
        $accessExpiresAt = $now->modify(self::ACCESS_LIFETIME);
        if ($accessExpiresAt > $absoluteExpiresAt) {
            $accessExpiresAt = $absoluteExpiresAt;
        }

        return new TenantTokenPair(
            $this->tokens->tenantAccess(),
            $this->tokens->tenantRefresh(),
            $accessExpiresAt,
            $absoluteExpiresAt,
        );
    }

    private function userAgentHash(?string $userAgent): ?string
    {
        return $userAgent === null ? null : hash('sha256', $userAgent);
    }

    private function assertTenantTokenPrefix(string $token, string $expected): void
    {
        if (str_starts_with($token, \PeanutAdmin\Kernel\Auth\TokenIssuer::PLATFORM_ACCESS_PREFIX) || str_starts_with($token, \PeanutAdmin\Kernel\Auth\TokenIssuer::PLATFORM_REFRESH_PREFIX)) {
            throw new \PeanutAdmin\Kernel\Auth\AuthException('AUTH_AUDIENCE_MISMATCH', 401);
        }
        $this->assertPrefix($token, $expected, 'AUTH_TOKEN_INVALID');
    }

    private function assertPrefix(string $token, string $expected, string $errorCode): void
    {
        if (!str_starts_with($token, $expected)) {
            throw new \PeanutAdmin\Kernel\Auth\AuthException($errorCode, 401);
        }
    }
}
