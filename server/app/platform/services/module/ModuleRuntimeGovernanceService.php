<?php

declare(strict_types=1);

namespace app\platform\services\module;

use app\common\module\Dependencies;
use app\platform\composition\module\ModuleDefinitionRegistryFactory;
use app\common\value\installation\ApplicationSourceIdentity;
use app\common\value\installation\ServerReleaseIdentity;
use PeanutAdmin\Modules\Identity\Contract\TenantModuleStateQueries;
use app\common\infrastructure\module\ModuleHostLayoutFactory;
use app\platform\exception\module\ModuleLifecycleException;
use app\platform\infrastructure\module\ModuleCatalogApplier;
use app\platform\infrastructure\module\ModuleUninstallPlanCodec;
use app\platform\infrastructure\module\ModuleLockResolver;
use app\platform\infrastructure\module\ModuleArtifactWriter;
use app\platform\policy\module\ModuleLifecyclePolicy;
use app\platform\value\module\ModuleDescriptor;
use app\platform\value\module\ModuleFrontendLayout;
use app\common\persistence\AdvisoryLockExecution;
use app\common\persistence\AdvisoryLockUnavailable;
use PeanutAdmin\Kernel\Module\ManifestLoader;
use PeanutAdmin\Kernel\Module\ModuleKey;
use PeanutAdmin\Kernel\Module\ModuleException;
use think\facade\Db;

/** Deployment-scoped retire/purge state machine; tenant licenses remain a separate boundary. */
final class ModuleRuntimeGovernanceService
{
    /** @param array<string,mixed> $moduleConfig @param null|callable(string):void $faultInjector */
    public function __construct(
        private readonly string $serverRoot,
        private readonly array $moduleConfig,
        private readonly ModuleCatalogApplier $catalogs,
        private readonly TenantModuleStateQueries $tenantStates,
        private readonly mixed $faultInjector = null,
    ) {}

    /** @return array<string,mixed> */
    public function preview(string $moduleOrPackageKey, bool $purge): array
    {
        $this->assertMutableSourceLock();
        $plan = $this->savedRemovalPlan($moduleOrPackageKey, $purge);
        if ($plan === null) {
            $scope = $this->scope($moduleOrPackageKey);
            $plan = $scope === null ? $this->cleanPlan($moduleOrPackageKey, $purge) : $this->buildPlan($scope, $purge);
        }
        return [
            'operation' => 'preview',
            'plan_digest' => $this->codec()->digest($plan),
            'confirm_plan' => $plan,
            'affected_modules' => $plan['affected_modules'],
            'preserved' => $plan['preserved'],
            'removed' => $plan['removed'],
            'blockers' => $plan['blockers'],
        ];
    }

    /** @param array<string,mixed> $confirmPlan @return array<string,mixed> */
    public function uninstall(
        string $moduleOrPackageKey,
        bool $purge,
        array $confirmPlan,
        string $confirmPlanDigest,
    ): array {
        $this->assertMutableSourceLock();
        $codec = $this->codec();
        if (!hash_equals($codec->digest($confirmPlan), strtolower(trim($confirmPlanDigest)))) {
            throw new ModuleLifecycleException('MODULE_UNINSTALL_PLAN_CHANGED', 'Confirmed Module uninstall plan digest differs.');
        }
        $operation = $purge ? 'purge' : 'retire';
        if (($confirmPlan['schema_version'] ?? null) !== 1 || ($confirmPlan['operation'] ?? null) !== $operation
            || !is_string($confirmPlan['package_key'] ?? null)
            || !is_array($confirmPlan['affected_modules'] ?? null)
            || !is_array($confirmPlan['removed'] ?? null)
            || !is_array($confirmPlan['preserved'] ?? null)
            || !is_array($confirmPlan['blockers'] ?? null)) {
            throw new ModuleLifecycleException('MODULE_UNINSTALL_PLAN_CHANGED', 'Confirmed Module uninstall plan is invalid.');
        }
        $packageKey = (string) $confirmPlan['package_key'];
        if (!$this->inputBelongsToPackage($moduleOrPackageKey, $packageKey)) {
            throw new ModuleLifecycleException('MODULE_UNINSTALL_PLAN_CHANGED', 'Confirmed package key differs.');
        }
        if ($confirmPlan['blockers'] !== []) {
            throw new ModuleLifecycleException('MODULE_UNINSTALL_BLOCKED', 'Module uninstall plan contains blockers.');
        }

        $lockName = 'pa:module-runtime:' . substr(hash('sha256', $packageKey), 0, 40);
        try {
            return (new AdvisoryLockExecution())->run($lockName, 0, function () use (
                $packageKey,
                $confirmPlan,
                $purge,
                $moduleOrPackageKey,
                $codec,
                $confirmPlanDigest,
            ): array {
                $state = $this->installationState($packageKey);
                if ($this->isCleanState($state, $confirmPlan, $purge)) {
                    if ($purge) {
                        foreach ($this->quarantineDirectories($packageKey) as $directory) { $this->removeTree($directory); }
                    }
                    return ['operation' => 'unchanged', 'package_key' => $packageKey, 'removed' => []];
                }
                $marker = $purge ? 'MODULE_PURGE_IN_PROGRESS' : 'MODULE_RETIRE_IN_PROGRESS';
                $resuming = is_array($state) && $state['status'] === 'maintenance' && $state['last_error_code'] === $marker;
                if (!$resuming) {
                    $scope = $this->scope($moduleOrPackageKey);
                    if ($scope === null) {
                        throw new ModuleLifecycleException('PLUGIN_NOT_INSTALLED', 'Module package is not installed.');
                    }
                    $current = $this->buildPlan($scope, $purge);
                    if (!hash_equals($codec->encode($confirmPlan), $codec->encode($current))) {
                        throw new ModuleLifecycleException('MODULE_UNINSTALL_PLAN_CHANGED', 'Module uninstall plan changed before execution.');
                    }
                } else {
                    $this->assertResumeScope($confirmPlan, $packageKey);
                }

                $moduleKeys = $this->confirmedModuleKeys($confirmPlan);
                $ownedTablesByModule = $this->confirmedOwnedTablesByModule($confirmPlan);
                $ownedTables = $this->confirmedOwnedTables($ownedTablesByModule);
                $this->saveRemovalPlan($packageKey, $confirmPlan);
                // The recovery marker commits with the preconditions and Module maintenance states.
                if (!$resuming) {
                    $this->markMaintenance($packageKey, $moduleKeys, $marker, $scope['manifests'] ?? []);
                }
                $this->inject('after-marker');

                $catalog = $this->catalogs;
                $currentCatalog = $catalog->plan($moduleKeys, $purge);
                if ($currentCatalog['blockers'] !== []) {
                    throw new ModuleLifecycleException('MODULE_UNINSTALL_BLOCKED', 'New catalog references block Module uninstall.');
                }
                $this->assertCurrentRemovalSubset($currentCatalog['removed'], $confirmPlan['removed']);
                $purge ? $catalog->purge($moduleKeys) : $catalog->retire($moduleKeys);
                $this->inject('after-catalog');

                if ($purge) {
                    $this->dropOwnedTables($ownedTables, $ownedTablesByModule);
                    $this->inject('after-first-drop');
                    $this->assertOwnedTablesAbsent($ownedTables);
                    Db::transaction(function () use ($moduleKeys): void {
                        if ($this->catalogs->plan($moduleKeys, true)['removed'] !== []) {
                            throw new ModuleLifecycleException('MODULE_PURGE_INCOMPLETE', 'Module catalog remains after purge.');
                        }
                    });
                    $this->inject('after-database-clean');
                }

                $quarantine = $this->finalizeFilesystem($packageKey, $confirmPlan, $confirmPlanDigest);
                $roots = [];
                foreach ($moduleKeys as $moduleKey) {
                    $relative = rtrim(ModuleHostLayoutFactory::pathLayout()->backendRelativePath(ModuleKey::fromString($moduleKey)), '/');
                    $roots[$moduleKey] = $quarantine . '/' . $relative;
                }
                try {
                    $dependencies = (new Dependencies($this->serverRoot))->remove($roots);
                } catch (\Throwable $exception) {
                    try {
                        $this->publishCurrentRegistry();
                    } catch (\Throwable $compilationFailure) {
                        \think\facade\Log::error('Module registry compilation failed during dependency-removal recovery: ' . $compilationFailure::class);
                    }
                    throw $exception;
                }
                $this->publishCurrentRegistry();
                $this->finalizeInstallation($packageKey, $moduleKeys, $purge);
                if ($purge) {
                    foreach ($this->quarantineDirectories($packageKey) as $directory) { $this->removeTree($directory); }
                }
                return [
                    'operation' => $purge ? 'purged' : 'retired',
                    'package_key' => $packageKey,
                    'affected_modules' => $confirmPlan['affected_modules'],
                    'removed' => $confirmPlan['removed'],
                    'preserved' => $confirmPlan['preserved'],
                    'dependencies' => $dependencies,
                ];
            });
        } catch (AdvisoryLockUnavailable) {
            throw new ModuleLifecycleException('MODULE_LIFECYCLE_BUSY', 'Module lifecycle is busy.');
        }
    }

    /** @param array{package_key:string,package_manifest_digest:string,descriptor:ModuleDescriptor,affected_modules:list<array<string,mixed>>} $scope @return array<string,mixed> */
    private function buildPlan(array $scope, bool $purge): array
    {
        $moduleKeys = array_column($scope['affected_modules'], 'module_key');
        $catalog = $this->catalogs->plan($moduleKeys, $purge);
        $removed = $catalog['removed'];
        $preserved = $catalog['preserved'];
        $blockers = [...$catalog['blockers'], ...$this->lifecycleBlockers($scope, $purge)];
        if ($purge && ($scope['source_matches_installed'] ?? true) === false) {
            $blockers[] = ['code' => 'MODULE_PURGE_SOURCE_MISMATCH', 'kind' => 'data_integrity', 'identifiers' => $moduleKeys];
        }
        $ownedTables = [];
        foreach ($scope['affected_modules'] as $module) {
            foreach ($module['owned_tables'] as $table) {
                $ownedTables[] = $table;
            }
        }
        sort($ownedTables, SORT_STRING);
        if ($purge) {
            $this->appendPlanEntry($removed, 'database', 'owned_tables', 'drop', $this->existingOwnedTables($ownedTables), true);
        } else {
            $this->appendPlanEntry($preserved, 'database', 'owned_tables', 'preserve', $this->existingOwnedTables($ownedTables), true);
        }
        $migrationTable = Db::name((string) config('database.migration_table', 'migrations'));
        $this->appendPlanEntry($preserved, 'database', $migrationTable->getTable(), 'preserve',
            array_map('strval', $migrationTable->order('version')->column('version')), true);
        $this->sortPlanEntries($removed);
        $this->sortPlanEntries($preserved);
        usort($blockers, static fn(array $a, array $b): int => strcmp((string) $a['code'], (string) $b['code']));
        return [
            'schema_version' => 1,
            'package_key' => $scope['package_key'],
            'package_manifest_digest' => $scope['package_manifest_digest'],
            'operation' => $purge ? 'purge' : 'retire',
            'affected_modules' => $scope['affected_modules'],
            'preserved' => $preserved,
            'removed' => $removed,
            'blockers' => $blockers,
            ...(isset($scope['installed_identity']) ? ['installed_identity' => $scope['installed_identity'],
                'source_identity' => $scope['source_identity']] : []),
        ];
    }

    /** @return array{package_key:string,package_manifest_digest:string,affected_modules:list<array<string,mixed>>}|null */
    private function scope(string $moduleOrPackageKey): ?array
    {
        return $this->lockedScope($moduleOrPackageKey)
            ?? $this->installedOrphanScope($moduleOrPackageKey)
            ?? $this->quarantinedScope($moduleOrPackageKey);
    }

    /** Installed lock-excluded source can be disabled and retired without activating its old dependencies. */
    public function installedOrphanScope(string $moduleOrPackageKey): ?array
    {
        $installation = Db::name('module_installation')->where('module_key', $moduleOrPackageKey)
            ->whereIn('status', ['active', 'maintenance'])
            ->field('module_key,installed_version,artifact_sha256')->select()->toArray();
        if ($installation === []) { return null; }
        if (count($installation) !== 1) {
            throw new ModuleLifecycleException('PLUGIN_IDENTITY_INVALID', 'Installed package identity is ambiguous.');
        }
        $installed = $installation[0];
        $packageKey = (string) $installed['module_key'];
        $projectRoot = dirname($this->serverRoot);
        $pluginPath = $projectRoot . '/' . rtrim(ModuleHostLayoutFactory::pathLayout()->backendRelativePath(ModuleKey::fromString($packageKey)), '/') . '/module.json';
        $plugin = $this->sourceMetadata($projectRoot, $packageKey);
        if ($plugin === null) { return null; }
        if (($plugin['key'] ?? null) !== $packageKey) {
            throw new ModuleLifecycleException('PLUGIN_ARTIFACT_MISMATCH', 'Installed package key differs from its source.');
        }
        $specs = array_map(static fn(array $module): string => $module['key'] . '=' . $module['root'], $plugin['modules']);
        if ((new ModuleArtifactWriter($this->serverRoot))->build($packageKey, $plugin['version'], $specs) !== $plugin) {
            throw new ModuleLifecycleException('PLUGIN_ARTIFACT_MISMATCH', 'Installed package source is not canonical.');
        }
        $owned = Db::name('module_installation')->where('module_key', $packageKey)->column('manifest_digest', 'module_key');
        $affected = [];
        $manifests = [];
        $sourceMatches = $plugin['version'] === $installed['installed_version']
            && $plugin['source']['sha256'] === $installed['artifact_sha256'];
        foreach ($plugin['modules'] as $module) {
            $key = $module['key'];
            $manifest = (new ManifestLoader())->load($projectRoot . '/' . $module['root']);
            if (!isset($owned[$key])) {
                throw new ModuleLifecycleException('PLUGIN_ARTIFACT_MISMATCH', 'Installed Module ownership differs from its source.');
            }
            $installedDigest = (string) $owned[$key];
            $sourceMatches = $sourceMatches && hash_equals($installedDigest, $manifest->digest);
            unset($owned[$key]);
            $tables = $manifest->data['database']['owned_tables'] ?? [];
            sort($tables, SORT_STRING);
            $manifests[$key] = $manifest;
            $affected[] = ['module_key' => $key, 'version' => $manifest->data['version'],
                'manifest_digest' => $installedDigest, 'source_manifest_digest' => $manifest->digest,
                'source_paths' => $this->moduleSourcePaths($key, $manifest->data),
                'owned_tables' => $tables, 'lifecycle_protected' => ModuleLifecyclePolicy::isProtected($manifest)];
        }
        if ($owned !== []) {
            throw new ModuleLifecycleException('PLUGIN_ARTIFACT_MISMATCH', 'Installed Module ownership exceeds its source.');
        }
        usort($affected, static fn(array $a, array $b): int => $a['module_key'] <=> $b['module_key']);
        return ['package_key' => $packageKey, 'package_manifest_digest' => hash_file('sha256', $pluginPath),
            'affected_modules' => $affected, 'manifests' => $manifests, 'source_matches_installed' => $sourceMatches,
            'installed_identity' => ['version' => $installed['installed_version'], 'artifact_sha256' => $installed['artifact_sha256']],
            'source_identity' => ['version' => $plugin['version'], 'artifact_sha256' => $plugin['source']['sha256']]];
    }

    /** @return array{package_key:string,package_manifest_digest:string,descriptor:ModuleDescriptor,affected_modules:list<array<string,mixed>>}|null */
    private function lockedScope(string $moduleOrPackageKey): ?array
    {
        $resolver = ModuleLockResolver::forRuntime(
            $this->serverRoot,
            (string) ($this->moduleConfig['module_lock'] ?? 'modules.lock'),
        );
        $descriptor = null;
        foreach ($resolver->all() as $plugin) {
            if ($plugin->key === $moduleOrPackageKey || isset($plugin->moduleRoots[$moduleOrPackageKey])) {
                $descriptor = $plugin;
                break;
            }
        }
        if (!$descriptor instanceof ModuleDescriptor) {
            return null;
        }
        $affected = [];
        $seenTables = [];
        foreach ($descriptor->moduleRoots as $moduleKey => $root) {
            $manifest = (new ManifestLoader())->load($root);
            $owned = array_values((array) ($manifest->data['database']['owned_tables'] ?? []));
            sort($owned, SORT_STRING);
            foreach ($owned as $table) {
                if (!is_string($table) || preg_match('/^pa_[a-z0-9_]+$/D', $table) !== 1 || isset($seenTables[$table])) {
                    throw new ModuleLifecycleException('MODULE_TABLE_OWNERSHIP_INVALID', 'Module owned table declaration is invalid.');
                }
                $seenTables[$table] = true;
            }
            $affected[] = [
                'module_key' => $moduleKey,
                'version' => (string) $manifest->data['version'],
                'manifest_digest' => $manifest->digest,
                'source_paths' => $this->moduleSourcePaths($moduleKey, $manifest->data),
                'owned_tables' => $owned,
                'lifecycle_protected' => ModuleLifecyclePolicy::isProtected($manifest),
            ];
        }
        usort($affected, static fn(array $a, array $b): int => strcmp($a['module_key'], $b['module_key']));
        return ['package_key' => $descriptor->key, 'package_manifest_digest' => $descriptor->manifestDigest, 'descriptor' => $descriptor, 'affected_modules' => $affected];
    }

    /** @return array{package_key:string,package_manifest_digest:string,affected_modules:list<array<string,mixed>>}|null */
    private function quarantinedScope(string $moduleOrPackageKey): ?array
    {
        $installation = Db::name('module_installation')->where('module_key', $moduleOrPackageKey)
            ->where('status', 'uninstalled')->whereNull('last_error_code')
            ->field('module_key,installed_version,artifact_sha256')->select()->toArray();
        if ($installation === []) {
            return null;
        }
        if (count($installation) !== 1) {
            throw new ModuleLifecycleException('MODULE_QUARANTINE_CONFLICT', 'Retired Module package identity is ambiguous.');
        }

        $packageKey = (string) $installation[0]['module_key'];
        $directories = $this->quarantineDirectories($packageKey);
        $quarantines = array_values(array_filter($directories, function (string $directory) use ($installation, $packageKey): bool {
            $plugin = $this->sourceMetadata($directory, $packageKey);
            if ($plugin === null) { return false; }
            return ($plugin['key'] ?? null) === $packageKey && ($plugin['version'] ?? null) === $installation[0]['installed_version']
                && ($plugin['source']['sha256'] ?? null) === $installation[0]['artifact_sha256'];
        }));
        if ($quarantines === []) {
            foreach ($directories as $directory) {
                if ($this->sourceMetadata($directory, $packageKey) !== null) {
                    throw new ModuleLifecycleException('MODULE_QUARANTINE_INVALID', 'Retired Module package identity changed.');
                }
            }
            return null;
        }
        $quarantine = $quarantines[0];
        $pluginManifestPath = $quarantine . '/' . rtrim(ModuleHostLayoutFactory::pathLayout()->backendRelativePath(ModuleKey::fromString($packageKey)), '/') . '/module.json';
        try {
            $plugin = $this->sourceMetadata($quarantine, $packageKey);
        } catch (\JsonException) {
            throw new ModuleLifecycleException('MODULE_QUARANTINE_INVALID', 'Retired Module package manifest is invalid.');
        }
        if (!is_array($plugin) || ($plugin['key'] ?? null) !== $packageKey
            || ($plugin['version'] ?? null) !== $installation[0]['installed_version']
            || ($plugin['source']['sha256'] ?? null) !== $installation[0]['artifact_sha256']
            || !is_array($plugin['modules'] ?? null)) {
            throw new ModuleLifecycleException('MODULE_QUARANTINE_INVALID', 'Retired Module package identity changed.');
        }

        $ownedManifests = Db::name('module_installation')->where('module_key', $packageKey)
            ->order('module_key')->column('manifest_digest', 'module_key');
        $affected = [];
        $seenTables = [];
        $manifests = [];
        $layout = ModuleHostLayoutFactory::pathLayout();
        foreach ($plugin['modules'] as $module) {
            if (!is_array($module) || !is_string($module['key'] ?? null) || !is_string($module['root'] ?? null)) {
                throw new ModuleLifecycleException('MODULE_QUARANTINE_INVALID', 'Retired Module package scope is invalid.');
            }
            $key = ModuleKey::fromString($module['key']);
            $relativeRoot = rtrim($layout->backendRelativePath($key), '/');
            if ($module['root'] !== $relativeRoot) {
                throw new ModuleLifecycleException('MODULE_QUARANTINE_INVALID', 'Retired Module path is not derived from its key.');
            }
            $root = realpath($quarantine . '/' . $relativeRoot);
            if ($root === false || !str_starts_with($root, $quarantine . DIRECTORY_SEPARATOR)) {
                throw new ModuleLifecycleException('MODULE_QUARANTINE_INVALID', 'Retired Module source is unavailable.');
            }
            $manifest = (new ManifestLoader())->load($root);
            if (!isset($ownedManifests[$key->value()])
                || !hash_equals((string) $ownedManifests[$key->value()], $manifest->digest)) {
                throw new ModuleLifecycleException('MODULE_QUARANTINE_INVALID', 'Retired Module manifest digest changed.');
            }
            $manifests[$key->value()] = $manifest;
            $owned = array_values((array) ($manifest->data['database']['owned_tables'] ?? []));
            sort($owned, SORT_STRING);
            foreach ($owned as $table) {
                if (!is_string($table) || preg_match('/^pa_[a-z0-9_]+$/D', $table) !== 1 || isset($seenTables[$table])) {
                    throw new ModuleLifecycleException('MODULE_TABLE_OWNERSHIP_INVALID', 'Module owned table declaration is invalid.');
                }
                $seenTables[$table] = true;
            }
            $affected[] = [
                'module_key' => $key->value(),
                'version' => (string) $manifest->data['version'],
                'manifest_digest' => $manifest->digest,
                'source_paths' => $this->moduleSourcePaths($key->value(), $manifest->data),
                'owned_tables' => $owned,
                'lifecycle_protected' => ModuleLifecyclePolicy::isProtected($manifest),
            ];
            unset($ownedManifests[$key->value()]);
        }
        if ($ownedManifests !== []) {
            throw new ModuleLifecycleException('MODULE_QUARANTINE_INVALID', 'Retired Module ownership scope changed.');
        }
        usort($affected, static fn(array $a, array $b): int => strcmp($a['module_key'], $b['module_key']));
        $manifestDigest = hash_file('sha256', $pluginManifestPath);
        if (!is_string($manifestDigest)) {
            throw new ModuleLifecycleException('MODULE_QUARANTINE_INVALID', 'Retired package manifest cannot be digested.');
        }
        return ['package_key' => $packageKey, 'package_manifest_digest' => $manifestDigest, 'affected_modules' => $affected,
            'manifests' => $manifests, 'quarantine_verified' => true];
    }

    /** @param array<string,mixed> $scope @return list<array<string,mixed>> */
    private function lifecycleBlockers(array $scope, bool $purge): array
    {
        $moduleKeys = array_column($scope['affected_modules'], 'module_key');
        $blockers = [];
        $protected = [];
        foreach ($scope['affected_modules'] as $module) {
            if (($module['lifecycle_protected'] ?? false) === true) {
                $protected[] = (string) $module['module_key'];
            }
        }
        sort($protected, SORT_STRING);
        if ($protected !== []) {
            $blockers[] = [
                'code' => 'MODULE_LIFECYCLE_PROTECTED',
                'kind' => 'product_policy',
                'identifiers' => $protected,
            ];
        }
        $enabled = $this->tenantStates->enabledModuleReferences($moduleKeys);
        if ($enabled !== []) {
            $blockers[] = ['code' => 'PLUGIN_TENANT_MODULE_ACTIVE', 'kind' => 'tenant_enablement', 'identifiers' => $enabled];
        }

        $states = $this->tenantStates->installationStates($moduleKeys);
        $notDisabled = array_values(array_filter($moduleKeys, static fn(string $key): bool =>
            ($states[$key]['status'] ?? null) !== 'maintenance'
            && !($purge && ($scope['quarantine_verified'] ?? false) === true
                && ($states[$key]['status'] ?? null) === 'uninstalled'
                && ($states[$key]['last_error_code'] ?? null) === null)));
        if ($notDisabled !== []) {
            $blockers[] = ['code' => 'MODULE_DISABLE_REQUIRED', 'kind' => 'lifecycle', 'identifiers' => $notDisabled];
        }
        try {
            ModuleLifecyclePolicy::assertNoOutstandingWorkflow($moduleKeys);
        } catch (ModuleLifecycleException $exception) {
            $blockers[] = ['code' => $exception->errorCode, 'kind' => 'lifecycle', 'identifiers' => $moduleKeys];
        }

        $dependents = ModuleLifecyclePolicy::installedBusinessDependents(
            ModuleLockResolver::forRuntime(
                $this->serverRoot,
                (string) ($this->moduleConfig['module_lock'] ?? 'modules.lock'),
            ),
            $moduleKeys,
            $this->tenantStates,
            $scope['manifests'] ?? [],
        );
        if ($dependents !== []) {
            $blockers[] = ['code' => 'MODULE_DEPENDENT_INSTALLED', 'kind' => 'business_dependency', 'identifiers' => array_values(array_unique($dependents))];
        }

        if ($purge) {
            $owned = [];
            foreach ($scope['affected_modules'] as $module) {
                $owned = [...$owned, ...$module['owned_tables']];
            }
            $external = $this->externalForeignKeys($owned);
            if ($external !== []) {
                $blockers[] = ['code' => 'MODULE_OWNED_TABLE_EXTERNAL_REFERENCE', 'kind' => 'data_integrity', 'identifiers' => $external];
            }
            if ($this->dropOrder($owned) === null) {
                $blockers[] = ['code' => 'MODULE_OWNED_TABLE_FK_CYCLE', 'kind' => 'data_integrity', 'identifiers' => $owned];
            }
        }
        return $blockers;
    }

    /** @param list<string> $tables @return list<string> */
    private function externalForeignKeys(array $tables): array
    {
        if ($tables === []) {
            return [];
        }
        $rows = Db::table('information_schema.KEY_COLUMN_USAGE')
            ->where('REFERENCED_TABLE_SCHEMA', Db::raw('DATABASE()'))
            ->whereIn('REFERENCED_TABLE_NAME', $tables)->whereNotIn('TABLE_NAME', $tables)
            ->where('CONSTRAINT_NAME', '<>', 'PRIMARY')
            ->field('TABLE_NAME,CONSTRAINT_NAME,REFERENCED_TABLE_NAME')
            ->order('TABLE_NAME')->order('CONSTRAINT_NAME')->select()->toArray();
        return array_map(static fn(array $row): string => $row['TABLE_NAME'] . '.' . $row['CONSTRAINT_NAME'] . '->' . $row['REFERENCED_TABLE_NAME'], $rows);
    }

    /** @param list<string> $tables @return list<string>|null */
    private function dropOrder(array $tables): ?array
    {
        $remaining = array_fill_keys($tables, true);
        if ($tables === []) {
            return [];
        }
        $edgeRows = Db::table('information_schema.KEY_COLUMN_USAGE')
            ->where('TABLE_SCHEMA', Db::raw('DATABASE()'))->where('REFERENCED_TABLE_SCHEMA', Db::raw('DATABASE()'))
            ->whereIn('TABLE_NAME', $tables)->whereIn('REFERENCED_TABLE_NAME', $tables)
            ->field('TABLE_NAME,REFERENCED_TABLE_NAME')->select()->toArray();
        $edges = array_map(static fn(array $row): array => [$row['TABLE_NAME'], $row['REFERENCED_TABLE_NAME']], $edgeRows);
        $ordered = [];
        while ($remaining !== []) {
            $parents = [];
            foreach ($edges as [$child, $parent]) {
                if (isset($remaining[$child], $remaining[$parent])) {
                    $parents[$parent] = true;
                }
            }
            $leaf = null;
            foreach (array_keys($remaining) as $table) {
                if (!isset($parents[$table])) {
                    $leaf = $table;
                    break;
                }
            }
            if ($leaf === null) {
                return null;
            }
            $ordered[] = $leaf;
            unset($remaining[$leaf]);
        }
        return $ordered;
    }

    /** @param list<string> $tables @param array<string,list<string>> $tablesByModule */
    private function dropOwnedTables(array $tables, array $tablesByModule): void
    {
        $order = $this->dropOrder($tables);
        if ($order === null) {
            throw new ModuleLifecycleException('MODULE_OWNED_TABLE_FK_CYCLE', 'Owned table foreign keys contain a cycle.');
        }
        $first = true;
        $moduleBoundaryInjected = false;
        $remainingByModule = [];
        $moduleByTable = [];
        foreach ($tablesByModule as $moduleKey => $moduleTables) {
            $remainingByModule[$moduleKey] = array_fill_keys($moduleTables, true);
            foreach ($moduleTables as $moduleTable) {
                $moduleByTable[$moduleTable] = $moduleKey;
            }
        }
        foreach ($order as $table) {
            if (preg_match('/^pa_[a-z0-9_]+$/D', $table) !== 1) {
                throw new ModuleLifecycleException('MODULE_TABLE_OWNERSHIP_INVALID', 'Owned table name is invalid.');
            }
            // Framework query builders do not expose DDL; validated owned table names remain the driver-level boundary.
            Db::execute("DROP TABLE IF EXISTS `{$table}`");
            if ($first) {
                $this->inject('after-first-drop-statement');
                $first = false;
            }
            $moduleKey = $moduleByTable[$table] ?? null;
            if (is_string($moduleKey)) {
                unset($remainingByModule[$moduleKey][$table]);
            }
            if (is_string($moduleKey) && $remainingByModule[$moduleKey] === []) {
                if (!$moduleBoundaryInjected && count($remainingByModule) > 1) {
                    $moduleBoundaryInjected = true;
                    $this->inject('after-first-module-drop');
                }
                unset($remainingByModule[$moduleKey]);
            }
        }
    }

    /** @param list<string> $tables */
    private function assertOwnedTablesAbsent(array $tables): void
    {
        if ($this->existingOwnedTables($tables) !== []) {
            throw new ModuleLifecycleException('MODULE_PURGE_INCOMPLETE', 'Owned tables remain after purge.');
        }
    }

    /** @param list<string> $tables @return list<string> */
    private function existingOwnedTables(array $tables): array
    {
        if ($tables === []) {
            return [];
        }
        return array_map('strval', Db::table('information_schema.TABLES')
            ->where('TABLE_SCHEMA', Db::raw('DATABASE()'))->whereIn('TABLE_NAME', $tables)
            ->order('TABLE_NAME')->column('TABLE_NAME'));
    }

    /** @param list<string> $moduleKeys */
    private function markMaintenance(string $packageKey, array $moduleKeys, string $marker, array $verifiedTargets): void
    {
        $now = gmdate('Y-m-d H:i:s.v');
        Db::transaction(function () use ($packageKey, $moduleKeys, $marker, $now, $verifiedTargets): void {
            $this->tenantStates->lockLifecycleRows($moduleKeys, [$packageKey]);
            $dependents = ModuleLifecyclePolicy::installedBusinessDependents(
                ModuleLockResolver::forRuntime($this->serverRoot, (string) ($this->moduleConfig['module_lock'] ?? '../modules.lock')),
                $moduleKeys,
                $this->tenantStates,
                $verifiedTargets,
            );
            if ($dependents !== []) {
                throw new ModuleLifecycleException('MODULE_DEPENDENT_INSTALLED', 'Installed Module dependencies must be removed before uninstall.');
            }
            ModuleLifecyclePolicy::assertNoOutstandingWorkflow($moduleKeys);
            if ($this->tenantStates->enabledReferencesForUpdate($moduleKeys) !== []) {
                throw new ModuleLifecycleException('PLUGIN_TENANT_MODULE_ACTIVE', 'Tenant license plan references block Module uninstall.');
            }
            $this->catalogs->markPackageModulesForRecovery($moduleKeys, $marker, $now);
        });
    }

    /** @param list<string> $moduleKeys */
    private function finalizeInstallation(string $packageKey, array $moduleKeys, bool $purge): void
    {
        $now = gmdate('Y-m-d H:i:s.v');
        Db::transaction(function () use ($packageKey, $moduleKeys, $purge, $now): void {
            $this->tenantStates->lockLifecycleRows($moduleKeys, [$packageKey]);
            $this->catalogs->finalizePackageRemoval($moduleKeys, $purge, $now);
        });
    }

    /** @param array<string,mixed> $plan */
    private function finalizeFilesystem(string $packageKey, array $plan, string $digest): string
    {
        $this->assertMutableSourceLock();
        $projectRoot = realpath(dirname($this->serverRoot)) ?: dirname($this->serverRoot);
        $paths = [];
        foreach ($plan['affected_modules'] as $module) {
            foreach ($module['source_paths'] as $relative) {
                $paths[] = $relative;
            }
        }
        $paths = array_values(array_unique($paths));
        foreach ($paths as $relative) {
            $source = $projectRoot . '/' . $relative;
            if (!file_exists($source) && !is_link($source)) {
                continue;
            }
            $resolved = realpath($source);
            if (is_link($source) || $resolved !== $source) {
                throw new ModuleLifecycleException('MODULE_QUARANTINE_INVALID', 'Module source path escapes its application.');
            }
        }
        $lockPath = $projectRoot . '/modules.lock';
        if (is_file($lockPath)) {
            $lock = json_decode((string) file_get_contents($lockPath), true, 128, JSON_THROW_ON_ERROR);
            if (!is_array($lock) || !is_array($lock['modules'] ?? null)) {
                throw new ModuleLifecycleException('PLUGIN_LOCK_INVALID', 'Plugin lock is invalid.');
            }
            $lock['modules'] = array_values(array_filter($lock['modules'], static fn(mixed $entry): bool => !is_array($entry) || ($entry['key'] ?? null) !== $packageKey));
            $contents = json_encode($lock, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
            $temporary = $lockPath . '.tmp-' . bin2hex(random_bytes(8));
            $stream = fopen($temporary, 'xb');
            if (!is_resource($stream) || fwrite($stream, $contents) !== strlen($contents) || !fflush($stream)) {
                throw new ModuleLifecycleException('PLUGIN_LOCK_WRITE_FAILED', 'Plugin lock cannot be written.');
            }
            if (function_exists('fsync')) {
                fsync($stream);
            }
            fclose($stream);
            if (!rename($temporary, $lockPath)) {
                throw new ModuleLifecycleException('PLUGIN_LOCK_WRITE_FAILED', 'Plugin lock cannot be promoted.');
            }
        }
        $this->inject('after-lock');

        $quarantine = $projectRoot . '/.local/module-quarantine/' . $packageKey . '-' . strtolower($digest);
        $moved = 0;
        foreach (array_values(array_unique($paths)) as $relative) {
            $source = $projectRoot . '/' . $relative;
            $target = $quarantine . '/' . $relative;
            if (file_exists($source)) {
                if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0700, true) && !is_dir(dirname($target))) {
                    throw new ModuleLifecycleException('MODULE_QUARANTINE_FAILED', 'Module quarantine cannot be created.');
                }
                if (!rename($source, $target)) {
                    throw new ModuleLifecycleException('MODULE_QUARANTINE_FAILED', 'Module path cannot enter quarantine.');
                }
                $moved++;
                if ($moved === 1) {
                    $this->inject('after-first-quarantine');
                }
            }
        }
        foreach ([$quarantine, ...$this->quarantineDirectories($packageKey)] as $directory) {
            $manifest = $directory . '/' . rtrim(ModuleHostLayoutFactory::pathLayout()->backendRelativePath(ModuleKey::fromString($packageKey)), '/') . '/module.json';
            if (is_file($manifest)) {
                $document = (new ManifestLoader())->load(dirname($manifest));
                // Existing confirmed removal plans already carry their Module identity.
                // It survives the retired envelope digest without rewriting the immutable plan.
                foreach ($plan['affected_modules'] as $module) {
                    if ($module['module_key'] === $packageKey && $module['version'] === $document->data['version']
                        && hash_equals((string) ($module['source_manifest_digest'] ?? $module['manifest_digest']), $document->digest)) {
                        return $directory;
                    }
                }
            }
        }
        throw new ModuleLifecycleException('MODULE_QUARANTINE_FAILED', 'The confirmed package source is unavailable in quarantine.');
    }

    /** Preserve the already confirmed input in the existing quarantine recovery area. */
    private function sourceMetadata(string $projectRoot, string $key): ?array
    {
        $relative = rtrim(ModuleHostLayoutFactory::pathLayout()->backendRelativePath(ModuleKey::fromString($key)), '/');
        $path = $projectRoot . '/' . $relative . '/module.json';
        if (!is_file($path)) {
            return null;
        }
        $manifest = (new ManifestLoader())->load(dirname($path));
        return (new ModuleArtifactWriter($projectRoot . '/server', false))->build(
            $key, $manifest->data['version'], [$key . '=' . $relative],
        );
    }

    private function saveRemovalPlan(string $packageKey, array $plan): void
    {
        $root = dirname($this->serverRoot) . '/.local/module-quarantine';
        if (!is_dir($root) && !mkdir($root, 0700, true) && !is_dir($root)) {
            throw new ModuleLifecycleException('MODULE_QUARANTINE_FAILED', 'Module quarantine cannot be created.');
        }
        $path = $root . '/' . $packageKey . '.plan.json';
        $state = $this->installationState($packageKey);
        if (is_file($path) && is_array($state) && $state['status'] === 'maintenance'
            && in_array($state['last_error_code'], ['MODULE_RETIRE_IN_PROGRESS', 'MODULE_PURGE_IN_PROGRESS'], true)) {
            $saved = json_decode((string) file_get_contents($path), true, 128, JSON_THROW_ON_ERROR);
            if ($this->codec()->encode($saved) !== $this->codec()->encode($plan)) {
                throw new ModuleLifecycleException('MODULE_UNINSTALL_PLAN_CHANGED', 'Complete the existing confirmed Module removal plan first.');
            }
        }
        $temporary = $path . '.tmp-' . bin2hex(random_bytes(6));
        $contents = $this->codec()->encode($plan) . "\n";
        if (file_put_contents($temporary, $contents, LOCK_EX) !== strlen($contents) || !rename($temporary, $path)) {
            @unlink($temporary);
            throw new ModuleLifecycleException('MODULE_QUARANTINE_FAILED', 'Confirmed Module removal plan cannot be saved.');
        }
    }

    private function publishCurrentRegistry(): void
    {
        $factory = new ModuleDefinitionRegistryFactory($this->serverRoot);
        try {
            $factory->fromCompiled($this->moduleConfig);
            return;
        } catch (ModuleException $exception) {
            if ($exception->errorCode !== 'MODULE_REGISTRY_NOT_COMPILED') { throw $exception; }
        }
        $resolver = new ModuleLockResolver($this->serverRoot, (string) $this->moduleConfig['module_lock']);
        $registry = $factory->fromModuleLock($resolver, $this->moduleConfig, false);
        $factory->publishCompiled($registry, $this->moduleConfig, $resolver->all());
    }

    private function savedRemovalPlan(string $moduleOrPackageKey, bool $purge): ?array
    {
        $packageKey = Db::name('module_installation')->where('module_key', $moduleOrPackageKey)->value('module_key') ?? $moduleOrPackageKey;
        $state = $this->installationState($packageKey);
        $marker = $purge ? 'MODULE_PURGE_IN_PROGRESS' : 'MODULE_RETIRE_IN_PROGRESS';
        if (!is_array($state) || $state['status'] !== 'maintenance' || $state['last_error_code'] !== $marker) { return null; }
        $plan = $this->readRemovalPlan($packageKey);
        if ($plan === null) { return null; }
        if (($plan['package_key'] ?? null) !== $packageKey || ($plan['operation'] ?? null) !== ($purge ? 'purge' : 'retire')) {
            throw new ModuleLifecycleException('MODULE_UNINSTALL_PLAN_CHANGED', 'Saved Module removal plan differs from its operation.');
        }
        $this->assertResumeScope($plan, $packageKey);
        return $plan;
    }

    /** @return array<string,mixed>|null */
    private function readRemovalPlan(string $packageKey): ?array
    {
        ModuleKey::fromString($packageKey);
        $path = dirname($this->serverRoot) . '/.local/module-quarantine/' . $packageKey . '.plan.json';
        return is_file($path) ? json_decode((string) file_get_contents($path), true, 128, JSON_THROW_ON_ERROR) : null;
    }

    private function assertMutableSourceLock(): void
    {
        $identityPath = $this->serverRoot . '/.peanut/release-identity.json';
        if (file_exists($identityPath) || is_link($identityPath)) {
            ServerReleaseIdentity::resolve($this->serverRoot);
            throw new ModuleLifecycleException(
                'PLUGIN_RELEASE_CHANGE_REQUIRED',
                'Server Plugin lock is part of the release identity; change Plugins through a new application release.',
            );
        }
        $manifestPath = dirname($this->serverRoot) . '/.peanut/application-manifest.json';
        $applicationSource = file_exists($manifestPath) || is_link($manifestPath);
        if ($applicationSource) {
            ApplicationSourceIdentity::load($this->serverRoot);
        }
        $lockPath = dirname($this->serverRoot) . '/modules.lock';
        if ((!$applicationSource && env('PEANUT_INSTALLATION_SOURCE_MODE', false) !== 'development')
            || ($this->moduleConfig['module_lock'] ?? null) !== '../modules.lock'
            || !is_file($lockPath) || is_link($lockPath)) {
            throw new ModuleLifecycleException(
                'SERVER_RELEASE_IDENTITY_INVALID',
                'A valid application source identity and project Plugin lock are required for source Plugin lifecycle operations.',
            );
        }
    }

    /** Freeze the selected manifest's actual backend and client contributions before source removal.
     * @param array<string,mixed> $manifest @return list<string>
     */
    private function moduleSourcePaths(string $moduleKey, array $manifest): array
    {
        $paths = [rtrim(ModuleHostLayoutFactory::pathLayout()->backendRelativePath(ModuleKey::fromString($moduleKey)), '/')];
        foreach (ModuleFrontendLayout::contributions((array) ($manifest['frontend'] ?? []), $moduleKey) as $contribution) {
            $paths[] = $contribution['root'];
        }
        sort($paths, SORT_STRING);
        return $paths;
    }

    /** @return list<string> */
    private function quarantineDirectories(string $packageKey): array
    {
        if (preg_match('/^[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*$/D', $packageKey) !== 1) {
            throw new ModuleLifecycleException('MODULE_QUARANTINE_INVALID', 'Module package key is invalid.');
        }
        $projectRoot = realpath(dirname($this->serverRoot)) ?: dirname($this->serverRoot);
        $root = $projectRoot . '/.local/module-quarantine';
        if (!is_dir($root)) {
            return [];
        }
        $resolvedRoot = realpath($root);
        if (!is_string($resolvedRoot)) {
            throw new ModuleLifecycleException('MODULE_QUARANTINE_INVALID', 'Module quarantine root is invalid.');
        }
        $directories = [];
        foreach (glob($root . '/' . $packageKey . '-*', GLOB_ONLYDIR) ?: [] as $candidate) {
            $resolved = realpath($candidate);
            if (is_link($candidate) || !is_string($resolved)
                || !str_starts_with($resolved, $resolvedRoot . DIRECTORY_SEPARATOR)) {
                throw new ModuleLifecycleException('MODULE_QUARANTINE_INVALID', 'Module quarantine path is invalid.');
            }
            $relative = rtrim(ModuleHostLayoutFactory::pathLayout()->backendRelativePath(ModuleKey::fromString($packageKey)), '/');
            $moduleRoot = $resolved . '/' . $relative;
            if (!file_exists($moduleRoot) && !is_link($moduleRoot)) { continue; }
            if (is_link($moduleRoot) || realpath($moduleRoot) !== $moduleRoot
                || !str_starts_with($moduleRoot, $resolved . DIRECTORY_SEPARATOR)) {
                throw new ModuleLifecycleException('MODULE_QUARANTINE_INVALID', 'Quarantined Module root is invalid.');
            }
            $manifestPath = $moduleRoot . '/module.json';
            if (!is_file($manifestPath)) { continue; }
            if (is_link($manifestPath)) {
                throw new ModuleLifecycleException('MODULE_QUARANTINE_INVALID', 'Quarantined Module declaration must not be linked.');
            }
            if ((new ManifestLoader())->load($moduleRoot)->data['key'] !== $packageKey) { continue; }
            $directories[] = $resolved;
        }
        sort($directories, SORT_STRING);
        return $directories;
    }

    /** @param array<string,mixed> $plan */
    private function assertResumeScope(array $plan, string $packageKey): void
    {
        $modules = $this->confirmedModuleKeys($plan);
        $actual = Db::name('module_installation')->where('module_key', $packageKey)
            ->order('module_key')->column('manifest_digest', 'module_key');
        foreach ($plan['affected_modules'] as $module) {
            if (!is_array($module) || !isset($actual[$module['module_key']])) {
                throw new ModuleLifecycleException('MODULE_UNINSTALL_PLAN_CHANGED', 'Module ownership changed during recovery.');
            }
            // Orphan plans separate source and installed digests; locked plans carry only the source digest.
            if (isset($module['source_manifest_digest'])
                && !hash_equals((string) $actual[$module['module_key']], (string) $module['manifest_digest'])) {
                throw new ModuleLifecycleException('MODULE_UNINSTALL_PLAN_CHANGED', 'Installed Module identity changed during recovery.');
            }
        }
        if (count($actual) !== count($modules)) {
            throw new ModuleLifecycleException('MODULE_UNINSTALL_PLAN_CHANGED', 'Package Module scope changed during recovery.');
        }
    }

    /** @param list<array<string,mixed>> $current @param list<array<string,mixed>> $confirmed */
    private function assertCurrentRemovalSubset(array $current, array $confirmed): void
    {
        $allowed = [];
        foreach ($confirmed as $entry) {
            if (is_array($entry)) {
                $allowed[(string) ($entry['scope'] ?? '') . "\0" . (string) ($entry['table'] ?? '') . "\0" . (string) ($entry['action'] ?? '')] = array_fill_keys((array) ($entry['identifiers'] ?? []), true);
            }
        }
        foreach ($current as $entry) {
            $key = $entry['scope'] . "\0" . $entry['table'] . "\0" . $entry['action'];
            if (!isset($allowed[$key])) {
                throw new ModuleLifecycleException('MODULE_UNINSTALL_PLAN_CHANGED', 'New removal scope appeared during recovery.');
            }
            foreach ($entry['identifiers'] as $identifier) {
                if (!isset($allowed[$key][$identifier])) {
                    throw new ModuleLifecycleException('MODULE_UNINSTALL_PLAN_CHANGED', 'New removal target appeared during recovery.');
                }
            }
        }
    }

    /** @param array<string,mixed>|null $state @param array<string,mixed> $plan */
    private function isCleanState(?array $state, array $plan, bool $purge): bool
    {
        if (!is_array($state) || $state['status'] !== 'uninstalled' || $state['last_error_code'] !== null) {
            return false;
        }
        if (!$purge) {
            return true;
        }
        $packageKey = $plan['package_key'];
        $saved = $this->readRemovalPlan($packageKey);
        if (($saved['operation'] ?? null) !== 'purge' || ($saved['package_key'] ?? null) !== $packageKey
            || $this->confirmedModuleKeys($saved) !== [$packageKey]) {
            return false;
        }
        $codec = $this->codec();
        $expected = $plan['affected_modules'] === [] ? $this->cleanPlan($packageKey, true) : $saved;
        if ($codec->encode($plan) !== $codec->encode($expected)) {
            return false;
        }
        // Completed purge keeps the installation identity; a retained retire plan is not a purge receipt.
        $module = $saved['affected_modules'][0];
        if (($module['version'] ?? null) !== $state['installed_version']
            || ($module['manifest_digest'] ?? null) !== $state['manifest_digest']
            || $this->existingOwnedTables($this->confirmedOwnedTables($this->confirmedOwnedTablesByModule($saved))) !== []) {
            return false;
        }
        $projectRoot = dirname($this->serverRoot);
        foreach ($module['source_paths'] as $relative) {
            $path = $projectRoot . '/' . $relative;
            if (file_exists($path) || is_link($path)) {
                return false;
            }
        }
        $catalog = $this->catalogs->plan([$packageKey], true);
        return $catalog['removed'] === [] && $catalog['blockers'] === [];
    }

    /** @return array<string,mixed>|null */
    private function installationState(string $packageKey): ?array
    {
        $row = Db::name('module_installation')->where('module_key', $packageKey)
            ->field('status,last_error_code,installed_version,manifest_digest')->find();
        return $row === null ? null : $row;
    }

    private function inputBelongsToPackage(string $input, string $packageKey): bool
    {
        if ($input === $packageKey) {
            return true;
        }
        return Db::name('module_installation')->where('module_key', $packageKey)->where('module_key', $input)->count() === 1;
    }

    /** @param array<string,mixed> $plan @return list<string> */
    private function confirmedModuleKeys(array $plan): array
    {
        $keys = [];
        foreach ((array) ($plan['affected_modules'] ?? []) as $module) {
            $key = is_array($module) ? ($module['module_key'] ?? null) : null;
            if (!is_string($key) || isset($keys[$key])) {
                throw new ModuleLifecycleException('MODULE_UNINSTALL_PLAN_CHANGED', 'Confirmed Module scope is invalid.');
            }
            $moduleKey = ModuleKey::fromString($key);
            $backend = rtrim(ModuleHostLayoutFactory::pathLayout()->backendRelativePath($moduleKey), '/');
            $allowed = [$backend];
            foreach (['admin-web', 'platform-web', 'pc-web', 'uniapp'] as $client) {
                $allowed[] = ModuleFrontendLayout::clientRoot($client) . '/' . str_replace('.', '-', $key);
            }
            if (!is_array($module['source_paths'] ?? null)
                || !in_array($backend, $module['source_paths'], true)
                || array_filter($module['source_paths'], static fn(mixed $path): bool => !is_string($path) || !in_array($path, $allowed, true)) !== []) {
                throw new ModuleLifecycleException('MODULE_UNINSTALL_PLAN_CHANGED', 'Confirmed Module source scope is invalid.');
            }
            $keys[$key] = true;
        }
        $result = array_keys($keys);
        sort($result, SORT_STRING);
        return $result;
    }

    /** @param array<string,mixed> $plan @return array<string,list<string>> */
    private function confirmedOwnedTablesByModule(array $plan): array
    {
        $modules = [];
        $seen = [];
        foreach ((array) ($plan['affected_modules'] ?? []) as $module) {
            $moduleKey = is_array($module) ? ($module['module_key'] ?? null) : null;
            if (!is_string($moduleKey) || isset($modules[$moduleKey])) {
                throw new ModuleLifecycleException('MODULE_UNINSTALL_PLAN_CHANGED', 'Confirmed owned table scope is invalid.');
            }
            $tables = [];
            foreach ((array) ($module['owned_tables'] ?? []) as $table) {
                if (!is_string($table) || preg_match('/^pa_[a-z0-9_]+$/D', $table) !== 1
                    || isset($tables[$table]) || isset($seen[$table])) {
                    throw new ModuleLifecycleException('MODULE_UNINSTALL_PLAN_CHANGED', 'Confirmed owned table scope is invalid.');
                }
                $tables[$table] = true;
                $seen[$table] = true;
            }
            $modules[$moduleKey] = array_keys($tables);
            sort($modules[$moduleKey], SORT_STRING);
        }
        ksort($modules, SORT_STRING);
        return $modules;
    }

    /** @param array<string,list<string>> $tablesByModule @return list<string> */
    private function confirmedOwnedTables(array $tablesByModule): array
    {
        $tables = [];
        foreach ($tablesByModule as $moduleTables) {
            $tables = [...$tables, ...$moduleTables];
        }
        sort($tables, SORT_STRING);
        return $tables;
    }

    /** @return array<string,mixed> */
    private function cleanPlan(string $key, bool $purge): array
    {
        $package = Db::name('module_installation')->where(function ($query) use ($key): void {
            $query->where('module_key', $key)->whereOr('module_key', $key);
        })->order('module_key')->value('module_key');
        return ['schema_version' => 1, 'package_key' => is_string($package) ? $package : $key, 'package_manifest_digest' => '', 'operation' => $purge ? 'purge' : 'retire', 'affected_modules' => [], 'preserved' => [], 'removed' => [], 'blockers' => []];
    }

    /** @param list<array<string,mixed>> $entries @param list<string> $identifiers */
    private function appendPlanEntry(array &$entries, string $scope, string $table, string $action, array $identifiers, bool $includeEmpty): void
    {
        sort($identifiers, SORT_STRING);
        if ($identifiers === [] && !$includeEmpty) {
            return;
        }
        $entries[] = ['scope' => $scope, 'table' => $table, 'action' => $action, 'count' => count($identifiers), 'identifiers' => $identifiers];
    }

    /** @param list<array<string,mixed>> $entries */
    private function sortPlanEntries(array &$entries): void
    {
        usort($entries, static fn(array $a, array $b): int => strcmp($a['scope'] . "\0" . $a['table'] . "\0" . $a['action'], $b['scope'] . "\0" . $b['table'] . "\0" . $b['action']));
    }

    private function inject(string $point): void
    {
        if (is_callable($this->faultInjector)) {
            ($this->faultInjector)($point);
        }
    }

    private function codec(): ModuleUninstallPlanCodec
    {
        return new ModuleUninstallPlanCodec();
    }
    private function removeTree(string $path): void
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($path);
    }
}
