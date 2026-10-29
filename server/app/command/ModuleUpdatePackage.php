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

final class ModuleUpdatePackage extends ModuleContextualCommand
{
    protected function configure()
    {
        $this->setName('module:update-package')->setDescription('Verify and explicitly update one installed Module package')
            ->addArgument('package', Argument::REQUIRED, 'Tar package path')
            ->addOption('sha256', null, Option::VALUE_REQUIRED, 'Expected archive SHA-256', '')
            ->addOption('signature-key-id', null, Option::VALUE_REQUIRED, 'Required trusted signature key id', '')
            ->addOption('dry-run', null, Option::VALUE_NONE, 'Return the verified update plan without product-state writes');
    }

    protected function handle(Input $input, Output $output): int
    {
        try {
            $this->assertDevelopmentInstanceMaintenanceAccess();
            $result = $this->moduleRuntime()->update(
                (string) $input->getArgument('package'),
                ($pin = trim((string) $input->getOption('sha256'))) === '' ? null : $pin,
                ($keyId = trim((string) $input->getOption('signature-key-id'))) === '' ? null : $keyId,
                (bool) $input->getOption('dry-run'),
            );
            $output->writeln((string) json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            return 0;
        } catch (PluginPackageException|PluginLifecycleException $exception) {
            $output->writeln((string) json_encode(
                [
                    'code' => $exception->errorCode,
                    'reason' => $exception->getMessage(),
                    'remediation' => $this->remediation($exception->errorCode),
                ] + ($exception instanceof PluginPackageException ? $exception->details : []),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            ));
            return $exception->getCode() > 0 && $exception->getCode() < 256 ? $exception->getCode() : 1;
        } catch (\Throwable) {
            $output->writeln('{"code":"MODULE_PACKAGE_UPDATE_FAILED","reason":"Package update failed.","remediation":"Inspect the restricted operator log and keep the current recovery state."}');
            return 1;
        }
    }

    private function remediation(string $code): string
    {
        return match ($code) {
            'PLUGIN_DOWNGRADE_REJECTED' => 'Build a version higher than the installed Package and review its fixed content digest.',
            'PACKAGE_VERSION_IDENTITY_CONFLICT' => 'Publish changed contents under a higher immutable version.',
            'PLUGIN_UPDATE_SCOPE_CHANGED' => 'Keep the existing Bundle member set; deliver membership changes as a separately approved lifecycle decision.',
            'PACKAGE_UPDATE_RECOVERY_REQUIRED' => 'Keep maintenance active and use the returned recovery pointer with a verified paired backup.',
            'PLUGIN_NOT_INSTALLED' => 'Install the Package before requesting an update.',
            default => 'Correct the reported preflight condition and rerun dry-run before updating.',
        };
    }
}
