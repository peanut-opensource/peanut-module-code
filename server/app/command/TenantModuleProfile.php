<?php

declare(strict_types=1);

namespace app\command;

use app\common\composition\ModuleComposition;
use app\common\services\audit\AuditContractHost;
use app\platform\services\module\ProductTenantModuleProfileService;
use app\platform\infrastructure\module\ThinkPhpModuleGovernanceProvider;
use app\platform\infrastructure\plugin\PluginLockResolver;
use PeanutAdmin\Kernel\Module\ModuleException;
use PeanutAdmin\Kernel\Module\ModuleRuntimeRepository;
use app\common\execution\ModuleContextualCommand;
use think\console\Input;
use think\console\input\Argument;
use think\console\Output;
use think\facade\Config;

final class TenantModuleProfile extends ModuleContextualCommand
{
    protected function configure()
    {
        $this->setName('tenant-module:apply-profile')
            ->setDescription('Apply an explicit application-owned Tenant Module product profile')
            ->addArgument('profile', Argument::REQUIRED, 'Product profile: standalone or demo');
    }

    protected function handle(Input $input, Output $output): int
    {
        try {
            $config = Config::get('modules', []);
            if (!is_array($config)) {
                throw new \RuntimeException('MODULE_REGISTRY_UNAVAILABLE');
            }
            $governance = new ThinkPhpModuleGovernanceProvider(
                dirname(__DIR__, 2),
                $config,
                $this->moduleCatalogs(),
                lockResolver: PluginLockResolver::forRuntime(dirname(__DIR__, 2), (string) ($config['plugin_lock'] ?? '')),
            );
            $result = (new ProductTenantModuleProfileService(
                app(ModuleRuntimeRepository::class),
                $governance,
                app(AuditContractHost::class),
                $this->getApp()->make(\PeanutAdmin\Modules\Identity\Contract\AdminDirectoryQuery::class),
                $this->getApp()->make(ModuleComposition::class),
            ))->apply(trim((string) $input->getArgument('profile')));
            $output->writeln((string) json_encode(
                $result,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            ));
            return 0;
        } catch (ModuleException $exception) {
            $output->writeln((string) json_encode(
                ['error' => $exception->errorCode],
                JSON_THROW_ON_ERROR,
            ));
            return 1;
        } catch (\Throwable $exception) {
            $message = $exception->getMessage();
            $output->writeln((string) json_encode(
                ['error' => preg_match('/^[A-Z0-9_]+$/D', $message) === 1
                    ? $message
                    : 'TENANT_MODULE_PROFILE_FAILED'],
                JSON_THROW_ON_ERROR,
            ));
            return 1;
        }
    }
}
