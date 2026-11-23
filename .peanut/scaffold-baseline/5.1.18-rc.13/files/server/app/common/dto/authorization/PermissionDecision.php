<?php

declare(strict_types=1);

namespace app\common\dto\authorization;

final readonly class PermissionDecision
{
    private function __construct(
        public bool $allowed,
        public string $accessUri,
        public ?string $reason,
    ) {}

    public static function allow(string $accessUri): self
    {
        return new self(true, $accessUri, null);
    }

    public static function deny(string $accessUri, string $reason): self
    {
        return new self(false, $accessUri, $reason);
    }
}
