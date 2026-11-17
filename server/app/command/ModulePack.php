<?php

declare(strict_types=1);

namespace app\command;

use app\platform\services\module\ModulePackageArchiveService;
use app\common\execution\ContextualCommand;
use app\common\validation\instance\InstanceToolAccessGuard;
use app\platform\exception\module\ModulePackageException;
use Composer\Semver\Comparator;
use think\console\Input;
use think\console\input\Argument;
use think\console\input\Option;
use think\console\Output;

final class ModulePack extends ContextualCommand
{
    use ModulePackageCommandSupport;

    protected function configure()
    {
        $this->setName('module:pack')->setDescription('Build one deterministic self-contained Module tar package')
            ->addArgument('module_key', Argument::REQUIRED, 'Module key')
            ->addOption('prepare-candidate', null, Option::VALUE_NONE, 'Advance selected Module declarations to the next local patch RC; do not pack')
            ->addOption('plan-only', null, Option::VALUE_NONE, 'Preview candidate declaration changes without writing (requires --prepare-candidate)')
            ->addOption('output', null, Option::VALUE_REQUIRED, 'Project-relative or absolute output tar path', '')
            ->addOption('signing-key-id', null, Option::VALUE_REQUIRED, 'Trusted Ed25519 signing key id', '')
            ->addOption('signing-secret-key-file', null, Option::VALUE_REQUIRED, 'Base64 Ed25519 secret key file', '');
    }

    protected function handle(Input $input, Output $output): int
    {
        return $this->runPackageCommand($output, function () use ($input): array {
            $key = trim((string) $input->getArgument('module_key'));
            $service = new ModulePackageArchiveService(dirname(__DIR__, 2));
            $inspection = (new \app\common\module\PackageCheck(dirname(__DIR__, 3)))->inspect($key);
            if ($input->getOption('prepare-candidate')) {
                $app = $this->getApp();
                if (!InstanceToolAccessGuard::fromConfiguredValue($app->config->get('deployment.mode'))
                    ->allowsCliDevelopmentMaintenance(
                        $app->config->get('peanut.environment'), $app->isDebug(),
                        $this->executionContext(), $this->getName(), $this->establishedInstanceContext(),
                    )) {
                    throw new ModulePackageException('MODULE_CANDIDATE_ACCESS_DENIED', 'Candidate preparation requires a development/debug CLI command.');
                }
                if ($input->getOption('output') !== '' || $input->getOption('signing-key-id') !== ''
                    || $input->getOption('signing-secret-key-file') !== '') {
                    throw new ModulePackageException('MODULE_CANDIDATE_OPTIONS_INVALID', 'Candidate preparation does not produce or sign an archive.');
                }
                return $this->prepareCandidate($inspection, (bool) $input->getOption('plan-only'));
            }
            if ($input->getOption('plan-only')) {
                throw new ModulePackageException('MODULE_CANDIDATE_OPTIONS_INVALID', '--plan-only requires --prepare-candidate.');
            }
            return $service->packModule(
                $key,
                $this->packageOutput($key, $inspection['version'], trim((string) $input->getOption('output'))),
                $this->packageSigner($input),
            );
        });
    }

    /** @param array<string,mixed> $inspection @return array<string,mixed> */
    private function prepareCandidate(array $inspection, bool $planOnly): array
    {
        $current = $inspection['version'];
        if (preg_match('/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:-rc\.([1-9][0-9]*))?$/D', $current, $parts) !== 1) {
            throw new ModulePackageException('MODULE_CANDIDATE_VERSION_INVALID', 'Candidate preparation requires a stable version or an rc.N version.');
        }
        $next = isset($parts[4])
            ? "{$parts[1]}.{$parts[2]}.{$parts[3]}-rc." . ((int) $parts[4] + 1)
            : "{$parts[1]}.{$parts[2]}." . ((int) $parts[3] + 1) . '-rc.1';
        if (strlen($next) > 32 || !Comparator::greaterThan($next, $current)) {
            throw new ModulePackageException('MODULE_CANDIDATE_VERSION_INVALID', 'The next candidate must be a supported, increasing version.');
        }

        $root = dirname(__DIR__, 3);
        $paths = [$inspection['backend_relative'] . '/module.json', $inspection['backend_relative'] . '/composer.json'];
        foreach ($inspection['frontend_contributions'] as $contribution) {
            $paths[] = $contribution['root'] . '/package.json';
        }
        $before = [];
        $after = [];
        $composerName = '';
        foreach (array_unique($paths) as $path) {
            $bytes = (string) file_get_contents($root . '/' . $path);
            $document = json_decode($bytes, false, 512, JSON_THROW_ON_ERROR);
            if (($document->version ?? null) !== $current) {
                throw new ModulePackageException('MODULE_CANDIDATE_DECLARATION_MISMATCH', "Align {$path} with module.json before preparing a candidate.");
            }
            if (str_ends_with($path, '/composer.json')) {
                $composerName = $document->name;
            }
            $document->version = $next;
            $updated = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $indent = preg_match('/^([ \t]+)"/m', $bytes, $spacing) === 1 ? $spacing[1] : '  ';
            $updated = preg_replace_callback('/^( +)/m', static fn(array $match): string => str_repeat($indent, intdiv(strlen($match[1]), 4)), $updated) . "\n";
            $before[$path] = $bytes;
            $after[$path] = $updated;
        }
        if (!$planOnly) {
            $attempted = [];
            try {
                foreach ($after as $path => $bytes) {
                    $attempted[] = $path;
                    if (file_put_contents($root . '/' . $path, $bytes, LOCK_EX) !== strlen($bytes)) {
                        throw new ModulePackageException('MODULE_CANDIDATE_WRITE_FAILED', "Cannot update {$path}.");
                    }
                }
            } catch (\Throwable $exception) {
                foreach ($attempted as $path) {
                    $bytes = $before[$path];
                    if (file_put_contents($root . '/' . $path, $bytes, LOCK_EX) !== strlen($bytes)) {
                        throw new ModulePackageException('MODULE_CANDIDATE_RESTORE_FAILED', "Restore {$path} from the reviewed source before continuing.", 0, $exception);
                    }
                }
                throw $exception;
            }
        }
        return [
            'status' => $planOnly ? 'candidate-plan' : 'candidate-declarations-prepared',
            'package_key' => $inspection['key'],
            'previous_version' => $current,
            'version' => $next,
            'files' => array_keys($after),
            'archive' => null,
            'preparation_required' => [
                'composer_package' => $composerName,
                'module_lock' => 'peanut module lock --write',
                'dependencies' => 'Prepare only affected Composer/workspace dependencies through native application tooling before packing.',
            ],
        ];
    }
}
