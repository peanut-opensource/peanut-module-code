<?php

declare(strict_types=1);

namespace app\common\execution;

use app\common\validation\instance\InstanceToolAccessGuard;
use app\platform\exception\plugin\PluginLifecycleException;
use app\platform\infrastructure\plugin\ModuleCatalogApplier;
use app\platform\services\plugin\PlatformModuleRuntimeService;

/** Supplies Module catalog coordination without leaking a database connection into commands. */
abstract class ModuleContextualCommand extends ContextualCommand
{
    public function __construct(
        ?ExecutionContextStore $contexts = null,
        ?CurrentExecutionContext $executionContext = null,
        private readonly ?ModuleCatalogApplier $catalogs = null,
        private readonly ?PlatformModuleRuntimeService $moduleRuntime = null,
    ) {
        parent::__construct($contexts, $executionContext);
    }

    final protected function moduleCatalogs(): ModuleCatalogApplier
    {
        return $this->catalogs
            ?? throw new \LogicException('COMMAND_DEPENDENCIES_NOT_INJECTED');
    }

    final protected function moduleRuntime(): PlatformModuleRuntimeService
    {
        return $this->moduleRuntime
            ?? throw new \LogicException('COMMAND_DEPENDENCIES_NOT_INJECTED');
    }

    final protected function assertDevelopmentInstanceMaintenanceAccess(): void
    {
        $app = $this->getApp();
        $guard = InstanceToolAccessGuard::fromConfiguredValue($app->config->get('deployment.mode'));
        if (!$guard->allowsCliDevelopmentMaintenance(
            $app->config->get('peanut.environment'),
            $app->isDebug(),
            $this->executionContext(),
            $this->getName(),
            $this->establishedInstanceContext(),
        )) {
            throw new PluginLifecycleException(
                'MODULE_RUNTIME_MUTATION_DISABLED',
                'Runtime Module mutation is disabled.',
            );
        }
    }
}
