<?php

declare(strict_types=1);

namespace app\platform\infrastructure\module;

use PeanutAdmin\Modules\Identity\Contract\TenantModuleStateQueries;
use app\platform\services\module\ModuleQualificationQueryService;
use app\common\contract\module\ModuleGovernanceProvider;
use app\common\contract\module\ModuleQualificationQuery;
use app\common\contract\module\PluginLifecycleCommands;
use app\platform\infrastructure\plugin\ModuleCatalogApplier;
use app\platform\exception\plugin\PluginLifecycleException;
use app\platform\services\plugin\PluginLifecycleService;
use app\platform\infrastructure\plugin\PluginLockResolver;
use app\platform\composition\plugin\PluginModuleRegistryFactory;

/** Single Host assembly point for the native ThinkPHP Module Governance contracts. */
final class ThinkPhpModuleGovernanceProvider implements ModuleGovernanceProvider
{
    private PluginModuleRegistryFactory $registryFactory;
    private ?DeployedTenantModuleRegistry $registryInstance = null;
    private ?ModuleQualificationQuery $qualificationInstance = null;

    /** @param array<string,mixed> $moduleConfig */
    public function __construct(
        private readonly string $serverRoot,
        private readonly array $moduleConfig,
        private readonly ModuleCatalogApplier $catalogs,
        private readonly ?\PeanutAdmin\Kernel\Module\CompiledModuleRegistry $applicationRegistry = null,
        private ?PluginLockResolver $lockResolver = null,
    ) {
        $this->registryFactory = new PluginModuleRegistryFactory($serverRoot, $applicationRegistry);
    }

    public function registry(): DeployedTenantModuleRegistry
    {
        if ($this->registryInstance instanceof DeployedTenantModuleRegistry) {
            return $this->registryInstance;
        }
        if ($this->applicationRegistry !== null) {
            return $this->registryInstance = new DeployedTenantModuleRegistry($this->applicationRegistry);
        }
        $lockPath = trim((string) ($this->moduleConfig['plugin_lock'] ?? ''));
        if ($lockPath !== '' && is_file($this->lockFile($lockPath))) {
            return $this->registryInstance = $this->registryFactory->fromPluginLock(
                $this->lockResolver($lockPath),
                $this->moduleConfig,
            );
        }
        return $this->registryInstance = $this->registryFactory->fromDeploymentConfig($this->moduleConfig);
    }

    public function pluginLifecycle(): PluginLifecycleCommands
    {
        return new PluginLifecycleService(
            $this->lockResolver(),
            $this->registryFactory,
            $this->moduleConfig,
            $this->catalogs,
            new TenantModuleStateQueries(),
        );
    }

    public function qualification(): ModuleQualificationQuery
    {
        return $this->qualificationInstance ??= new ModuleQualificationQueryService($this->registry(), new TenantModuleStateQueries());
    }

    private function lockResolver(?string $lockPath = null): PluginLockResolver
    {
        $lockPath ??= trim((string) ($this->moduleConfig['plugin_lock'] ?? ''));
        if ($lockPath === '') {
            throw new PluginLifecycleException('PLUGIN_LOCK_INVALID', 'Plugin lock path is not configured.');
        }
        return $this->lockResolver ??= new PluginLockResolver($this->serverRoot, $lockPath);
    }

    private function lockFile(string $lockPath): string
    {
        return str_starts_with($lockPath, DIRECTORY_SEPARATOR)
            ? $lockPath
            : $this->serverRoot . '/' . ltrim($lockPath, '/');
    }
}
