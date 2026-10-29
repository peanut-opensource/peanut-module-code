<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Notification\Contract;

final readonly class DeliveryResult
{
    /** @param array<string,mixed> $receipt */
    public function __construct(
        public bool $success,
        public string $provider,
        public string $error = '',
        public array $receipt = [],
    ) {}
}
