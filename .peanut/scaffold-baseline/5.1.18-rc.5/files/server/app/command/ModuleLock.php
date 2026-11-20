<?php

declare(strict_types=1);

namespace app\command;

use app\platform\exception\module\ModuleArtifactToolException;
use app\platform\infrastructure\module\ModuleArtifactWriter;
use app\common\execution\ContextualCommand;
use app\common\validation\instance\InstanceToolAccessGuard;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

final class ModuleLock extends ContextualCommand
{
    protected function configure()
    {
        $this->setName('module:lock')->setDescription('Write or verify the canonical Plugin lock')
            ->addOption('write', null, Option::VALUE_NONE, 'Write modules.lock')
            ->addOption('check', null, Option::VALUE_NONE, 'Check modules.lock without writing');
    }

    protected function handle(Input $input, Output $output): int
    {
        try {
            $write = (bool) $input->getOption('write');
            $check = (bool) $input->getOption('check');
            if ($write === $check) {
                throw new ModuleArtifactToolException('Specify exactly one of --write or --check.');
            }
            if ($write) {
                $this->assertSourceAuthoringAccess();
            }
            $writer = new ModuleArtifactWriter(dirname(__DIR__, 2));
            $result = $write ? $writer->writeLock() : $writer->checkLock();
            if ($write) {
                $app = $this->getApp();
                $config = $app->config->get('modules', []);
                $factory = new \app\platform\composition\module\ModuleDefinitionRegistryFactory(dirname(__DIR__, 2));
                $resolver = new \app\platform\infrastructure\module\ModuleLockResolver(
                    dirname(__DIR__, 2), (string) $config['module_lock'],
                );
                $registry = $factory->fromModuleLock($resolver, $config, false, true);
                $factory->publishCompiled($registry, $config, $resolver->all());
            }
            $output->writeln((string) json_encode(['status' => $write ? 'written' : 'valid'] + $result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            return 0;
        } catch (ModuleArtifactToolException $exception) {
            $output->writeln((string) json_encode(['error' => $exception->getMessage()], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            return 1;
        }
    }

    private function assertSourceAuthoringAccess(): void
    {
        $app = $this->getApp();
        if (!InstanceToolAccessGuard::fromConfiguredValue($app->config->get('deployment.mode'))
            ->allowsCliDevelopmentMaintenance(
                $app->config->get('peanut.environment'),
                $app->isDebug(),
                $this->executionContext(),
                $this->getName(),
                $this->establishedInstanceContext(),
            )) {
            throw new ModuleArtifactToolException('Plugin source authoring requires a development/debug CLI command.');
        }
    }
}
