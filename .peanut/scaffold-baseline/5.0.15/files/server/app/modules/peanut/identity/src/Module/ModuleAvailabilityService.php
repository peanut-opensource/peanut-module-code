<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Module;

use DateTimeImmutable;
use PeanutAdmin\Modules\Identity\Module\Model\ModuleInstallation;
use PeanutAdmin\Modules\Identity\Module\Model\TenantModule;
use PeanutAdmin\Kernel\Tenancy\TenantScope;
use think\Model;

final readonly class ModuleAvailabilityService implements \PeanutAdmin\Kernel\Module\ModuleAvailability
{
    public function __construct(private \PeanutAdmin\Kernel\Module\CompiledModuleRegistry $registry) {}

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
        if ($this->registry->isRequiredTenantFoundation($moduleKey)) {
            return;
        }
        $tenantModule = TenantModule::scope('tenant', $scope)
            ->where('module_key', $moduleKey)
            ->lock($lock)
            ->find();
        if (!$tenantModule instanceof TenantModule || $tenantModule->getAttr('status') === 'disabled') {
            throw new \PeanutAdmin\Kernel\Module\ModuleException('MODULE_TENANT_DISABLED', "Module {$moduleKey} is disabled for tenant.");
        }
        if (!$this->effective($tenantModule, $now)) {
            throw new \PeanutAdmin\Kernel\Module\ModuleException('MODULE_TENANT_NOT_EFFECTIVE', "Module {$moduleKey} is outside its effective window.");
        }
    }

    private function effective(Model $record, DateTimeImmutable $now): bool
    {
        $effectiveAt = $this->date($record->getAttr('effective_at'));
        $expiresAt = $this->date($record->getAttr('expires_at'));

        return $record->getAttr('status') === 'enabled'
            && ($effectiveAt === null || $effectiveAt <= $now)
            && ($expiresAt === null || $now < $expiresAt);
    }

    private function date(mixed $value): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }
        if ($value instanceof DateTimeImmutable) {
            return $value;
        }
        if ($value instanceof \DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }

        return is_string($value) ? new DateTimeImmutable($value) : null;
    }
}
