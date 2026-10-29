<?php

declare(strict_types=1);

namespace app\platform\composition\plugin;

use app\platform\infrastructure\plugin\PluginLockResolver;
use app\platform\infrastructure\module\DeployedTenantModuleRegistry;
use PeanutAdmin\Kernel\Module\CompiledModuleRegistry;

/** The single Host construction path for deployed Module registry compilation. */
final readonly class PluginModuleRegistryFactory
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
    public function fromPluginLock(PluginLockResolver $resolver, array $deploymentConfig): DeployedTenantModuleRegistry
    {
        return new DeployedTenantModuleRegistry(
            $this->applicationRegistry ?? $this->definitions->fromPluginLock($resolver, $deploymentConfig),
        );
    }
}
