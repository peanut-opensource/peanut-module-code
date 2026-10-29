<?php

declare(strict_types=1);

namespace app\command;

use app\platform\exception\plugin\PluginLifecycleException;
use app\common\execution\ModuleContextualCommand;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

final class ModuleSync extends ModuleContextualCommand
{
    protected function configure()
    {
        $this->setName('module:sync')->setDescription('Synchronize active module.json catalog contributions into a development database')
            ->addOption('module', null, Option::VALUE_REQUIRED, 'Optional single Module key', '');
    }

    protected function handle(Input $input, Output $output): int
    {
        try {
            $this->assertDevelopmentInstanceMaintenanceAccess();
            $config = $this->getApp()->config->get('modules', []);
            if (!is_array($config)) {
                throw new PluginLifecycleException('MODULE_REGISTRY_UNAVAILABLE', 'Module registry is unavailable.');
            }
            $key = trim((string) $input->getOption('module'));
            $result = $this->moduleRuntime()->sync($key === '' ? null : $key);
            $output->writeln((string) json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            return 0;
        } catch (PluginLifecycleException $exception) {
            $output->writeln((string) json_encode(['error' => $exception->errorCode], JSON_THROW_ON_ERROR));
            return 1;
        } catch (\Throwable) {
            $output->writeln('{"error":"MODULE_SYNC_FAILED"}');
            return 1;
        }
    }
}
