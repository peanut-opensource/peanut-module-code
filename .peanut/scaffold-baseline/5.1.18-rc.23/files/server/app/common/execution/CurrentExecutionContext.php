<?php

declare(strict_types=1);

namespace app\common\execution;

use app\platform\context\PlatformOperatorContext;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Context\AuthenticatedMemberContext;
use PeanutAdmin\Kernel\Context\TenantSystemContext;

/** Typed, fail-closed access to the context established by the current boundary. */
final readonly class CurrentExecutionContext
{
    public function __construct(private ExecutionContextStore $store) {}

    public function get(): ExecutionContext
    {
        return $this->store->require();
    }

    public function current(): ?ExecutionContext
    {
        return $this->store->current();
    }

    public function tenantId(): int
    {
        return $this->get()->tenantId()
            ?? throw new \DomainException('EXECUTION_TENANT_CONTEXT_REQUIRED');
    }

    public function tenantAdmin(): TenantContext
    {
        $context = $this->get();
        if (!$context instanceof AdminExecutionContext) {
            throw new \DomainException('EXECUTION_TENANT_ADMIN_CONTEXT_REQUIRED');
        }
        return $context->tenant;
    }

    public function admin(): AdminExecutionContext
    {
        $context = $this->get();
        return $context instanceof AdminExecutionContext
            ? $context
            : throw new \DomainException('EXECUTION_TENANT_ADMIN_CONTEXT_REQUIRED');
    }

    public function member(): AuthenticatedMemberContext
    {
        $context = $this->get();
        if (!$context instanceof ConsumerExecutionContext || $context->member === null) {
            throw new \DomainException('EXECUTION_MEMBER_CONTEXT_REQUIRED');
        }
        return $context->member;
    }

    public function consumer(): ConsumerExecutionContext
    {
        $context = $this->get();
        return $context instanceof ConsumerExecutionContext
            ? $context
            : throw new \DomainException('EXECUTION_CONSUMER_CONTEXT_REQUIRED');
    }

    public function system(): TenantSystemContext
    {
        $context = $this->get();
        if (!$context instanceof SystemExecutionContext) {
            throw new \DomainException('EXECUTION_SYSTEM_CONTEXT_REQUIRED');
        }
        return $context->system;
    }

    public function systemExecution(): SystemExecutionContext
    {
        $context = $this->get();
        return $context instanceof SystemExecutionContext
            ? $context
            : throw new \DomainException('EXECUTION_SYSTEM_CONTEXT_REQUIRED');
    }

    public function platform(): PlatformOperatorContext
    {
        $context = $this->get();
        if (!$context instanceof PlatformExecutionContext) {
            throw new \DomainException('EXECUTION_PLATFORM_CONTEXT_REQUIRED');
        }
        return $context->platform;
    }

    public function instance(): InstanceExecutionScope
    {
        $context = $this->get();
        if (!$context instanceof InstanceExecutionContext) {
            throw new \DomainException('EXECUTION_INSTANCE_CONTEXT_REQUIRED');
        }
        return $context->instance;
    }

    /** @return array<string,int|string> */
    public function actor(): array
    {
        return $this->get()->actor();
    }

    /** @return array<string,mixed> */
    public function tenantAdminPrincipal(): array
    {
        $context = $this->get();
        if (!$context instanceof AdminExecutionContext) {
            throw new \DomainException('EXECUTION_TENANT_ADMIN_CONTEXT_REQUIRED');
        }
        $principal = $context->principal;
        if ((int) ($principal['id'] ?? 0) !== $context->tenant->memberId) {
            throw new \DomainException('EXECUTION_ADMIN_PRINCIPAL_REQUIRED');
        }
        return $principal;
    }

    public function tenantEntryBound(): bool
    {
        return $this->admin()->tenantEntryBound;
    }

    public function memberId(): int
    {
        $context = $this->get();
        if (!$context instanceof ConsumerExecutionContext || $context->member === null) {
            throw new \DomainException('EXECUTION_MEMBER_CONTEXT_REQUIRED');
        }
        return $context->member->memberId;
    }

    public function operation(): string
    {
        return $this->get()->operation();
    }

    public function requestId(): string
    {
        return $this->get()->requestId();
    }
}
