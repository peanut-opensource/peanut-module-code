<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Contract\Dto;

final readonly class RevokeDeviceCredentialInput
{
    public function __construct(
        public string $reasonNote,
        public string $commandKey,
        public string $expectedRevision,
    ) {}
}
