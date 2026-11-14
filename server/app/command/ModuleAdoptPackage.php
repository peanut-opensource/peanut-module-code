<?php

declare(strict_types=1);

namespace app\command;

use app\common\execution\ContextualCommand;
use app\platform\services\module\ModulePackageAdoptionService;
use app\platform\exception\module\ModulePackageException;
use app\platform\exception\module\ModuleLifecycleException;
use think\console\Input;
use think\console\Output;
use think\console\input\Argument;
use think\console\input\Option;
use think\facade\Config;

/** Development-only, database-free adoption and recovery of a verified Module package. */
final class ModuleAdoptPackage extends ContextualCommand
{
    protected function configure()
    {
        $this->setName('module:adopt-package')->setDescription('Adopt a reviewed digest-pinned Module package into development source')
            ->addArgument('package', Argument::OPTIONAL, 'Tar package path')
            ->addOption('sha256', null, Option::VALUE_REQUIRED, 'Required archive SHA-256')
            ->addOption('official-key', null, Option::VALUE_REQUIRED, 'Exact official Module key bound to a reviewed upstream archive digest')
            ->addOption('signature-key-id', null, Option::VALUE_REQUIRED, 'Optional trusted signature key id when explicitly selected')
            ->addOption('composition', null, Option::VALUE_REQUIRED, 'MT pre-install composition request JSON path')
            ->addOption('plan-sha256', null, Option::VALUE_REQUIRED, 'Prepare the complete source graph only if its plan SHA matches')
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
            $service = new ModulePackageAdoptionService(
                dirname(__DIR__, 2),
                $trusted,
                (string) Config::get('peanut.environment', ''),
            );
            if ($input->getOption('composition') !== null) {
                if ($input->getOption('recover') || $input->getArgument('package') !== null
                    || $input->getOption('sha256') !== null || $input->getOption('official-key') !== null
                    || $input->getOption('signature-key-id') !== null) {
                    throw new ModulePackageException('MODULE_COMPOSITION_REQUEST_INVALID', 'Composition and single-package operations are separate.');
                }
                $request = json_decode((string) file_get_contents((string) $input->getOption('composition')), true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($request)) {
                    throw new ModulePackageException('MODULE_COMPOSITION_REQUEST_INVALID', 'Composition request must be an object.');
                }
                $result = $service->compose($request, $input->getOption('plan-sha256'));
            } else {
                if ($input->getOption('plan-sha256') !== null) {
                    throw new ModulePackageException('MODULE_COMPOSITION_REQUEST_INVALID', 'A plan SHA requires a composition request.');
                }
                $result = $input->getOption('recover') ? $service->recover() : $service->adopt(
                    (string) $input->getArgument('package'),
                    $input->getOption('sha256'),
                    $input->getOption('signature-key-id'),
                    $input->getOption('official-key'),
                );
            }
            $output->writeln(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            return ($result['plan']['status'] ?? null) === 'blocked' ? 1 : 0;
        } catch (ModulePackageException|ModuleLifecycleException $exception) {
            return $this->failure($output, $exception->errorCode, $exception->getMessage());
        } catch (\JsonException $exception) {
            return $this->failure($output, 'MODULE_COMPOSITION_REQUEST_INVALID', $exception->getMessage());
        } catch (\Throwable $exception) {
            return $this->failure($output, 'MODULE_PACKAGE_ADOPTION_FAILED', $exception->getMessage());
        }
    }

    private function failure(Output $output, string $code, string $message): int
    {
        try {
            $recovery = (new \app\platform\infrastructure\module\ModulePackageSourcePromoter(dirname(__DIR__, 2)))->recoveryState();
        } catch (\Throwable $exception) {
            $recovery = ['status' => 'unknown', 'message' => $exception->getMessage(),
                'journal' => '.local/module-source-adoption/journal.json'];
        }
        $output->writeln(json_encode(['schema_version' => 1, 'error' => $code, 'message' => $message, 'recovery' => $recovery], JSON_THROW_ON_ERROR));
        return 1;
    }
}
