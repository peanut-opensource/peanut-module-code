<?php

declare(strict_types=1);

namespace app\command;

use app\platform\exception\plugin\PluginLifecycleException;
use app\common\execution\ModuleContextualCommand;
use think\console\Input;
use think\console\input\Argument;
use think\console\Output;

final class ModuleDisablePackage extends ModuleContextualCommand
{
    protected function configure()
    {
        $this->setName('module:disable-package')
            ->setDescription('Disable one active Module package while preserving its artifact and data')
            ->addArgument('module_key', Argument::REQUIRED, 'Module key or package key');
    }

    protected function handle(Input $input, Output $output): int
    {
        try {
            $this->assertDevelopmentInstanceMaintenanceAccess();
            $moduleKey = trim((string) $input->getArgument('module_key'));
            $result = $this->moduleRuntime()->disable($moduleKey);
            $output->writeln((string) json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            return 0;
        } catch (PluginLifecycleException $exception) {
            $output->writeln((string) json_encode([
                'code' => $exception->errorCode,
                'reason' => $exception->getMessage(),
                'remediation' => $this->remediation($exception->errorCode),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            return 1;
        } catch (\Throwable) {
            $output->writeln('{"code":"MODULE_PACKAGE_DISABLE_FAILED","reason":"Package disable failed.","remediation":"Inspect the restricted operator log and preserve the current Package state."}');
            return 1;
        }
    }

    private function remediation(string $code): string
    {
        return match ($code) {
            'PLUGIN_TENANT_MODULE_ACTIVE' => 'Disable every TenantModule in the Package before disabling the Package.',
            'MODULE_DEPENDENT_INSTALLED' => 'Retire or disable active dependent Packages first.',
            'MODULE_LIFECYCLE_PROTECTED' => 'Protected Modules cannot be disabled.',
            'MODULE_LIFECYCLE_BUSY' => 'Wait for the current Package lifecycle operation to finish and retry.',
            default => 'Correct the reported lifecycle condition and retry the explicit disable operation.',
        };
    }
}
