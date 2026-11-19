<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Contract\Dto;

final readonly class EnrollDeviceCredentialInput
{
    public function __construct(
        public string $clientKey,
        public string $deviceFingerprintHash,
        public string $credentialPublicKey,
        public string $credentialSignature,
        public string $commandKey,
    ) {}
}
