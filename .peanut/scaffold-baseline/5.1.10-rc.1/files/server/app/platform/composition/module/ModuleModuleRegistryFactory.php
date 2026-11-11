<?php

declare(strict_types=1);

namespace app\platform\composition\module;

use app\platform\infrastructure\module\ModuleLockResolver;
use app\platform\infrastructure\module\DeployedTenantModuleRegistry;
use PeanutAdmin\Kernel\Module\CompiledModuleRegistry;

/** The single Host construction path for deployed Module registry compilation. */
final readonly class ModuleModuleRegistryFactory
{
    private ModuleDefinitionRegistryFactory $definitions;

    public function __construct(
        private string $serverRoot,
        private ?CompiledModuleRegistry $applicationRegistry = null,
    ) {
        $this->definitions = new ModuleDefinitionRegistryFactory($serverRoot);
    }

    /** @param array<string,mixed> $deploymentConfig */
    public function fromDeploymentConfig(array $deploymentConfig): DeployedTenantModuleRegistry
    {
        return new DeployedTenantModuleRegistry(
            $this->applicationRegistry ?? $this->definitions->fromDeploymentConfig($deploymentConfig),
        );
    }

    /** @param array<string,mixed> $deploymentConfig */
    public function fromModuleLock(ModuleLockResolver $resolver, array $deploymentConfig): DeployedTenantModuleRegistry
    {
        return new DeployedTenantModuleRegistry(
            $this->applicationRegistry ?? $this->definitions->fromModuleLock($resolver, $deploymentConfig),
        );
    }
}
