<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Task\Contract;

/** Identifiers to match against native Task evidence; this value is not an authorization token. */
final readonly class SourceTaskBinding
{
    public function __construct(
        public string $jobKey,
        public string $moduleKey,
        public string $resourceKey,
        public string $operation,
        public string $handlerKey,
    ) {
        if (preg_match('/^job_[0-9a-f]{32}$/D', $jobKey) !== 1) {
            throw new \DomainException('TASK_SOURCE_BINDING_INVALID');
        }
        foreach ([$moduleKey, $resourceKey, $operation, $handlerKey] as $value) {
            if (strlen($value) > 128 || preg_match('/^[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*$/D', $value) !== 1) {
                throw new \DomainException('TASK_SOURCE_BINDING_INVALID');
            }
        }
    }

    /** @return array<string,string> */
    public function toArray(): array
    {
        return ['job_key' => $this->jobKey, 'module_key' => $this->moduleKey, 'resource_key' => $this->resourceKey,
            'operation' => $this->operation, 'handler_key' => $this->handlerKey];
    }
}
