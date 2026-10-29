<?php

declare(strict_types=1);

namespace app\common\execution;

/** Process-local context stack; every boundary must restore it in finally. */
final class ExecutionContextStore
{
    /** @var list<ExecutionContext> */
    private array $stack = [];
    private ?object $rootIdentity = null;

    /** Opaque identity of the current outer run; it grants no authority. */
    public function rootIdentity(): ?object
    {
        return $this->rootIdentity;
    }

    public function current(): ?ExecutionContext
    {
        $context = end($this->stack);
        return $context instanceof ExecutionContext ? $context : null;
    }

    public function require(): ExecutionContext
    {
        return $this->current()
            ?? throw new \DomainException('EXECUTION_CONTEXT_REQUIRED');
    }

    public function run(ExecutionContext $context, callable $operation): mixed
    {
        $current = $this->current();
        if ($current !== null && $current::class !== $context::class) {
            throw new \DomainException('EXECUTION_AUDIENCE_CONTEXT_MISMATCH');
        }
        $currentTenantId = $current?->tenantId();
        $nextTenantId = $context->tenantId();
        if ($currentTenantId !== null
            && $nextTenantId !== null
            && $currentTenantId !== $nextTenantId) {
            throw new \DomainException('EXECUTION_TENANT_CONTEXT_MISMATCH');
        }
        $isRoot = $current === null;
        if ($isRoot) {
            $this->rootIdentity = new \stdClass();
        }
        $this->stack[] = $context;
        try {
            return $operation();
        } finally {
            $restored = array_pop($this->stack);
            if ($restored !== $context) {
                $this->stack = [];
                $this->rootIdentity = null;
                throw new \LogicException('EXECUTION_CONTEXT_STACK_CORRUPTED');
            }
            if ($isRoot) {
                $this->rootIdentity = null;
            }
        }
    }

    public function isEmpty(): bool
    {
        return $this->stack === [];
    }
}
