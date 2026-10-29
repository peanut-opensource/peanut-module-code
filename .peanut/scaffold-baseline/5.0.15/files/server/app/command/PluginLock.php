<?php

declare(strict_types=1);

namespace app\command;

use app\platform\exception\plugin\PluginArtifactToolException;
use app\platform\infrastructure\plugin\PluginArtifactWriter;
use app\common\execution\ContextualCommand;
use app\common\validation\instance\InstanceToolAccessGuard;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

final class PluginLock extends ContextualCommand
{
    protected function configure()
    {
        $this->setName('plugin:lock')->setDescription('Write or verify the canonical Plugin lock')
            ->addOption('write', null, Option::VALUE_NONE, 'Write plugins.lock')
            ->addOption('check', null, Option::VALUE_NONE, 'Check plugins.lock without writing');
    }

    protected function handle(Input $input, Output $output): int
    {
        try {
            $write = (bool) $input->getOption('write');
            $check = (bool) $input->getOption('check');
            if ($write === $check) {
                throw new PluginArtifactToolException('Specify exactly one of --write or --check.');
            }
            if ($write) {
                $this->assertSourceAuthoringAccess();
            }
            $writer = new PluginArtifactWriter(dirname(__DIR__, 2));
            $result = $write ? $writer->writeLock() : $writer->checkLock();
            if ($write) {
                $app = $this->getApp();
                $config = $app->config->get('modules', []);
                $factory = new \app\platform\composition\plugin\ModuleDefinitionRegistryFactory(dirname(__DIR__, 2));
                $resolver = new \app\platform\infrastructure\plugin\PluginLockResolver(
                    dirname(__DIR__, 2), (string) $config['plugin_lock'],
                );
                $registry = $factory->fromPluginLock($resolver, $config, false);
                $factory->publishCompiled($registry, $config, $resolver->all());
            }
            $output->writeln((string) json_encode(['status' => $write ? 'written' : 'valid'] + $result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            return 0;
        } catch (PluginArtifactToolException $exception) {
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
            throw new PluginArtifactToolException('Plugin source authoring requires a development/debug CLI command.');
        }
    }
}
