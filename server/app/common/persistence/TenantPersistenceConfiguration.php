<?php

declare(strict_types=1);

namespace app\common\persistence;

use PeanutAdmin\Kernel\Persistence\Tenancy\TenantPersistenceMode;

/** Edition-shaped configuration consumed by Core's native ThinkPHP stores. */
final readonly class TenantPersistenceConfiguration
{
    public function __construct(
        public TenantPersistenceMode $mode = TenantPersistenceMode::TenantScoped,
        public ?int $instanceTenantId = null,
    ) {
        if ($mode !== TenantPersistenceMode::TenantScoped || $instanceTenantId !== null) {
            throw new \RuntimeException('TENANTLESS_LEGACY_SCHEMA_MANUAL_MIGRATION_REQUIRED');
        }
    }
}
