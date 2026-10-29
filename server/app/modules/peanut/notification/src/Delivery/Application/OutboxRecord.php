<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Notification\Delivery\Application;

final readonly class OutboxRecord
{
    public function __construct(
        public string $outboxKey,
        public int $tenantId,
        public string $channel,
        public string $status,
        public ?string $jobKey,
    ) {}
}
