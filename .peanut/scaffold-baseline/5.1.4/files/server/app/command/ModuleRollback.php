<?php

declare(strict_types=1);

namespace app\command;

use app\common\execution\ModuleContextualCommand;
use think\console\Input;
use think\console\input\Argument;
use think\console\Output;

final class ModuleRollback extends ModuleContextualCommand
{
    use ModuleCommandSupport;

    protected function configure()
    {
        $this->setName('module:rollback')->setDescription('Output a preserve-data Plugin rollback plan')
            ->addArgument('module_key', Argument::REQUIRED, 'Plugin key');
    }

    protected function handle(Input $input, Output $output): int
    {
        $key = trim((string) $input->getArgument('module_key'));
        return $this->runModuleOperation($output, static fn($service): array => $service->rollbackPlan($key));
    }
}
