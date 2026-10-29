<?php

declare(strict_types=1);

namespace app\command;

use app\common\composition\ModuleComposition;
use app\common\execution\ModuleContextualCommand;
use app\common\services\audit\AuditContractHost;
use app\common\enum\instance\DeploymentMode;
use app\platform\infrastructure\module\ThinkPhpModuleGovernanceProvider;
use app\platform\services\module\ProductTenantModuleProfileService;
use app\platform\infrastructure\plugin\PluginLockResolver;
use app\platform\exception\plugin\PluginLifecycleException;
use PeanutAdmin\Kernel\Module\ModuleException;
use PeanutAdmin\Kernel\Module\ModuleRuntimeRepository;
use think\console\Input;
use think\console\Output;
use think\console\input\Option;
use think\facade\Config;

/** Explicit Standalone deployment-owner opening of installed, locked private Modules, without RBAC grants. */
final class TenantModuleEnableLockedPrivate extends ModuleContextualCommand
{
    protected function configure()
    {
        $this->setName('tenant-module:enable-locked-private')->setDescription('Add locked private Modules to the Standalone default Tenant')
            ->addOption('module', null, Option::VALUE_REQUIRED | Option::VALUE_IS_ARRAY, 'Private Module key');
    }

    /** All selected Modules and effective dependencies are checked inside one canonical profile transaction. */
    protected function handle(Input $input, Output $output): int
    {
        try {
            $mode = DeploymentMode::fromConfiguredValue(Config::get('deployment.mode'));
            if ($mode !== DeploymentMode::Standalone) {
                throw new ModuleException('PRIVATE_TENANT_MODULE_STANDALONE_REQUIRED', 'Private Module selection requires Standalone.');
            }
            $config = Config::get('modules', []);
            if (!is_array($config) || !is_string($config['plugin_lock'] ?? null) || $config['plugin_lock'] === '') {
                throw new ModuleException('MODULE_REGISTRY_UNAVAILABLE', 'Explicit Plugin lock configuration is required.');
            }
            $root = dirname(__DIR__, 2);
            $governance = new ThinkPhpModuleGovernanceProvider(
                $root,
                $config,
                $this->moduleCatalogs(),
                lockResolver: PluginLockResolver::forRuntime($root, (string) $config['plugin_lock']),
            );
            $service = new ProductTenantModuleProfileService(
                app(ModuleRuntimeRepository::class),
                $governance,
                app(AuditContractHost::class),
                $this->getApp()->make(\PeanutAdmin\Modules\Identity\Contract\AdminDirectoryQuery::class),
                $this->getApp()->make(ModuleComposition::class),
            );
            $result = $service->applyAdditionalInstallationSelection($input->getOption('module'), $mode, PluginLockResolver::forRuntime($root, $config['plugin_lock']));
            $output->writeln(json_encode($result + ['rbac_granted' => false], JSON_THROW_ON_ERROR));
            return 0;
        } catch (ModuleException|PluginLifecycleException $exception) {
            $output->writeln(json_encode(['error' => $exception->errorCode], JSON_THROW_ON_ERROR));
            return 1;
        } catch (\Throwable) {
            $output->writeln('{"error":"PRIVATE_TENANT_MODULE_SELECTION_FAILED"}');
            return 1;
        }
    }
}
