<?php

declare(strict_types=1);

namespace app\command;

use app\common\execution\ContextualCommand;
use app\platform\services\plugin\PluginPackageAdoptionService;
use app\platform\exception\plugin\PluginPackageException;
use app\platform\exception\plugin\PluginLifecycleException;
use think\console\Input;
use think\console\Output;
use think\console\input\Argument;
use think\console\input\Option;
use think\facade\Config;

/** Development-only, database-free adoption and recovery of a verified private Module package. */
final class ModuleAdoptPackage extends ContextualCommand
{
    protected function configure()
    {
        $this->setName('module:adopt-package')->setDescription('Adopt a reviewed digest-pinned private package into development source')
            ->addArgument('package', Argument::OPTIONAL, 'Tar package path')
            ->addOption('sha256', null, Option::VALUE_REQUIRED, 'Required archive SHA-256')
            ->addOption('signature-key-id', null, Option::VALUE_REQUIRED, 'Optional trusted signature key id when explicitly selected')
            ->addOption('recover', null, Option::VALUE_NONE, 'Resume the pending source/lock transaction only');
    }

    /** Trust comes from application configuration; adoption never invokes Runtime install or tenant enable. */
    protected function handle(Input $input, Output $output): int
    {
        try {
            $trusted = [];
            foreach ((array) Config::get('module_packages.trusted_ed25519_keys', []) as $key => $encoded) {
                $bytes = is_string($encoded) ? base64_decode($encoded, true) : false;
                if (is_string($key) && is_string($bytes) && strlen($bytes) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                    $trusted[$key] = $bytes;
                }
            }
            $service = new PluginPackageAdoptionService(
                dirname(__DIR__, 2),
                $trusted,
                (string) Config::get('peanut.environment', ''),
            );
            $result = $input->getOption('recover') ? $service->recover() : $service->adopt(
                (string) $input->getArgument('package'),
                $input->getOption('sha256'),
                $input->getOption('signature-key-id'),
            );
            $output->writeln(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            return 0;
        } catch (PluginPackageException|PluginLifecycleException $exception) {
            $output->writeln(json_encode(['error' => $exception->errorCode], JSON_THROW_ON_ERROR));
            return 1;
        } catch (\Throwable) {
            $output->writeln('{"error":"MODULE_PACKAGE_ADOPTION_FAILED"}');
            return 1;
        }
    }
}
