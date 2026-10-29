<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Notification\Contract;

final readonly class VerificationResult
{
    public function __construct(
        public bool $accepted,
        public string $error = '',
    ) {}
}
