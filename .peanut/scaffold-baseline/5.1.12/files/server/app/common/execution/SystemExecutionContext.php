<?php

declare(strict_types=1);

namespace app\common\execution;

use PeanutAdmin\Kernel\Context\TenantSystemContext;

final readonly class SystemExecutionContext implements ExecutionContext
{
    public function __construct(
        public TenantSystemContext $system,
        public SystemExecutionMetadata $metadata = new SystemExecutionMetadata(),
    ) {
        if (trim($this->system->operation) === '' || trim($this->system->operationId) === '') {
            throw new \DomainException('EXECUTION_CONTEXT_UNTRUSTED');
        }
    }

    public function operation(): string
    {
        return $this->system->operation;
    }
    public function requestId(): string
    {
        return $this->system->operationId;
    }
    public function tenantId(): int
    {
        return $this->system->tenantId;
    }
    public function actor(): array
    {
        return ['tenant_id' => $this->system->tenantId, 'actor_key' => $this->system->actorKey];
    }
}
