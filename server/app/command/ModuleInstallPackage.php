<?php

declare(strict_types=1);

namespace app\command;

use app\platform\exception\plugin\PluginLifecycleException;
use app\platform\exception\plugin\PluginPackageException;
use app\common\execution\ModuleContextualCommand;
use think\console\Input;
use think\console\input\Argument;
use think\console\input\Option;
use think\console\Output;

final class ModuleInstallPackage extends ModuleContextualCommand
{
    protected function configure()
    {
        $this->setName('module:install-package')->setDescription('Verify and install one self-contained Module package')
            ->addArgument('package', Argument::REQUIRED, 'Tar package path')
            ->addOption('sha256', null, Option::VALUE_REQUIRED, 'Expected archive SHA-256', '')
            ->addOption('signature-key-id', null, Option::VALUE_REQUIRED, 'Required trusted signature key id', '');
    }

    protected function handle(Input $input, Output $output): int
    {
        try {
            $this->assertDevelopmentInstanceMaintenanceAccess();
            $result = $this->moduleRuntime()->install(
                (string) $input->getArgument('package'),
                ($pin = trim((string) $input->getOption('sha256'))) === '' ? null : $pin,
                ($keyId = trim((string) $input->getOption('signature-key-id'))) === '' ? null : $keyId,
            );
            $output->writeln((string) json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            return 0;
        } catch (PluginPackageException|PluginLifecycleException $exception) {
            $output->writeln((string) json_encode(
                ['error' => $exception->errorCode, 'reason' => $exception->getMessage()]
                    + ($exception instanceof PluginPackageException ? ['details' => $exception->details] : []),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            ));
            return $exception->getCode() > 0 && $exception->getCode() < 256 ? $exception->getCode() : 1;
        } catch (\Throwable $exception) {
            $output->writeln((string) json_encode(['error' => 'MODULE_PACKAGE_INSTALL_FAILED', 'reason' => $exception->getMessage()], JSON_THROW_ON_ERROR));
            return 1;
        }
    }
}
