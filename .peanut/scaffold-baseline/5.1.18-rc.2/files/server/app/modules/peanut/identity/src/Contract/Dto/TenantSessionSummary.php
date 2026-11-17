<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Contract\Dto;

/** Redacted device/session projection safe for cross-Module self-service. */
final readonly class TenantSessionSummary
{
    public function __construct(
        public string $sessionKey,
        public string $clientKey,
        public string $status,
        public bool $current,
        public ?string $maskedIp,
        public ?string $userAgentFingerprint,
        public string $issuedAt,
        public string $lastSeenAt,
        public string $absoluteExpiresAt,
        public ?string $revokedAt,
        public int $authorizationRevision,
    ) {
        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/D', $sessionKey) !== 1
            || $clientKey === ''
            || !in_array($status, ['active', 'revoked', 'expired'], true)
            || $authorizationRevision < 1
        ) {
            throw new \InvalidArgumentException('TENANT_SESSION_SUMMARY_INVALID');
        }
    }
}
