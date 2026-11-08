<?php

declare(strict_types=1);

namespace app\common\execution;

final readonly class SystemExecutionMetadata
{
    public function __construct(
        public ?string $jobKey = null,
        public ?int $attemptNumber = null,
        public ?string $handlerKey = null,
    ) {}

    /** @return array<string,int|string> */
    public function toArray(): array
    {
        return array_filter([
            'job_key' => $this->jobKey,
            'attempt_number' => $this->attemptNumber,
            'handler_key' => $this->handlerKey,
        ], static fn(mixed $value): bool => $value !== null);
    }
}
