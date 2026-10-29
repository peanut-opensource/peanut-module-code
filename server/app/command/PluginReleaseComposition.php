<?php

declare(strict_types=1);

namespace app\command;

use app\common\execution\ModuleContextualCommand;
use app\platform\exception\plugin\PluginLifecycleException;
use app\platform\validation\plugin\PluginReleaseCompositionGuard;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use think\facade\Config;

/** Runs the read-only Plugin composition gate from the staged application release. */
final class PluginReleaseComposition extends ModuleContextualCommand
{
    /** Exposes only the current immutable release root; the target is always this command's own application. */
    protected function configure()
    {
        $this->setName('plugin:release-composition')
            ->setDescription('Verify current installed Plugins against a staged application release')
            ->addOption('current-root', null, Option::VALUE_REQUIRED, 'Current application release root');
    }

    /** Runs the read-only gate and emits only stable machine-readable status or error codes. */
    protected function handle(Input $input, Output $output): int
    {
        try {
            $currentRoot = trim((string) $input->getOption('current-root'));
            if ($currentRoot === '') {
                throw new PluginLifecycleException(
                    'PLUGIN_RELEASE_CURRENT_ROOT_REQUIRED',
                    'Current application release root is required.',
                );
            }
            $config = Config::get('modules', []);
            if (!is_array($config)) {
                throw new PluginLifecycleException(
                    'MODULE_REGISTRY_UNAVAILABLE',
                    'Module deployment config is invalid.',
                );
            }
            $serverRoot = dirname(__DIR__, 2);
            $result = (new PluginReleaseCompositionGuard(
                dirname($serverRoot),
                $config,
                $this->moduleCatalogs(),
            ))->verify($currentRoot);
            $output->writeln((string) json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            return 0;
        } catch (PluginLifecycleException $exception) {
            $output->writeln((string) json_encode(
                ['error' => $exception->errorCode],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            ));
            return 1;
        } catch (\Throwable) {
            $output->writeln('{"error":"PLUGIN_RELEASE_COMPOSITION_FAILED"}');
            return 1;
        }
    }
}
