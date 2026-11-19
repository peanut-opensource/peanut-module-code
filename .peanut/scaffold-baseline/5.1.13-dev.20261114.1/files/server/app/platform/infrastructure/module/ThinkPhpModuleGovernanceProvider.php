<?php

declare(strict_types=1);

namespace app\platform\infrastructure\module;

use PeanutAdmin\Modules\Identity\Contract\TenantModuleStateQueries;
use app\platform\services\module\ModuleQualificationQueryService;
use app\common\contract\module\ModuleGovernanceProvider;
use app\common\contract\module\ModuleQualificationQuery;
use app\common\contract\module\ModuleLifecycleCommands;
use app\platform\infrastructure\module\ModuleCatalogApplier;
use app\platform\exception\module\ModuleLifecycleException;
use app\platform\services\module\ModuleLifecycleService;
use app\platform\infrastructure\module\ModuleLockResolver;
use app\platform\composition\module\ModuleModuleRegistryFactory;
use app\platform\composition\module\ModuleDefinitionRegistryFactory;

/** Single Host assembly point for the native ThinkPHP Module Governance contracts. */
final class ThinkPhpModuleGovernanceProvider implements ModuleGovernanceProvider
{
    private ModuleModuleRegistryFactory $registryFactory;
    private ?DeployedTenantModuleRegistry $registryInstance = null;
    private ?ModuleQualificationQuery $qualificationInstance = null;

    /** @param array<string,mixed> $moduleConfig */
    public function __construct(
        private readonly string $serverRoot,
        private readonly array $moduleConfig,
        private readonly ModuleCatalogApplier $catalogs,
        private readonly ?\PeanutAdmin\Kernel\Module\CompiledModuleRegistry $applicationRegistry = null,
        private ?ModuleLockResolver $lockResolver = null,
    ) {
        $this->registryFactory = new ModuleModuleRegistryFactory($serverRoot, $applicationRegistry);
    }

    public function registry(): DeployedTenantModuleRegistry
    {
        if ($this->registryInstance instanceof DeployedTenantModuleRegistry) {
            return $this->registryInstance;
        }
        if ($this->applicationRegistry !== null) {
            return $this->registryInstance = new DeployedTenantModuleRegistry($this->applicationRegistry);
        }
        $lockPath = trim((string) ($this->moduleConfig['module_lock'] ?? ''));
        if ($lockPath !== '' && is_file($this->lockFile($lockPath))) {
            return $this->registryInstance = $this->registryFactory->fromModuleLock(
                $this->lockResolver($lockPath),
                $this->moduleConfig,
            );
        }
        return $this->registryInstance = $this->registryFactory->fromDeploymentConfig($this->moduleConfig);
    }

    public function moduleLifecycle(): ModuleLifecycleCommands
    {
        return new ModuleLifecycleService(
            $this->lockResolver(),
            new ModuleDefinitionRegistryFactory($this->serverRoot),
            $this->moduleConfig,
            $this->catalogs,
            new TenantModuleStateQueries(),
            $this->applicationRegistry,
        );
    }

    public function qualification(): ModuleQualificationQuery
    {
        return $this->qualificationInstance ??= new ModuleQualificationQueryService($this->registry(), new TenantModuleStateQueries());
    }

    private function lockResolver(?string $lockPath = null): ModuleLockResolver
    {
        $lockPath ??= trim((string) ($this->moduleConfig['module_lock'] ?? ''));
        if ($lockPath === '') {
            throw new ModuleLifecycleException('PLUGIN_LOCK_INVALID', 'Plugin lock path is not configured.');
        }
        return $this->lockResolver ??= ModuleLockResolver::forRuntime($this->serverRoot, $lockPath);
    }

    private function lockFile(string $lockPath): string
    {
        return str_starts_with($lockPath, DIRECTORY_SEPARATOR)
            ? $lockPath
            : $this->serverRoot . '/' . ltrim($lockPath, '/');
    }
}
