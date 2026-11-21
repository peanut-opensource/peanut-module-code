<?php

declare(strict_types=1);

namespace app\common\execution;

use PeanutAdmin\Kernel\Context\AuthenticatedMemberContext;
use PeanutAdmin\Kernel\Context\TenantSystemContext;

final readonly class ConsumerExecutionContext implements ExecutionContext
{
    private function __construct(
        public ?AuthenticatedMemberContext $member,
        public ?TenantSystemContext $publicTenant,
        private string $operation,
        private string $requestId,
    ) {
        if (trim($this->operation) === '' || trim($this->requestId) === ''
            || ($this->member !== null && $this->publicTenant !== null)) {
            throw new \DomainException('EXECUTION_CONTEXT_UNTRUSTED');
        }
    }

    public static function member(AuthenticatedMemberContext $context, string $operation): self
    {
        return new self($context, null, $operation, $context->requestId);
    }

    public static function publicTenant(TenantSystemContext $context): self
    {
        return new self(null, $context, $context->operation, $context->operationId);
    }

    public static function anonymous(string $operation, string $requestId): self
    {
        return new self(null, null, $operation, $requestId);
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
        return $this->member?->tenantId ?? $this->publicTenant?->tenantId;
    }

    public function actor(): array
    {
        if ($this->member !== null) {
            return ['tenant_id' => $this->member->tenantId, 'id' => $this->member->memberId];
        }
        if ($this->publicTenant !== null) {
            return ['tenant_id' => $this->publicTenant->tenantId, 'actor_key' => $this->publicTenant->actorKey];
        }
        return ['actor_key' => 'anonymous'];
    }
}
