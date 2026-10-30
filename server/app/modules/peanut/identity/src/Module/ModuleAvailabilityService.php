<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Module;

use DateTimeImmutable;
use PeanutAdmin\Modules\Identity\Module\Model\ModuleInstallation;
use PeanutAdmin\Kernel\Tenancy\TenantScope;

final readonly class ModuleAvailabilityService implements \PeanutAdmin\Kernel\Module\ModuleAvailability
{
    public function __construct(private \PeanutAdmin\Kernel\Module\CompiledModuleRegistry $registry, private TenantEntitlementService $entitlements) {}

    public function assertAvailable(
        TenantScope $scope,
        string $moduleKey,
        DateTimeImmutable $now,
        bool $lock = false,
    ): void {
        $this->assertDeployment($moduleKey, $lock);
        $this->assertTenant($scope, $moduleKey, $now, $lock);
    }

    public function assertDeployment(string $moduleKey, bool $lock = false): void
    {
        $manifest = $this->registry->requireManifest($moduleKey);
        $installation = ModuleInstallation::where('module_key', $moduleKey)->lock($lock)->find();
        if (!$installation instanceof ModuleInstallation) {
            throw new \PeanutAdmin\Kernel\Module\ModuleException('MODULE_NOT_INSTALLED', "Module {$moduleKey} is not installed.");
        }
        if ($installation->getAttr('status') !== 'active') {
            throw new \PeanutAdmin\Kernel\Module\ModuleException('MODULE_INSTALLATION_FAILED', "Module {$moduleKey} is not active.");
        }
        if ((string) $installation->getAttr('installed_version') !== (string) ($manifest->data['version'] ?? '')
            || (int) $installation->getAttr('manifest_schema_version') !== (int) ($manifest->data['schema_version'] ?? 0)
            || !hash_equals($manifest->digest, (string) $installation->getAttr('manifest_digest'))) {
            throw new \PeanutAdmin\Kernel\Module\ModuleException(
                'MODULE_INSTALLATION_MISMATCH',
                "Installed Module manifest does not match the compiled registry: {$moduleKey}",
            );
        }
    }

    public function assertTenant(
        TenantScope $scope,
        string $moduleKey,
        DateTimeImmutable $now,
        bool $lock = false,
    ): void {
        if (!$this->entitlements->allowsModule($scope->tenantId(), $moduleKey, $now, $lock)) {
            throw new \PeanutAdmin\Kernel\Module\ModuleException('MODULE_TENANT_NOT_EFFECTIVE', "Module {$moduleKey} is outside the tenant license entitlement.");
        }
    }
}
