<?php

declare(strict_types=1);

namespace app\common\execution;

use app\platform\context\PlatformOperatorContext;

final readonly class PlatformExecutionContext implements ExecutionContext
{
    public function __construct(public PlatformOperatorContext $platform, private string $operation)
    {
        if (trim($this->operation) === '') {
            throw new \DomainException('EXECUTION_CONTEXT_UNTRUSTED');
        }
    }

    public function operation(): string
    {
        return trim($this->operation);
    }
    public function requestId(): string
    {
        return $this->platform->core->requestId;
    }
    public function tenantId(): ?int
    {
        return null;
    }

    public function actor(): array
    {
        return ['account_id' => $this->platform->core->accountId, 'id' => $this->platform->core->operatorId];
    }
}
