<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Auth;

use DateTimeImmutable;
use PeanutAdmin\Modules\Identity\Identity\AccountStatus;
use PeanutAdmin\Modules\Identity\Membership\TenantMemberStatus;
use PeanutAdmin\Modules\Identity\Tenancy\TenantStatus;

final readonly class SessionAuthenticationRecord
{
    public function __construct(
        public int $tokenId,
        public string $tokenType,
        public string $tokenStatus,
        public DateTimeImmutable $tokenExpiresAt,
        public int $sessionId,
        public string $sessionKey,
        public string $sessionStatus,
        public int $tenantId,
        public int $accountId,
        public int $memberId,
        public string $clientKey,
        public DateTimeImmutable $issuedAt,
        public DateTimeImmutable $idleExpiresAt,
        public DateTimeImmutable $absoluteExpiresAt,
        public int $accountSecurityRevision,
        public int $tenantSecurityRevision,
        public int $memberSecurityRevision,
        public AccountStatus $accountStatus,
        public int $currentAccountSecurityRevision,
        public TenantStatus $tenantStatus,
        public int $currentTenantSecurityRevision,
        public TenantMemberStatus $memberStatus,
        public int $currentMemberSecurityRevision,
        public int $authorizationRevision,
    ) {}

    public function validated(): \PeanutAdmin\Kernel\Auth\ValidatedTenantSession
    {
        return new \PeanutAdmin\Kernel\Auth\ValidatedTenantSession(
            $this->sessionId,
            $this->sessionKey,
            $this->tenantId,
            $this->accountId,
            $this->memberId,
            $this->clientKey,
            $this->issuedAt,
            $this->authorizationRevision,
        );
    }
}
