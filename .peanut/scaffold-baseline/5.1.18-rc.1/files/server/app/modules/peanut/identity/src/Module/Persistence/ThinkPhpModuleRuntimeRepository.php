<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Module\Persistence;

use DateTimeImmutable;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Module\ModuleException;
use PeanutAdmin\Kernel\Module\CompiledModuleRegistry;
use PeanutAdmin\Kernel\Module\ModuleInstallationRecord;
use PeanutAdmin\Kernel\Module\ModuleRuntimeRepository;
use PeanutAdmin\Modules\Identity\Contract\TenantModuleStateQueries;
use PeanutAdmin\Kernel\Module\TenantModuleRecord;
use PeanutAdmin\Modules\Identity\Persistence\Model\Tenant;
use PeanutAdmin\Modules\Identity\Module\TenantEntitlementService;

final readonly class ThinkPhpModuleRuntimeRepository implements ModuleRuntimeRepository
{
    public function __construct(
        private CompiledModuleRegistry $registry,
        private TenantEntitlementService $entitlements,
        private bool $lockAvailabilityReads = false,
        private TenantModuleStateQueries $installations = new TenantModuleStateQueries(),
    ) {}

    public function tenantIsActive(int $tenantId): bool
    {
        return Tenant::where('id', $tenantId)->value('status') === 'active';
    }

    public function installation(string $moduleKey): ?ModuleInstallationRecord
    {
        $row = $this->installations->installationRecord($moduleKey, $this->lockAvailabilityReads);

        if ($row !== null) {
            $manifest = $this->registry->requireManifest($moduleKey);
            if ((string) $row['installed_version'] !== (string) ($manifest->data['version'] ?? '')
                || (int) $row['manifest_schema_version'] !== (int) ($manifest->data['schema_version'] ?? 0)
                || !hash_equals($manifest->digest, (string) $row['manifest_digest'])) {
                throw new ModuleException(
                    'MODULE_INSTALLATION_MISMATCH',
                    "Installed Module manifest does not match the compiled registry: {$moduleKey}",
                );
            }
        }

        return $row === null ? null : new ModuleInstallationRecord(
            (string) $row['module_key'],
            (string) $row['installed_version'],
            (string) $row['status'],
            (int) $row['revision'],
            (string) $row['manifest_digest'],
        );
    }

    public function tenantModule(int $tenantId, string $moduleKey, ?TenantContext $context = null): ?TenantModuleRecord
    {
        if ($this->registry->isRequiredTenantFoundation($moduleKey)) {
            $tenantQuery = Tenant::where('id', $tenantId);
            if ($this->lockAvailabilityReads) {
                $tenantQuery->lock(true);
            }
            $tenant = $tenantQuery->field('status,authorization_revision')->find()?->toArray();
            $installation = $this->installation($moduleKey);
            if ($tenant === null || ($tenant['status'] ?? null) !== 'active'
                || $installation === null || $installation->status !== 'active') {
                return null;
            }

            return new TenantModuleRecord(
                $tenantId,
                $moduleKey,
                'enabled',
                null,
                null,
                max(1, (int) $tenant['authorization_revision'] + $installation->revision),
            );
        }
        $license = $this->entitlements->license($tenantId, $this->lockAvailabilityReads, $context);
        if ($license === null || !$this->entitlements->planIncludesModule(
            $tenantId, $license['plan_id'], $moduleKey, $this->lockAvailabilityReads, $context,
        )) {
            return null;
        }

        return new TenantModuleRecord(
            $tenantId,
            $moduleKey,
            $license['status'] === 'active' ? 'enabled' : 'disabled',
            new DateTimeImmutable($license['effective_at']),
            $license['expires_at'] === null ? null : new DateTimeImmutable($license['expires_at']),
            $license['revision'],
        );
    }
}
