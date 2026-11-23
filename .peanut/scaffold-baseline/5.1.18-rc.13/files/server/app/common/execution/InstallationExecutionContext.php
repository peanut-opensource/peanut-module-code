<?php

declare(strict_types=1);

namespace app\common\execution;

final readonly class InstallationExecutionContext implements ExecutionContext
{
    public function __construct(private string $operation, private string $requestId)
    {
        if (trim($this->operation) === '' || trim($this->requestId) === '') {
            throw new \DomainException('EXECUTION_CONTEXT_UNTRUSTED');
        }
    }

    public function operation(): string
    {
        return trim($this->operation);
    }
    public function requestId(): string
    {
        return trim($this->requestId);
    }
    public function tenantId(): ?int
    {
        return null;
    }
    public function actor(): array
    {
        return ['actor_key' => 'installation'];
    }
}
