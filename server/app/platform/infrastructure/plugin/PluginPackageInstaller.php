<?php

declare(strict_types=1);

namespace app\platform\infrastructure\plugin;

use app\platform\composition\plugin\PluginModuleRegistryFactory;
use app\platform\exception\plugin\PluginLifecycleException;
use app\platform\exception\plugin\PluginPackageException;
use app\platform\services\plugin\PluginLifecycleService;
use app\platform\services\plugin\PluginPackageArchiveService;
use app\platform\value\plugin\PluginDescriptor;
use app\common\persistence\AdvisoryLockExecution;
use app\common\persistence\AdvisoryLockUnavailable;
use think\facade\Config;
use think\facade\Db;

/** Promotes one verified package overlay, rebuilds the canonical lock, then invokes the shared lifecycle. */
final class PluginPackageInstaller
{
    /**
     * @param array<string,mixed> $moduleConfig
     * @param array<string,string> $trustedPublicKeys key_id => raw Ed25519 public key
     */
    public function __construct(
        private readonly string $serverRoot,
        private readonly array $moduleConfig,
        private readonly array $trustedPublicKeys,
        private readonly ModuleCatalogApplier $catalogs,
    ) {}

    /**
     * Verifies and promotes an archive, then runs the deployment-level install lifecycle.
     *
     * @return array<string,mixed>
     */
    public function install(
        string $archivePath,
        ?string $expectedSha256,
        ?string $signatureKeyId,
    ): array {
        return (new PluginPackageSourcePromoter($this->serverRoot))->run(
            fn(): array => $this->promote('install', $archivePath, $expectedSha256, $signatureKeyId, false),
        );
    }

    /**
     * Verifies and plans an update; dry-run returns before source promotion or lock changes.
     *
     * @return array<string,mixed>
     */
    public function update(
        string $archivePath,
        ?string $expectedSha256,
        ?string $signatureKeyId,
        bool $dryRun,
    ): array {
        return (new PluginPackageSourcePromoter($this->serverRoot))->run(
            fn(): array => $this->promote('update', $archivePath, $expectedSha256, $signatureKeyId, $dryRun),
        );
    }

    /** @return array<string,mixed> */
    private function promote(
        string $operation,
        string $archivePath,
        ?string $expectedSha256,
        ?string $signatureKeyId,
        bool $dryRun,
    ): array {
        $archive = new PluginPackageArchiveService($this->serverRoot);
        $current = $this->currentDescriptors();
        $availableVersions = $this->moduleVersions($current);
        $package = null;
        try {
            $package = $archive->verify(
                $archivePath,
                $expectedSha256,
                $this->trustedPublicKeys,
                $signatureKeyId,
                $availableVersions,
            );
            foreach ($package->modules as $module) {
                \app\common\module\PackageCheck::assertEdition($module['manifest']->root, (string) Config::get('deployment.mode', ''));
            }
            // Verify the untrusted archive first, but do not mutate source/lock until the application schema exists.
            $this->assertApplicationInstalled();
            if ($operation === 'update') {
                $plan = $this->updatePlan($package, $current);
                if ($dryRun) {
                    return $plan + ['operation' => 'update', 'dry_run' => true];
                }
            }
            $promoted = [];
            $replaced = [];
            $recoveryRoot = null;
            $lockPath = $this->projectRoot() . '/plugins.lock';
            $lockBefore = is_file($lockPath) ? file_get_contents($lockPath) : null;
            $lifecycleStarted = false;
            $plan = null;
            $lockName = 'pa:module-runtime:' . substr(hash('sha256', $package->packageKey), 0, 40);
            try {
                return (new AdvisoryLockExecution())->run($lockName, 0, function () use (
                    $operation,
                    $package,
                    &$promoted,
                    &$replaced,
                    &$recoveryRoot,
                    $lockPath,
                    $lockBefore,
                    &$lifecycleStarted,
                    &$plan,
                ): array {
                    try {
                        $current = $this->currentDescriptors();
                        $plan = $operation === 'update' ? $this->updatePlan($package, $current) : null;
                        foreach ($current as $pluginKey => $descriptor) {
                            foreach (array_keys($descriptor->moduleRoots) as $moduleKey) {
                                if (isset($package->modules[$moduleKey]) && $pluginKey !== $package->packageKey) {
                                    throw new PluginPackageException('PLUGIN_MODULE_CONFLICT', 'Module is owned by another package.');
                                }
                            }
                        }
                        $recoverableReplacement = $operation === 'update'
                            || $this->recoverableReplacement($package, $current);
                        $scopes = [$package->manifestRelative => dirname($package->manifestRelative)];
                        foreach ($package->modules as $module) {
                            $scopes[$module['backend_relative']] = $module['backend_relative'];
                            foreach ($module['frontend_contributions'] as $contribution) {
                                $scopes[$contribution['root']] = $contribution['root'];
                            }
                        }
                        $scopeRoots = array_values(array_unique(array_map(
                            static fn(string $scope): string => str_ends_with($scope, 'plugin.json') ? dirname($scope) : $scope,
                            array_keys($scopes),
                        )));
                        usort($scopeRoots, static fn(string $left, string $right): int => strlen($right) <=> strlen($left));
                        foreach ($scopeRoots as $relative) {
                            $source = $package->stageRoot . '/' . $relative;
                            $target = $this->projectRoot() . '/' . $relative;
                            if (file_exists($target)) {
                                if (!$this->scopeMatches($relative, $target, $package->inventory)) {
                                    if (!$recoverableReplacement) {
                                        throw new PluginPackageException('MODULE_PACKAGE_TARGET_CONFLICT', 'Package target contains a different identity.');
                                    }
                                    $recoveryRoot ??= $this->recoveryRoot($package->packageKey);
                                    $backup = $recoveryRoot . '/' . $relative;
                                    if (!is_dir(dirname($backup)) && !mkdir(dirname($backup), 0700, true) && !is_dir(dirname($backup))) {
                                        throw new PluginPackageException('MODULE_PACKAGE_RECOVERY_FAILED', 'Package recovery backup cannot be created.');
                                    }
                                    if (!rename($target, $backup)) {
                                        throw new PluginPackageException('MODULE_PACKAGE_RECOVERY_FAILED', 'Package recovery backup cannot be promoted.');
                                    }
                                    $replaced[$target] = $backup;
                                } else {
                                    $this->removeTree($source);
                                    continue;
                                }
                            }
                            $parent = dirname($target);
                            if (!is_dir($parent) && !mkdir($parent, 0775, true) && !is_dir($parent)) {
                                throw new PluginPackageException('MODULE_PACKAGE_PROMOTION_FAILED', 'Package target parent is unavailable.');
                            }
                            if (!rename($source, $target)) {
                                throw new PluginPackageException('MODULE_PACKAGE_PROMOTION_FAILED', 'Package target promotion failed.');
                            }
                            $promoted[] = $target;
                        }

                        (new PluginArtifactWriter($this->serverRoot))->writeLock();
                        $resolver = new PluginLockResolver($this->serverRoot, '../plugins.lock');
                        $lifecycle = new PluginLifecycleService(
                            $resolver,
                            new PluginModuleRegistryFactory($this->serverRoot),
                            $this->moduleConfig,
                            $this->catalogs,
                            new \PeanutAdmin\Modules\Identity\Contract\TenantModuleStateQueries(),
                        );
                        $lifecycleStarted = true;
                        $result = $operation === 'update'
                            ? $lifecycle->upgrade($package->packageKey, false)
                            : $lifecycle->install($package->packageKey);
                        if ($operation === 'install') {
                            $this->clearQuarantine($package->packageKey);
                        }
                        if (is_string($recoveryRoot)) {
                            $this->removeTree($recoveryRoot);
                        }
                        return $result + [
                            'archive_sha256' => $package->archiveSha256,
                            'package_key' => $package->packageKey,
                            'modules' => array_map(
                                static fn(array $module): array => [
                                    'module_key' => $module['key'],
                                    'version' => $module['version'],
                                    'status' => 'active',
                                ],
                                array_values($package->modules),
                            ),
                            'dry_run' => false,
                            'plan' => $plan,
                        ];
                    } catch (\Throwable $exception) {
                        if (!$lifecycleStarted) {
                            if (is_string($lockBefore)) {
                                $this->writeAtomic($lockPath, $lockBefore);
                            } elseif (is_file($lockPath)) {
                                unlink($lockPath);
                            }
                            foreach (array_reverse($promoted) as $target) {
                                $this->removeTree($target);
                            }
                            foreach (array_reverse($replaced, true) as $target => $backup) {
                                if (file_exists($backup) && !rename($backup, $target)) {
                                    throw new PluginPackageException('MODULE_PACKAGE_RECOVERY_FAILED', 'Package recovery restore failed.', 0, $exception);
                                }
                            }
                        }
                        if ($operation === 'update' && $lifecycleStarted) {
                            $recoveryRoot ??= $this->recoveryRoot($package->packageKey);
                            $pointer = $this->writeUpdateRecoveryPointer(
                                $recoveryRoot,
                                $package,
                                $plan ?? [],
                                $exception,
                            );
                            throw new PluginPackageException(
                                'PACKAGE_UPDATE_RECOVERY_REQUIRED',
                                'Package update stopped in a recoverable failed state.',
                                0,
                                $exception,
                                ['recovery_pointer' => $pointer],
                            );
                        }
                        if (is_string($recoveryRoot) && is_dir($recoveryRoot)) {
                            $this->removeTree($recoveryRoot);
                        }
                        throw $exception;
                    }
                });
            } catch (AdvisoryLockUnavailable) {
                throw new PluginLifecycleException('MODULE_LIFECYCLE_BUSY', 'Module lifecycle is busy.');
            }
        } finally {
            if ($package instanceof VerifiedPluginPackage) {
                $archive->cleanup($package);
            }
        }
    }

    /**
     * Package promotion mutates source and plugins.lock, so an application schema must already exist.
     * Fresh application installation owns schema creation; module delivery must never become that bootstrap path.
     */
    private function assertApplicationInstalled(): void
    {
        try {
            $connection = (string) Config::get('database.default', 'mysql');
            $prefix = Config::get("database.connections.{$connection}.prefix", '');
            if (!is_string($prefix) || preg_match('/^[A-Za-z0-9_]*$/D', $prefix) !== 1) {
                throw new RuntimeException('Database table prefix is invalid.');
            }
            $expected = [
                $prefix . 'plugin_installation',
                $prefix . 'module_installation',
            ];
            $rows = Db::query(
                'SELECT table_name AS table_name FROM information_schema.tables '
                . 'WHERE table_schema = DATABASE() AND table_name IN (?, ?)',
                $expected,
            );
            $tables = array_fill_keys(array_map(
                static fn(array $row): string => (string) ($row['table_name'] ?? ''),
                $rows,
            ), true);
        } catch (\Throwable $exception) {
            throw new PluginPackageException(
                'INSTALLATION_REQUIRED',
                'Application installation must complete before module package delivery.',
                0,
                $exception,
            );
        }
        if (!isset($tables[$expected[0]], $tables[$expected[1]])) {
            throw new PluginPackageException(
                'INSTALLATION_REQUIRED',
                'Application installation must complete before module package delivery.',
            );
        }
    }

    /** @param array<string,PluginDescriptor> $current @return array<string,mixed> */
    private function updatePlan(VerifiedPluginPackage $package, array $current): array
    {
        $descriptor = $current[$package->packageKey] ?? null;
        if (!$descriptor instanceof PluginDescriptor) {
            throw new PluginPackageException('PLUGIN_NOT_INSTALLED', 'Package must be installed before update.');
        }
        $installation = Db::name('plugin_installation')->where('plugin_key', $package->packageKey)
            ->field('installed_version,status,artifact_sha256,lock_digest')->find();
        if ($installation === null) {
            throw new PluginPackageException('PLUGIN_NOT_INSTALLED', 'Package must be installed before update.');
        }
        if (!in_array($installation['status'] ?? null, ['active', 'failed'], true)) {
            throw new PluginPackageException('PLUGIN_STATE_INVALID', 'Package cannot be updated from its current state.');
        }
        $comparison = version_compare($package->packageVersion, (string) $installation['installed_version']);
        if ($comparison < 0) {
            throw new PluginPackageException('PLUGIN_DOWNGRADE_REJECTED', 'Package update cannot install an older version.');
        }
        $sameContents = hash_equals(
            (string) $descriptor->source['sha256'],
            (string) $package->descriptor->source['sha256'],
        );
        if ($comparison === 0 && !$sameContents) {
            throw new PluginPackageException(
                'PACKAGE_VERSION_IDENTITY_CONFLICT',
                'Package version already exists with another immutable identity.',
            );
        }
        $currentModules = array_keys($descriptor->moduleRoots);
        $targetModules = array_keys($package->modules);
        sort($currentModules, SORT_STRING);
        sort($targetModules, SORT_STRING);
        if ($currentModules !== $targetModules) {
            throw new PluginPackageException(
                'PLUGIN_UPDATE_SCOPE_CHANGED',
                'Package update cannot add or remove Bundle members.',
            );
        }
        return [
            'schema_version' => 1,
            'package_key' => $package->packageKey,
            'source' => [
                'version' => (string) $installation['installed_version'],
                'artifact_sha256' => (string) $installation['artifact_sha256'],
                'lock_digest' => (string) $installation['lock_digest'],
                'status' => (string) $installation['status'],
            ],
            'target' => [
                'version' => $package->packageVersion,
                'archive_sha256' => $package->archiveSha256,
                'artifact_sha256' => (string) $package->descriptor->source['sha256'],
                'modules' => $targetModules,
            ],
            'unchanged' => $comparison === 0 && $sameContents && $installation['status'] === 'active',
        ];
    }

    /** @param array<string,mixed> $plan @return array<string,mixed> */
    private function writeUpdateRecoveryPointer(
        string $recoveryRoot,
        VerifiedPluginPackage $package,
        array $plan,
        \Throwable $exception,
    ): array {
        $key = basename($recoveryRoot);
        $errorCode = $exception instanceof PluginPackageException || $exception instanceof PluginLifecycleException
            ? $exception->errorCode
            : 'PLUGIN_LIFECYCLE_FAILED';
        $document = [
            'schema_version' => 1,
            'recovery_key' => $key,
            'package_key' => $package->packageKey,
            'archive_sha256' => $package->archiveSha256,
            'plan' => $plan,
            'error_code' => $errorCode,
            'automatic_rollback' => false,
            'requires_verified_backup_for_irreversible_migration' => true,
        ];
        $this->writeAtomic(
            $recoveryRoot . '/recovery.json',
            json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
        );
        return [
            'schema_version' => 1,
            'recovery_key' => $key,
            'package_key' => $package->packageKey,
            'error_code' => $errorCode,
            'automatic_rollback' => false,
        ];
    }

    /** @return array<string,PluginDescriptor> */
    private function currentDescriptors(): array
    {
        $journal = $this->projectRoot() . '/.local/module-source-adoption/journal.json';
        if (file_exists($journal) || is_link($journal)) {
            throw new PluginPackageException('MODULE_PACKAGE_RECOVERY_REQUIRED', 'Recover development source adoption before Runtime package operations.');
        }
        $lockPath = $this->projectRoot() . '/plugins.lock';
        if (!is_file($lockPath)) {
            return [];
        }
        return (new PluginLockResolver($this->serverRoot, '../plugins.lock'))->all();
    }

    /** @param array<string,PluginDescriptor> $descriptors @return array<string,string> */
    private function moduleVersions(array $descriptors): array
    {
        $versions = [];
        foreach ($descriptors as $descriptor) {
            foreach ($descriptor->moduleRoots as $moduleKey => $root) {
                try {
                    $manifest = json_decode((string) file_get_contents($root . '/module.json'), true, 32, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    continue;
                }
                if (is_array($manifest) && is_string($manifest['version'] ?? null)) {
                    $versions[$moduleKey] = $manifest['version'];
                }
            }
        }
        return $versions;
    }

    /** @param array<string,string> $inventory */
    private function scopeMatches(string $relativeRoot, string $targetRoot, array $inventory): bool
    {
        $expected = [];
        $prefix = rtrim($relativeRoot, '/') . '/';
        foreach ($inventory as $path => $digest) {
            if (str_starts_with($path, $prefix)) {
                $expected[$path] = $digest;
            }
        }
        if ($expected === []) {
            return false;
        }
        $actual = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($targetRoot, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if ($file->isLink() || !$file->isFile()) {
                return false;
            }
            $relative = ltrim(substr($file->getPathname(), strlen($this->projectRoot())), '/');
            $digest = hash_file('sha256', $file->getPathname());
            if (!is_string($digest)) {
                return false;
            }
            $actual[$relative] = $digest;
        }
        ksort($expected, SORT_STRING);
        ksort($actual, SORT_STRING);
        return $actual === $expected;
    }

    private function writeAtomic(string $path, string $contents): void
    {
        $temporary = $path . '.tmp-' . bin2hex(random_bytes(8));
        if (file_put_contents($temporary, $contents, LOCK_EX) === false || !rename($temporary, $path)) {
            if (is_file($temporary)) {
                unlink($temporary);
            }
            throw new PluginPackageException('MODULE_PACKAGE_RECOVERY_FAILED', 'Package lock recovery failed.');
        }
    }

    /** @param array<string,PluginDescriptor> $current */
    private function recoverableReplacement(VerifiedPluginPackage $package, array $current): bool
    {
        $descriptor = $current[$package->packageKey] ?? null;
        if (!$descriptor instanceof PluginDescriptor) {
            return false;
        }
        $installation = Db::name('plugin_installation')->where('plugin_key', $package->packageKey)
            ->field('installed_version,status')->find();
        if ($installation === null
            || !in_array($installation['status'] ?? null, ['failed', 'installing'], true)
            || version_compare($package->packageVersion, (string) $installation['installed_version'], '<=')) {
            return false;
        }
        $currentModules = array_keys($descriptor->moduleRoots);
        $replacementModules = array_keys($package->modules);
        sort($currentModules, SORT_STRING);
        sort($replacementModules, SORT_STRING);
        return $currentModules === $replacementModules;
    }

    private function recoveryRoot(string $packageKey): string
    {
        $root = $this->projectRoot() . '/.local/module-install-recovery/' . $packageKey . '-' . bin2hex(random_bytes(12));
        if (!mkdir($root, 0700, true) && !is_dir($root)) {
            throw new PluginPackageException('MODULE_PACKAGE_RECOVERY_FAILED', 'Package recovery root cannot be created.');
        }
        return $root;
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            if (is_file($path)) {
                unlink($path);
            }
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($path);
    }

    private function clearQuarantine(string $packageKey): void
    {
        if (preg_match('/^[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*$/D', $packageKey) !== 1) {
            throw new PluginPackageException('MODULE_QUARANTINE_INVALID', 'Module package key is invalid.');
        }
        $root = $this->projectRoot() . '/.local/module-quarantine';
        if (!is_dir($root)) {
            return;
        }
        $resolvedRoot = realpath($root);
        if (!is_string($resolvedRoot)) {
            throw new PluginPackageException('MODULE_QUARANTINE_INVALID', 'Module quarantine root is invalid.');
        }
        foreach (glob($root . '/' . $packageKey . '-*', GLOB_ONLYDIR) ?: [] as $candidate) {
            $resolved = realpath($candidate);
            if (is_link($candidate) || !is_string($resolved)
                || !str_starts_with($resolved, $resolvedRoot . DIRECTORY_SEPARATOR)) {
                throw new PluginPackageException('MODULE_QUARANTINE_INVALID', 'Module quarantine path is invalid.');
            }
            $this->removeTree($resolved);
            if (file_exists($resolved)) {
                throw new PluginPackageException('MODULE_QUARANTINE_FAILED', 'Module quarantine cannot be cleared after install.');
            }
        }
    }

    private function projectRoot(): string
    {
        return dirname($this->serverRoot);
    }
}
