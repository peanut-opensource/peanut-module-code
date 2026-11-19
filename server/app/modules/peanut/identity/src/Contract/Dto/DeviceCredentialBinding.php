<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Contract\Dto;

final readonly class DeviceCredentialBinding
{
    public function __construct(
        public string $credentialKey,
        public string $revision,
        public string $clientKey,
        public string $deviceFingerprintHash,
        public string $keyFingerprint,
        public string $installationKey,
        public string $status,
        public string $revocationEpoch,
        public string $boundAt,
    ) {}
}
