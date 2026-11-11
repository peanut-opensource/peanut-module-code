<?php

declare(strict_types=1);

namespace app\command;

use app\platform\exception\module\ModuleArtifactToolException;
use app\platform\infrastructure\module\ModuleArtifactWriter;
use app\common\execution\ContextualCommand;
use app\common\validation\instance\InstanceToolAccessGuard;
use think\console\Input;
use think\console\input\Argument;
use think\console\input\Option;
use think\console\Output;

final class ModuleMake extends ContextualCommand
{
    protected function configure()
    {
        $this->setName('module:make')->setDescription('Generate delivery metadata for one named Module')
            ->addArgument('module_key', Argument::REQUIRED, 'Plugin key')
            ->addArgument('version', Argument::REQUIRED, 'Plugin version')
            ->addOption('module', null, Option::VALUE_REQUIRED | Option::VALUE_IS_ARRAY, 'Module key=project-relative-root');
    }

    protected function handle(Input $input, Output $output): int
    {
        try {
            $this->assertSourceAuthoringAccess();
            $result = (new ModuleArtifactWriter(dirname(__DIR__, 2)))->make(
                (string) $input->getArgument('module_key'),
                (string) $input->getArgument('version'),
                array_values((array) $input->getOption('module')),
            );
            $output->writeln((string) json_encode(['path' => $result['path'], 'key' => $result['manifest']['key']], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
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
