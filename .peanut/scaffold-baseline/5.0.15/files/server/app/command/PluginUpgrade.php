<?php

declare(strict_types=1);

namespace app\command;

use app\common\execution\ModuleContextualCommand;
use think\console\Input;
use think\console\input\Argument;
use think\console\input\Option;
use think\console\Output;

final class PluginUpgrade extends ModuleContextualCommand
{
    use PluginCommandSupport;

    protected function configure()
    {
        $this->setName('plugin:upgrade')->setDescription('Plan or apply a locked Plugin upgrade')
            ->addArgument('plugin_key', Argument::REQUIRED, 'Plugin key')
            ->addOption('dry-run', null, Option::VALUE_NONE, 'Only output the upgrade plan');
    }

    protected function handle(Input $input, Output $output): int
    {
        $key = trim((string) $input->getArgument('plugin_key'));
        $dryRun = (bool) $input->getOption('dry-run');
        return $this->runPluginOperation(
            $output,
            static fn($service): array => $service->upgrade($key, $dryRun),
        );
    }
}
