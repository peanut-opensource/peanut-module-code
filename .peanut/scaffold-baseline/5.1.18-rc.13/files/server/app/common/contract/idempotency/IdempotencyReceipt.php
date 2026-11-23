<?php

declare(strict_types=1);

namespace app\common\contract\idempotency;

final readonly class IdempotencyReceipt
{
    /** @param array<string, mixed> $body */
    public function __construct(
        public int $status,
        public array $body,
        public ?string $resourceType = null,
        public ?string $resourceId = null,
    ) {}
}
