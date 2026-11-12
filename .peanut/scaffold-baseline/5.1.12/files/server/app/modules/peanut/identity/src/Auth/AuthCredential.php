<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Auth;

use DateTimeImmutable;
use PeanutAdmin\Modules\Identity\Identity\AccountStatus;
use PeanutAdmin\Modules\Identity\Identity\CredentialStatus;

final readonly class AuthCredential
{
    public function __construct(
        public int $credentialId,
        public int $accountId,
        public string $secretHash,
        public CredentialStatus $credentialStatus,
        public int $failedAttempts,
        public ?DateTimeImmutable $lockedUntil,
        public ?DateTimeImmutable $expiresAt,
        public AccountStatus $accountStatus,
    ) {}
}
