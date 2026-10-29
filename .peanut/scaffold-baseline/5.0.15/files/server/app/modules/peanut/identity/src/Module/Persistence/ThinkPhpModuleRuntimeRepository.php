<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Module\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use PeanutAdmin\Kernel\Module\ModuleException;
use PeanutAdmin\Kernel\Module\CompiledModuleRegistry;
use PeanutAdmin\Kernel\Module\ModuleInstallationRecord;
use PeanutAdmin\Kernel\Module\ModuleRuntimeRepository;
use PeanutAdmin\Modules\Identity\Module\Model\ModuleInstallation;
use PeanutAdmin\Modules\Identity\Module\Model\TenantModule;
use PeanutAdmin\Kernel\Module\TenantModuleMutationRepository;
use PeanutAdmin\Kernel\Module\TenantModuleRecord;
use PeanutAdmin\Modules\Identity\Persistence\Model\Tenant;
use think\db\Raw;

final readonly class ThinkPhpModuleRuntimeRepository implements ModuleRuntimeRepository, TenantModuleMutationRepository
{
    public function __construct(
        private CompiledModuleRegistry $registry,
        private bool $lockAvailabilityReads = false,
    ) {}

    public function tenantIsActive(int $tenantId): bool
    {
        return Tenant::where('id', $tenantId)->value('status') === 'active';
    }

    public function installation(string $moduleKey): ?ModuleInstallationRecord
    {
        $query = ModuleInstallation::where('module_key', $moduleKey);
        if ($this->lockAvailabilityReads) {
            $query->lock(true);
        }
        $row = $query->field(
            'module_key,installed_version,manifest_schema_version,status,revision,manifest_digest',
        )->find()?->toArray();

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

    public function tenantModule(int $tenantId, string $moduleKey): ?TenantModuleRecord
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
        $query = TenantModule::where('tenant_id', $tenantId)->where('module_key', $moduleKey);
        if ($this->lockAvailabilityReads) {
            $query->lock(true);
        }
        $row = $query->field(
            'tenant_id,module_key,status,effective_at,expires_at,authorization_revision',
        )->find()?->toArray();

        return $row === null ? null : new TenantModuleRecord(
            (int) $row['tenant_id'],
            (string) $row['module_key'],
            (string) $row['status'],
            $row['effective_at'] === null ? null : new DateTimeImmutable((string) $row['effective_at']),
            $row['expires_at'] === null ? null : new DateTimeImmutable((string) $row['expires_at']),
            (int) $row['authorization_revision'],
        );
    }

    public function enabledDependents(int $tenantId, string $moduleKey): array
    {
        return array_values(array_map('strval', TenantModule::where('tenant_id', $tenantId)
            ->where('status', 'enabled')
            ->where('module_key', '<>', $moduleKey)
            ->order('module_key')
            ->column('module_key')));
    }

    public function enable(
        int $tenantId,
        string $moduleKey,
        array $config,
        DateTimeImmutable $now,
        string $source = 'manual',
        ?DateTimeImmutable $effectiveAt = null,
        ?DateTimeImmutable $expiresAt = null,
    ): TenantModuleRecord {
        $timestamp = $this->format($now);
        $data = [
            'status' => 'enabled',
            'source' => $source,
            'config_json' => json_encode($config, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'effective_at' => $effectiveAt === null ? null : $this->format($effectiveAt),
            'expires_at' => $expiresAt === null ? null : $this->format($expiresAt),
            'enabled_at' => $timestamp,
            'disabled_at' => null,
            'disabled_reason' => null,
            'updated_at' => $timestamp,
        ];
        $existing = TenantModule::where('tenant_id', $tenantId)
            ->where('module_key', $moduleKey)
            ->lock(true)
            ->field('config_revision,authorization_revision')
            ->find();
        if ($existing === null) {
            TenantModule::insert([
                'tenant_id' => $tenantId,
                'module_key' => $moduleKey,
                ...$data,
                'config_revision' => 1,
                'authorization_revision' => 1,
                'created_at' => $timestamp,
            ]);
        } else {
            TenantModule::where('tenant_id', $tenantId)->where('module_key', $moduleKey)->update([
                ...$data,
                'config_revision' => new Raw('config_revision + 1'),
                'authorization_revision' => new Raw('authorization_revision + 1'),
            ]);
        }
        $this->bumpTenant($tenantId, $timestamp);

        return $this->tenantModule($tenantId, $moduleKey)
            ?? throw new ModuleException('MODULE_TENANT_DISABLED', 'Enabled module could not be reloaded.');
    }

    public function disable(int $tenantId, string $moduleKey, DateTimeImmutable $now): TenantModuleRecord
    {
        $timestamp = $this->format($now);
        TenantModule::where('tenant_id', $tenantId)->where('module_key', $moduleKey)->update([
            'status' => 'disabled',
            'disabled_at' => $timestamp,
            'authorization_revision' => new Raw('authorization_revision + 1'),
            'updated_at' => $timestamp,
        ]);
        $this->bumpTenant($tenantId, $timestamp);

        return $this->tenantModule($tenantId, $moduleKey)
            ?? throw new ModuleException('MODULE_TENANT_DISABLED', 'Disabled module could not be reloaded.');
    }

    private function bumpTenant(int $tenantId, string $timestamp): void
    {
        Tenant::where('id', $tenantId)->update([
            'authorization_revision' => new Raw('authorization_revision + 1'),
            'revision' => new Raw('revision + 1'),
            'updated_at' => $timestamp,
        ]);
    }

    private function format(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
    }
}
