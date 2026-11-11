<?php

declare(strict_types=1);

namespace app\command;

use app\platform\infrastructure\module\ThinkPhpModuleGovernanceProvider;
use app\platform\exception\module\ModuleLifecycleException;
use app\platform\exception\module\ModulePackageException;
use app\platform\infrastructure\module\ModuleLockResolver;
use think\console\Output;
use think\facade\Config;

trait ModuleCommandSupport
{
    private function moduleLifecycle(): \app\common\contract\module\ModuleLifecycleCommands
    {
        $serverRoot = dirname(__DIR__, 2);
        $config = Config::get('modules', []);
        if (!is_array($config)) {
            throw new ModuleLifecycleException('MODULE_REGISTRY_UNAVAILABLE', 'Module deployment config is invalid.');
        }
        return (new ThinkPhpModuleGovernanceProvider(
            $serverRoot,
            $config,
            $this->moduleCatalogs(),
            lockResolver: ModuleLockResolver::forRuntime($serverRoot, (string) ($config['module_lock'] ?? '')),
        ))->moduleLifecycle();
    }

    /** @param callable(\app\common\contract\module\ModuleLifecycleCommands):array<string,mixed> $operation */
    private function runModuleOperation(Output $output, callable $operation): int
    {
        return $this->writeModuleResult($output, fn(): array => $operation($this->moduleLifecycle()));
    }

    private function writeModuleResult(Output $output, callable $operation): int
    {
        try {
            $output->writeln((string) json_encode(
                $operation(),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            ));
            return 0;
        } catch (ModuleLifecycleException|ModulePackageException $exception) {
            $output->writeln((string) json_encode(
                ['error' => $exception->errorCode, 'reason' => $exception->getMessage()]
                    + ($exception instanceof ModulePackageException ? ['details' => $exception->details] : []),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            ));
            return $exception->getCode() > 0 && $exception->getCode() < 256 ? $exception->getCode() : 1;
        } catch (\Throwable $exception) {
            $this->getApp()->make(\think\exception\Handle::class)->report($exception);
            $record = ['error' => 'PLUGIN_LIFECYCLE_FAILED'];
            $output->writeln((string) json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            return 1;
        }
    }
}
