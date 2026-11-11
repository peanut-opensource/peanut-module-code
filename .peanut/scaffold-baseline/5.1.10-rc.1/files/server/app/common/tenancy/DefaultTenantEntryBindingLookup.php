<?php

declare(strict_types=1);

namespace app\common\tenancy;

use PeanutAdmin\Modules\Identity\Tenancy\DefaultTenantContextResolver;
use PeanutAdmin\Kernel\Tenancy\TenantEntryBindingLookup;

/** Resolves every Standalone entry to the server-owned active default Tenant. */
final readonly class DefaultTenantEntryBindingLookup implements TenantEntryBindingLookup
{
    public function __construct(private DefaultTenantContextResolver $defaultTenant) {}

    public function binding(string $host, string $clientKey): array
    {
        $context = $this->defaultTenant->system(
            'peanut-admin',
            'standalone-default-tenant-entry',
            hash('sha256', $host . "\0" . $clientKey),
        );

        return ['tenant_id' => $context->tenantId, 'tenant_code' => 'default'];
    }
}
