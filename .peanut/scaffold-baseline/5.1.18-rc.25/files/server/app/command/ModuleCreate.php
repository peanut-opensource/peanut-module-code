<?php

declare(strict_types=1);

namespace app\command;

use app\common\exception\module\ModuleScaffoldException;
use app\common\infrastructure\module\ModuleScaffoldGenerator;
use think\console\Command;
use think\console\Input;
use think\console\input\Argument;
use think\console\input\Option;
use think\console\Output;

final class ModuleCreate extends Command
{
    protected function configure()
    {
        $this->setName('module:create')->setDescription('Generate one key-derived Module backend/frontend skeleton')
            ->addArgument('module_key', Argument::REQUIRED, 'Namespaced Module key')
            ->addOption('vendor', null, Option::VALUE_REQUIRED, 'Optional vendor assertion derived from the Module key', '')
            ->addOption('client', null, Option::VALUE_REQUIRED, 'Client contribution: none (default) or admin-web', 'none');
    }

    protected function execute(Input $input, Output $output): int
    {
        try {
            $result = (new ModuleScaffoldGenerator(dirname(__DIR__, 3)))->create(
                (string) $input->getArgument('module_key'),
                (string) $input->getOption('vendor'),
                (string) $input->getOption('client'),
            );
            $output->writeln((string) json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            return 0;
        } catch (ModuleScaffoldException $exception) {
            $output->writeln((string) json_encode(['error' => $exception->errorCode], JSON_THROW_ON_ERROR));
            return 1;
        } catch (\Throwable) {
            $output->writeln('{"error":"MODULE_CREATE_FAILED"}');
            return 1;
        }
    }
}
