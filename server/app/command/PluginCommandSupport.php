<?php

declare(strict_types=1);

namespace app\command;

use app\platform\infrastructure\module\ThinkPhpModuleGovernanceProvider;
use app\platform\exception\plugin\PluginLifecycleException;
use app\platform\exception\plugin\PluginPackageException;
use app\platform\infrastructure\plugin\PluginLockResolver;
use think\console\Output;
use think\facade\Config;

trait PluginCommandSupport
{
    private function pluginLifecycle(): \app\common\contract\module\PluginLifecycleCommands
    {
        $serverRoot = dirname(__DIR__, 2);
        $config = Config::get('modules', []);
        if (!is_array($config)) {
            throw new PluginLifecycleException('MODULE_REGISTRY_UNAVAILABLE', 'Module deployment config is invalid.');
        }
        return (new ThinkPhpModuleGovernanceProvider(
            $serverRoot,
            $config,
            $this->moduleCatalogs(),
            lockResolver: PluginLockResolver::forRuntime($serverRoot, (string) ($config['plugin_lock'] ?? '')),
        ))->pluginLifecycle();
    }

    /** @param callable(\app\common\contract\module\PluginLifecycleCommands):array<string,mixed> $operation */
    private function runPluginOperation(Output $output, callable $operation): int
    {
        return $this->writePluginResult($output, fn(): array => $operation($this->pluginLifecycle()));
    }

    private function writePluginResult(Output $output, callable $operation): int
    {
        try {
            $output->writeln((string) json_encode(
                $operation(),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            ));
            return 0;
        } catch (PluginLifecycleException|PluginPackageException $exception) {
            $output->writeln((string) json_encode(
                ['error' => $exception->errorCode, 'reason' => $exception->getMessage()]
                    + ($exception instanceof PluginPackageException ? ['details' => $exception->details] : []),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            ));
            return $exception->getCode() > 0 && $exception->getCode() < 256 ? $exception->getCode() : 1;
        } catch (\Throwable) {
            $record = ['error' => 'PLUGIN_LIFECYCLE_FAILED'];
            $output->writeln((string) json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            return 1;
        }
    }
}
