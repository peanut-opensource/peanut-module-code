<?php

declare(strict_types=1);

namespace app\platform\services\plugin;

use app\platform\composition\plugin\PluginModuleRegistryFactory;
use app\platform\exception\plugin\PluginLifecycleException;
use app\platform\infrastructure\plugin\DevelopmentModuleDiscovery;
use app\platform\infrastructure\plugin\ModuleCatalogApplier;

/** Applies module.json catalog contributions through the same compiled Plugin registry used at runtime. */
final readonly class PluginCatalogSyncService
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
        $roots = array_values((new DevelopmentModuleDiscovery(dirname($this->serverRoot)))->moduleRoots());
        $compiled = (new PluginModuleRegistryFactory($this->serverRoot))
            ->fromDeploymentConfig(array_replace($this->moduleConfig, ['roots' => $roots]))
            ->compiled();
        $registered = [];
        foreach ($compiled->modules as $manifest) {
            $key = (string) ($manifest->data['key'] ?? '');
            $registered[$key] = true;
        }
        if ($moduleKey !== null && !isset($registered[$moduleKey])) {
            throw new PluginLifecycleException('MODULE_NOT_REGISTERED', 'Module is not active in the deployed registry.');
        }
        return $this->catalogs->apply(
            $compiled,
            $moduleKey === null ? null : [$moduleKey],
        );
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
