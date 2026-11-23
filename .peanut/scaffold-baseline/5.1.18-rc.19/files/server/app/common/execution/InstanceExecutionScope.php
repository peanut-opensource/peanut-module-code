<?php

declare(strict_types=1);

namespace app\common\execution;

/** Trusted scope for instance-owned CLI and maintenance work. */
final readonly class InstanceExecutionScope
{
    public function __construct(
        public string $actorKey,
        public string $host,
    ) {
        if (trim($this->actorKey) === '' || trim($this->host) === '') {
            throw new \DomainException('INSTANCE_EXECUTION_SCOPE_INVALID');
        }
    }
}
