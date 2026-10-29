<?php

declare(strict_types=1);

namespace app\command;

use app\platform\composition\plugin\ModuleDefinitionRegistryFactory;
use PeanutAdmin\Kernel\Module\CompiledModuleRegistry;
use think\console\Command;
use think\console\Input;
use think\console\Output;

/** Builds local HTTP declarations without migrations or database access. */
final class ModuleCompile extends Command
{
    protected function configure()
    {
        $this->setName('module:compile')->setDescription('Compile and verify Module declarations before serving source HTTP');
    }

    protected function execute(Input $input, Output $output): int
    {
        $app = $this->getApp();
        if (is_file($app->getRootPath() . '.peanut/release-identity.json')) {
            throw new \RuntimeException('Server releases use the existing verified deployment publisher.');
        }
        $registry = $app->make(CompiledModuleRegistry::class);
        (new ModuleDefinitionRegistryFactory(rtrim($app->getRootPath(), '/')))
            ->fromCompiled($app->config->get('modules', []));
        $output->writeln(json_encode(['status' => 'compiled', 'modules' => count($registry->modules),
            'revision' => $registry->revision], JSON_THROW_ON_ERROR));
        return 0;
    }
}
