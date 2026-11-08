<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Auth;

use DateTimeImmutable;
use PeanutAdmin\Modules\Identity\Identity\AccountStatus;
use PeanutAdmin\Modules\Identity\Platform\PlatformOperatorStatus;

final readonly class PlatformSessionAuthenticationRecord
{
    public function __construct(
        public int $tokenId,
        public string $tokenType,
        public string $tokenStatus,
        public DateTimeImmutable $tokenExpiresAt,
        public int $sessionId,
        public string $sessionKey,
        public string $sessionStatus,
        public int $accountId,
        public int $operatorId,
        public string $clientKey,
        public DateTimeImmutable $issuedAt,
        public DateTimeImmutable $idleExpiresAt,
        public DateTimeImmutable $absoluteExpiresAt,
        public int $accountSecurityRevision,
        public int $operatorSecurityRevision,
        public AccountStatus $accountStatus,
        public int $currentAccountSecurityRevision,
        public PlatformOperatorStatus $operatorStatus,
        public int $currentOperatorSecurityRevision,
    ) {}

    public function validated(): \PeanutAdmin\Kernel\Auth\ValidatedPlatformSession
    {
        return new \PeanutAdmin\Kernel\Auth\ValidatedPlatformSession(
            $this->sessionId,
            $this->sessionKey,
            $this->accountId,
            $this->operatorId,
            $this->clientKey,
            $this->issuedAt,
        );
    }
}
