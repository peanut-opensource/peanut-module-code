<?php

declare(strict_types=1);

namespace app\platform\services\module;

use app\platform\composition\module\ModuleDefinitionRegistryFactory;
use app\platform\exception\module\ModuleLifecycleException;
use app\platform\infrastructure\module\ModuleCatalogApplier;

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
        $compiled = (new ModuleDefinitionRegistryFactory($this->serverRoot))->fromCompiled($this->moduleConfig);
        $registered = [];
        foreach ($compiled->modules as $manifest) {
            $key = (string) ($manifest->data['key'] ?? '');
            $registered[$key] = true;
        }
        if ($moduleKey !== null && !isset($registered[$moduleKey])) {
            throw new ModuleLifecycleException('MODULE_NOT_REGISTERED', 'Module is not active in the deployed registry.');
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
