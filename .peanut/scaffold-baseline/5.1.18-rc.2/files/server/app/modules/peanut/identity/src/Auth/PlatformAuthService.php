<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Auth;

use app\common\policy\audit\RedactionPolicy;
use DateTimeImmutable;
use PeanutAdmin\Kernel\Context\PlatformContext;
use PeanutAdmin\Modules\Identity\Identity\AccountStatus;
use PeanutAdmin\Modules\Identity\Identity\CredentialStatus;
use PeanutAdmin\Kernel\Identity\EmailAddress;
use PeanutAdmin\Kernel\Identity\PasswordHasher;
use think\facade\Db;
use think\facade\Log;
use PeanutAdmin\Modules\Identity\Platform\PlatformOperatorStatus;
use SensitiveParameter;

final class PlatformAuthService
{
    private const RATE_WINDOW = '-15 minutes';
    private const RATE_LIMIT = 20;

    public function __construct(
        private readonly PlatformAuthRepository $repository,
        private readonly PasswordHasher $passwords,
        private readonly \PeanutAdmin\Kernel\Auth\Clock $clock,
        private readonly \PeanutAdmin\Kernel\Auth\TokenIssuer $tokens,
        private readonly string $identifierHmacKey,
    ) {}

    public function login(
        string $email,
        #[SensitiveParameter]
        string $plainPassword,
        string $ipAddress,
        ?string $userAgent,
        string $requestId,
    ): PlatformAuthentication {
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
            $this->repository->recordEvent(
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
        $result = Db::transaction(function () use (
            $normalizedEmail,
            $plainPassword,
            $identifierHmac,
            $ipAddress,
            $userAgent,
            $requestId,
            $now,
        ): PlatformAuthentication|\PeanutAdmin\Kernel\Auth\AuthException {
            $principal = $this->repository->principalByEmail($normalizedEmail, true);
            $hash = $principal === null ? PasswordHasher::INVALID_CREDENTIAL_HASH : $principal->secretHash;
            if (
                !$this->passwords->verify($plainPassword, $hash)
                || $principal === null
                || !$this->principalIsActive($principal, $now)
            ) {
                $this->repository->registerFailedLogin(
                    $principal,
                    $identifierHmac,
                    $ipAddress,
                    $this->userAgentHash($userAgent),
                    $requestId,
                    $now,
                );

                return new \PeanutAdmin\Kernel\Auth\AuthException('AUTH_INVALID_CREDENTIALS', 401);
            }

            $replacementSecretHash = $this->passwords->needsRehash($principal->secretHash)
                ? $this->passwords->hash($plainPassword)
                : null;
            $this->repository->registerSuccessfulLogin($principal, $replacementSecretHash, $now);
            if ($replacementSecretHash !== null) {
                $this->repository->recordEvent(
                    'credential_rehashed',
                    'success',
                    null,
                    $principal->accountId,
                    $principal->credentialId,
                    null,
                    $identifierHmac,
                    $requestId,
                    $ipAddress,
                    $this->userAgentHash($userAgent),
                    $now,
                );
            }
            $tokens = $this->issuePair($now, $now->modify('+14 days'));
            $session = $this->repository->createSession(
                $principal,
                $this->tokens->key($now),
                $tokens,
                $ipAddress,
                $this->userAgentHash($userAgent),
                $now,
            );
            $this->repository->recordEvent(
                'login_succeeded',
                'success',
                null,
                $principal->accountId,
                $principal->credentialId,
                $session->sessionKey,
                $identifierHmac,
                $requestId,
                $ipAddress,
                $this->userAgentHash($userAgent),
                $now,
            );

            return new PlatformAuthentication(
                $tokens,
                PlatformContext::fromValidatedSession($session, $requestId),
            );
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
    ): PlatformContext {
        return PlatformContext::fromValidatedSession(
            $this->validatedAccessSession($accessToken, $requestId, false),
            $requestId,
        );
    }

    public function refresh(
        #[SensitiveParameter]
        string $refreshToken,
        string $ipAddress,
        ?string $userAgent,
        string $requestId,
    ): PlatformAuthentication {
        $this->assertPlatformPrefix($refreshToken, \PeanutAdmin\Kernel\Auth\TokenIssuer::PLATFORM_REFRESH_PREFIX);
        $now = $this->clock->now();
        $result = Db::transaction(function () use (
            $refreshToken,
            $ipAddress,
            $userAgent,
            $requestId,
            $now,
        ): PlatformAuthentication|\PeanutAdmin\Kernel\Auth\AuthException {
            $record = $this->repository->sessionByTokenHash(
                hash('sha256', $refreshToken),
                'refresh',
                true,
            );
            if ($record === null) {
                return new \PeanutAdmin\Kernel\Auth\AuthException('AUTH_TOKEN_INVALID', 401);
            }
            if ($record->tokenStatus === 'used') {
                $this->repository->revokeSession($record->sessionId, 'refresh_reused', $now);

                return new \PeanutAdmin\Kernel\Auth\AuthException('AUTH_REFRESH_REUSED', 401);
            }
            $failure = $this->tokenFailure($record, 'refresh', $now)
                ?? $this->sessionFailure($record, $now);
            if ($failure !== null) {
                $this->repository->revokeSession($record->sessionId, 'session_invalid', $now);

                return $failure;
            }

            $tokens = $this->issuePair($now, $record->absoluteExpiresAt);
            $this->repository->rotateTokens($record, $tokens, $now);
            $this->repository->recordEvent(
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

            return new PlatformAuthentication(
                $tokens,
                PlatformContext::fromValidatedSession($record->validated(), $requestId),
            );
        });

        if ($result instanceof \PeanutAdmin\Kernel\Auth\AuthException) {
            throw $result;
        }

        return $result;
    }

    public function logout(#[SensitiveParameter] string $accessToken): void
    {
        $session = $this->validatedAccessSession($accessToken);
        $now = $this->clock->now();
        Db::transaction(function () use ($session, $now): void {
            $this->repository->revokeSession($session->sessionId, 'logout', $now);
        });
    }

    private function validatedAccessSession(string $accessToken, ?string $requestId = null, bool $forUpdate = true): \PeanutAdmin\Kernel\Auth\ValidatedPlatformSession
    {
        $this->assertPlatformPrefix($accessToken, \PeanutAdmin\Kernel\Auth\TokenIssuer::PLATFORM_ACCESS_PREFIX);
        // Keep write callers and existing transactions on the locking path.
        $pdo = Db::connect()->getPdo();
        if (!$forUpdate && (!$pdo instanceof \PDO || !$pdo->inTransaction())) {
            $record = $this->repository->sessionByTokenHash(hash('sha256', $accessToken), 'access');
            $now = $this->clock->now();
            if ($record === null) {
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

        $result = Db::transaction(function () use ($accessToken, $requestId): \PeanutAdmin\Kernel\Auth\ValidatedPlatformSession|\PeanutAdmin\Kernel\Auth\AuthException {
            $phase = 'session_lookup';
            try {
                $record = $this->repository->sessionByTokenHash(
                    hash('sha256', $accessToken),
                    'access',
                    true,
                );
                $now = $this->clock->now();
                if ($record === null) {
                    return new \PeanutAdmin\Kernel\Auth\AuthException('AUTH_TOKEN_INVALID', 401);
                }
                $phase = 'token_validation';
                $tokenFailure = $this->tokenFailure($record, 'access', $now);
                if ($tokenFailure !== null) {
                    return $tokenFailure;
                }
                $phase = 'session_validation';
                $failure = $this->sessionFailure($record, $now);
                if ($failure !== null) {
                    $phase = 'session_revocation';
                    $this->repository->revokeSession($record->sessionId, 'session_invalid', $now);

                    return $failure;
                }

                $phase = 'validated_session';
                return $record->validated();
            } catch (\Throwable $first) {
                // Preserve the callback failure before a native rollback can replace it.
                try {
                    $trace = [];
                    foreach (array_slice($first->getTrace(), 0, 5) as $frame) {
                        $trace[] = [
                            'class' => is_string($frame['class'] ?? null) ? $frame['class'] : null,
                            'function' => is_string($frame['function'] ?? null) ? $frame['function'] : null,
                            'line' => is_int($frame['line'] ?? null) ? $frame['line'] : null,
                        ];
                    }
                    Log::warning(RedactionPolicy::encode([
                        'event' => 'platform_access_session_callback_failed',
                        'attributes' => [
                            'phase' => $phase,
                            'request_id' => $requestId !== null && preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,63}$/D', $requestId) === 1 ? $requestId : null,
                            'exception_class' => $first::class,
                            'exception_file' => $first->getFile(),
                            'exception_line' => $first->getLine(),
                            'exception_trace' => $trace,
                        ],
                    ]));
                } catch (\Throwable) {
                    // Diagnostics must never replace the original authentication failure.
                }
                throw $first;
            }
        });

        if ($result instanceof \PeanutAdmin\Kernel\Auth\AuthException) {
            throw $result;
        }

        return $result;
    }

    private function principalIsActive(PlatformAuthPrincipal $principal, DateTimeImmutable $now): bool
    {
        if ($principal->accountStatus !== AccountStatus::Active) {
            return false;
        }
        if ($principal->expiresAt !== null && $now >= $principal->expiresAt) {
            return false;
        }
        $credentialIsAvailable = match ($principal->credentialStatus) {
            CredentialStatus::Active => true,
            CredentialStatus::Locked => $principal->lockedUntil !== null
                && $now >= $principal->lockedUntil,
            CredentialStatus::Revoked => false,
        };

        return $credentialIsAvailable
            && $principal->operatorStatus === PlatformOperatorStatus::Active;
    }

    private function sessionFailure(
        PlatformSessionAuthenticationRecord $record,
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
            || $record->operatorStatus !== PlatformOperatorStatus::Active
            || $record->operatorSecurityRevision !== $record->currentOperatorSecurityRevision
        ) {
            return new \PeanutAdmin\Kernel\Auth\AuthException('AUTH_ACCOUNT_UNAVAILABLE', 401);
        }

        return null;
    }

    private function tokenFailure(
        PlatformSessionAuthenticationRecord $record,
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

    private function issuePair(DateTimeImmutable $now, DateTimeImmutable $absolute): PlatformTokenPair
    {
        $accessExpiresAt = $now->modify('+15 minutes');
        if ($accessExpiresAt > $absolute) {
            $accessExpiresAt = $absolute;
        }

        return new PlatformTokenPair(
            $this->tokens->platformAccess(),
            $this->tokens->platformRefresh(),
            $accessExpiresAt,
            $absolute,
        );
    }

    private function assertPlatformPrefix(string $token, string $expected): void
    {
        if (str_starts_with($token, \PeanutAdmin\Kernel\Auth\TokenIssuer::TENANT_ACCESS_PREFIX) || str_starts_with($token, \PeanutAdmin\Kernel\Auth\TokenIssuer::TENANT_REFRESH_PREFIX)) {
            throw new \PeanutAdmin\Kernel\Auth\AuthException('AUTH_AUDIENCE_MISMATCH', 401);
        }
        if (!str_starts_with($token, $expected)) {
            throw new \PeanutAdmin\Kernel\Auth\AuthException('AUTH_TOKEN_INVALID', 401);
        }
    }

    private function userAgentHash(?string $userAgent): ?string
    {
        return $userAgent === null ? null : hash('sha256', $userAgent);
    }
}
