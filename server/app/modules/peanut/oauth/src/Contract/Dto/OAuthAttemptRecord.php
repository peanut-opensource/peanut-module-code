<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\OAuth\Contract\Dto;

final readonly class OAuthAttemptRecord
{
    public function __construct(
        public int $id,
        public string $scene,
        public string $returnPath,
        public int $expiresAt,
        public ?int $usedAt,
    ) {}
}
