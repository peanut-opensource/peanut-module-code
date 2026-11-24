<?php

declare(strict_types=1);

namespace app\common\execution;

use PeanutAdmin\Kernel\Auth\TenantContext;

final readonly class AdminExecutionContext implements ExecutionContext
{
    public TenantContext $tenant;
    private string $operation;
    /** @var array<string,mixed> */
    public array $principal;
    public bool $tenantEntryBound;

    /** @param array<string,mixed> $principal */
    public function __construct(
        TenantContext $tenant,
        string $operation,
        array $principal = [],
        bool $tenantEntryBound = false,
    ) {
        if (trim($operation) === '' || trim($tenant->requestId) === '') {
            throw new \DomainException('EXECUTION_CONTEXT_UNTRUSTED');
        }
        if ($principal !== [] && (int) ($principal['id'] ?? 0) !== $tenant->memberId) {
            throw new \DomainException('EXECUTION_ADMIN_PRINCIPAL_MISMATCH');
        }
        unset($principal['token']);
        $this->tenant = $tenant;
        $this->operation = trim($operation);
        $this->principal = $principal;
        $this->tenantEntryBound = $tenantEntryBound;
    }

    public function operation(): string
    {
        return $this->operation;
    }
    public function requestId(): string
    {
        return $this->tenant->requestId;
    }
    public function tenantId(): int
    {
        return $this->tenant->tenantId;
    }

    public function actor(): array
    {
        return [
            'tenant_id' => $this->tenant->tenantId,
            'account_id' => $this->tenant->accountId,
            'id' => $this->tenant->memberId,
        ];
    }
}
