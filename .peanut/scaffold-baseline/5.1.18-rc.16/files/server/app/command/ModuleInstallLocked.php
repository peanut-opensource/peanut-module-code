<?php

declare(strict_types=1);

namespace app\command;

use app\common\execution\ModuleContextualCommand;
use think\console\Input;
use think\console\input\Argument;
use think\console\input\Option;
use think\console\Output;

final class ModuleInstallLocked extends ModuleContextualCommand
{
    use ModuleCommandSupport;

    protected function configure()
    {
        $this->setName('module:install-locked')->setDescription('Install one immutable locked Module')
            ->addArgument('module_key', Argument::REQUIRED, 'Plugin key')
            ->addOption('dry-run', null, Option::VALUE_NONE, 'Only output the locked install plan');
    }

    protected function handle(Input $input, Output $output): int
    {
        $key = trim((string) $input->getArgument('module_key'));
        if ($input->getOption('dry-run')) {
            return $this->runModuleOperation($output, static fn($service): array => $service->installPlan($key));
        }
        return $this->runModuleOperation($output, static fn($service): array => $service->install($key));
    }
}
