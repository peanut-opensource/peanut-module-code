<?php

declare(strict_types=1);

namespace app\command;

use app\adminapi\services\generator\GeneratorService;
use app\common\execution\ContextualCommand;
use app\common\validation\instance\InstanceToolAccessGuard;
use app\common\validation\scaffold\ScaffoldPathGuard;
use RuntimeException;
use think\console\Input;
use think\console\input\Argument;
use think\console\input\Option;
use think\console\Output;

final class ModuleGenerateCrud extends ContextualCommand
{
    protected function configure()
    {
        $this->setName('module:generate-crud')
            ->setDescription('Preview or write CRUD files for a registered tenant-owned Module table')
            ->addArgument('module_key', Argument::REQUIRED, 'Registered Module key')
            ->addArgument('table_name', Argument::REQUIRED, 'Physical database table name')
            ->addOption('write', null, Option::VALUE_NONE, 'Write generated files and merge Module metadata');
    }

    protected function handle(Input $input, Output $output): int
    {
        $this->assertSourceAuthoringAccess();
        $app = $this->getApp();
        $root = ScaffoldPathGuard::projectRoot(dirname(rtrim($app->getRootPath(), '/\\')));
        $write = (bool) $input->getOption('write');
        $lock = $write ? fopen($root, 'r') : null;
        if ($write && ($lock === false || !flock($lock, LOCK_EX))) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            throw new RuntimeException('Cannot acquire the CRUD generation write lock.');
        }
        try {
            $files = $app->make(GeneratorService::class)->renderSource(
                (string) $input->getArgument('module_key'),
                (string) $input->getArgument('table_name'),
                (string) $app->config->get('deployment.mode'),
                $root,
            );
            if ($write) {
                self::apply($root, $files);
            }
        } finally {
            if (is_resource($lock)) {
                fclose($lock);
            }
        }

        foreach ($files as $file) {
            $output->writeln(sprintf('%s %s', $file['operation'], $file['path']));
        }
        return 0;
    }

    /** Stage all bytes before changing any file. Publish each file by same-directory rename. */
    private static function apply(string $root, array $files): void
    {
        $staged = [];
        $published = [];
        $directories = [];
        try {
            foreach ($files as $file) {
                $target = ScaffoldPathGuard::projectPath($root, $file['path']);
                $base = null;
                $mode = 0644;
                if ($file['operation'] === 'merge') {
                    ScaffoldPathGuard::existingFileWithin($root, $target, 'CRUD_MERGE_TARGET_INVALID');
                    $base = file_get_contents($target);
                    $permissions = fileperms($target);
                    if ($base === false || $permissions === false || hash('sha256', $base) !== $file['base_sha256']) {
                        throw new RuntimeException('CRUD_MERGE_SOURCE_CHANGED: ' . $file['path']);
                    }
                    $mode = $permissions & 0777;
                } elseif (file_exists($target)) {
                    throw new RuntimeException('CRUD_CREATE_TARGET_EXISTS: ' . $file['path']);
                }
                $directory = dirname($target);
                for ($parent = $directory; !is_dir($parent); $parent = dirname($parent)) {
                    $directories[] = $parent;
                }
                ScaffoldPathGuard::ensureDirectory($directory);
                $staged[$target] = [
                    'file' => self::stage($directory, $file['content'], $mode),
                    'base' => $base,
                    'backup' => null,
                ];
                if ($base !== null) {
                    $staged[$target]['backup'] = self::stage($directory, $base, $mode);
                }
            }
            foreach ($staged as $target => $entry) {
                clearstatcache(true, $target);
                $unchanged = $entry['base'] === null
                    ? !file_exists($target) && !is_link($target)
                    : is_file($target) && !is_link($target) && file_get_contents($target) === $entry['base'];
                $committed = $unchanged && ($entry['base'] === null
                    ? link($entry['file'], $target)
                    : rename($entry['file'], $target));
                if (!$committed) {
                    throw new RuntimeException('CRUD_TARGET_CHANGED_OR_UNWRITABLE: ' . $target);
                }
                $published[] = $target;
            }
        } catch (\Throwable $failure) {
            $unrestored = [];
            foreach (array_reverse($published) as $target) {
                $backup = $staged[$target]['backup'];
                $restored = $backup === null ? unlink($target) : rename($backup, $target);
                if (!$restored) {
                    $unrestored[] = $backup === null ? $target : $target . ' (original: ' . $backup . ')';
                    $staged[$target]['backup'] = null;
                }
            }
            if ($unrestored !== []) {
                throw new RuntimeException('CRUD_ROLLBACK_FAILED: ' . implode(', ', $unrestored), 0, $failure);
            }
            throw $failure;
        } finally {
            foreach ($staged as $entry) {
                foreach ([$entry['file'], $entry['backup']] as $temporary) {
                    if ($temporary !== null && is_file($temporary)) {
                        unlink($temporary);
                    }
                }
            }
            rsort($directories);
            foreach (array_unique($directories) as $directory) {
                if (is_dir($directory) && scandir($directory) === ['.', '..']) {
                    rmdir($directory);
                }
            }
        }
    }

    private static function stage(string $directory, string $content, int $mode): string
    {
        $path = tempnam($directory, '.peanut-crud-');
        if ($path === false) {
            throw new RuntimeException('CRUD_STAGE_UNAVAILABLE: ' . $directory);
        }
        if (dirname($path) !== $directory || file_put_contents($path, $content) !== strlen($content) || !chmod($path, $mode)) {
            unlink($path);
            throw new RuntimeException('CRUD_STAGE_WRITE_FAILED: ' . $directory);
        }
        return $path;
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
            throw new RuntimeException('CRUD source authoring requires a development/debug CLI command.');
        }
    }
}
