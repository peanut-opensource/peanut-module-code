<?php

declare(strict_types=1);

namespace app\platform\services\module;

use PeanutAdmin\Modules\Identity\Contract\TenantModuleStateQueries;
use app\platform\infrastructure\module\DeployedTenantModuleRegistry;
use app\common\contract\module\ModuleQualification;
use app\common\contract\module\ModuleQualificationQuery;
use app\common\contract\module\TenantModuleState;
use PeanutAdmin\Kernel\Module\ManifestDocument;
use think\facade\Db;

/** Read-only qualification projection over the verified deployment registry. */
final readonly class ModuleQualificationQueryService implements ModuleQualificationQuery
{
    public function __construct(
        private DeployedTenantModuleRegistry $registry,
        private TenantModuleStateQueries $tenantStates,
    ) {}

    public function installedModule(string $moduleKey): ModuleQualification
    {
        $manifest = $this->registry->requireInstalled($moduleKey, false);
        $pluginKey = Db::name('plugin_module')->where('module_key', $moduleKey)->value('plugin_key');
        return $this->qualification($moduleKey, $manifest, $pluginKey);
    }

    public function installedModules(): array
    {
        $keys = $this->tenantStates->activeInstallationKeys();
        if ($keys === []) {
            return [];
        }
        $manifests = $this->registry->requireInstalledMany($keys, false);
        $pluginRows = Db::name('plugin_module')->whereIn('module_key', $keys)
            ->field('module_key,plugin_key')->select()->toArray();
        // plugin_module.module_key is unique; a missing ownership row is an explicit deployment root.
        $pluginKeys = array_column($pluginRows, 'plugin_key', 'module_key');
        $modules = [];
        foreach ($keys as $moduleKey) {
            $modules[] = $this->qualification($moduleKey, $manifests[$moduleKey], $pluginKeys[$moduleKey] ?? null);
        }
        return $modules;
    }

    private function qualification(string $moduleKey, ManifestDocument $manifest, mixed $pluginKey): ModuleQualification
    {
        if (!is_string($pluginKey) || $pluginKey === '') {
            // Explicit deployment roots are allowed to register a Module without a Plugin.
            $pluginKey = 'deployment';
        }
        $dependencies = [];
        foreach (($manifest->data['dependencies'] ?? []) as $dependency) {
            if (is_array($dependency) && is_string($dependency['module_key'] ?? null)) {
                $dependencies[] = $dependency['module_key'];
            }
        }
        return new ModuleQualification(
            $moduleKey,
            $pluginKey,
            (string) $manifest->data['version'],
            (int) $manifest->data['schema_version'],
            $manifest->digest,
            array_values($dependencies),
        );
    }

    public function tenantModuleStates(int $tenantId): array
    {
        if (!$this->tenantIsActive($tenantId)) {
            return [];
        }

        $rows = $this->tenantStates->stateRows($tenantId);
        $foundationKeys = array_fill_keys($this->foundationKeys(), true);
        $rows = array_values(array_filter(
            $rows,
            static fn(array $row): bool => !isset($foundationKeys[(string) ($row['module_key'] ?? '')]),
        ));
        $states = array_map(
            static fn(array $row): TenantModuleState => TenantModuleState::fromRow($row),
            $rows,
        );
        foreach ($this->activeFoundations() as $moduleKey => $installation) {
            $activatedAt = self::nullableDate($installation['activated_at'] ?? null);
            $states[] = new TenantModuleState(
                0,
                $tenantId,
                $moduleKey,
                'enabled',
                'required_foundation',
                (int) ($installation['revision'] ?? 0),
                null,
                null,
                $activatedAt,
                null,
                null,
                self::nullableDate($installation['created_at'] ?? null),
                self::nullableDate($installation['updated_at'] ?? null),
            );
        }
        usort($states, static fn(TenantModuleState $left, TenantModuleState $right): int => $left->moduleKey <=> $right->moduleKey);

        return $states;
    }

    public function activeTenantModuleKeys(int $tenantId): array
    {
        if (!$this->tenantIsActive($tenantId)) {
            return [];
        }

        $keys = $this->tenantStates->activeModuleKeys($tenantId);
        array_push($keys, ...array_keys($this->activeFoundations()));
        $keys = array_values(array_unique($keys));
        sort($keys, SORT_STRING);

        return $keys;
    }

    private function tenantIsActive(int $tenantId): bool
    {
        return $this->tenantStates->tenantIsActive($tenantId);
    }

    /** @return array<string,array<string,mixed>> */
    private function activeFoundations(): array
    {
        $compiledKeys = array_fill_keys($this->registry->compiled()->moduleKeys(), true);
        $rows = $this->tenantStates->activeInstallationMetadata();
        $foundations = [];
        foreach ($rows as $row) {
            $moduleKey = (string) ($row['module_key'] ?? '');
            if ($moduleKey === '' || !isset($compiledKeys[$moduleKey])
                || !$this->registry->isRequiredTenantFoundation($moduleKey)) {
                continue;
            }
            $foundations[$moduleKey] = $row;
        }
        $this->registry->requireInstalledMany(array_keys($foundations), false);

        return $foundations;
    }

    /** @return list<string> */
    private function foundationKeys(): array
    {
        return array_values(array_filter(
            $this->registry->compiled()->moduleKeys(),
            fn(string $moduleKey): bool => $this->registry->isRequiredTenantFoundation($moduleKey),
        ));
    }

    private static function nullableDate(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }
}
