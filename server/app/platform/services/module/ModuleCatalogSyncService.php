<?php

declare(strict_types=1);

namespace app\platform\services\module;

use app\platform\composition\module\ModuleDefinitionRegistryFactory;
use app\platform\exception\module\ModuleLifecycleException;
use app\platform\infrastructure\module\ModuleCatalogApplier;
use think\facade\Db;

/** Applies module.json catalog contributions through the same compiled Plugin registry used at runtime. */
final readonly class ModuleCatalogSyncService
{
    /** @param array<string,mixed> $moduleConfig */
    public function __construct(
        private string $serverRoot,
        private array $moduleConfig,
        private ModuleCatalogApplier $catalogs,
    ) {}

    /** @return array<string,mixed> */
    public function sync(?string $moduleKey = null): array
    {
        $factory = new ModuleDefinitionRegistryFactory($this->serverRoot);
        $compiled = $factory->fromCompiled($this->moduleConfig);
        $registered = [];
        foreach ($compiled->modules as $manifest) {
            $key = (string) ($manifest->data['key'] ?? '');
            $registered[$key] = true;
        }
        if ($moduleKey !== null && !isset($registered[$moduleKey])) {
            throw new ModuleLifecycleException('MODULE_NOT_REGISTERED', 'Module is not active in the deployed registry.');
        }
        $selected = $moduleKey === null ? array_keys($registered) : [$moduleKey];
        $plugins = $factory->pluginsFromCompiled($this->moduleConfig);
        return Db::transaction(function () use ($compiled, $selected, $plugins, $moduleKey): array {
            $owned = [];
            foreach ($plugins as $plugin) {
                $keys = array_intersect($selected, array_keys($plugin->moduleRoots));
                if ($keys === []) { continue; }
                $current = Db::name('module_installation')->where('module_key', $plugin->key)->lock(true)->find();
                if ($current === null || $current['status'] !== 'active'
                    || !ModuleLifecycleService::sameIdentity($plugin, $current)
                    || $current['source'] !== $plugin->source['type'] . ':' . $plugin->source['reference']) {
                    throw new ModuleLifecycleException('PLUGIN_CATALOG_SYNC_IDENTITY_MISMATCH',
                        'Catalog sync requires the already installed active package; use its native lifecycle for package changes.');
                }
                $owned = [...$owned, ...$keys];
            }
            if (array_diff($selected, $owned) !== []) {
                throw new ModuleLifecycleException('PLUGIN_MODULE_MISMATCH', 'Selected catalog modules have no installed package owner.');
            }
            return $this->catalogs->apply($compiled, $moduleKey === null ? null : $selected, refreshActiveIdentities: true);
        });
    }

    public function catalogRevision(): string
    {
        return $this->catalogs->catalogRevision();
    }

    /** @param list<string> $moduleKeys */
    public function invalidateTenantAuthorization(array $moduleKeys): void
    {
        $this->catalogs->invalidateTenantAuthorization($moduleKeys);
    }
}
