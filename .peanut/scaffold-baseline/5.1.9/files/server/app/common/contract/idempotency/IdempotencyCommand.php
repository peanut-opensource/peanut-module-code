<?php

declare(strict_types=1);

namespace app\common\contract\idempotency;

use DateTimeImmutable;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Idempotency\IdempotencyKey;

final readonly class IdempotencyCommand
{
    private function __construct(
        public TenantContext $context,
        public string $operationKey,
        public IdempotencyKey $key,
        public string $requestHash,
        public DateTimeImmutable $expiresAt,
    ) {}

    public static function tenant(
        TenantContext $context,
        string $operationKey,
        string $rawKey,
        string $requestHash,
        DateTimeImmutable $expiresAt,
    ): self {
        return new self(
            $context,
            $operationKey,
            IdempotencyKey::fromString($rawKey),
            $requestHash,
            $expiresAt,
        );
    }
}
