<?php

declare(strict_types=1);

namespace app\platform\services\plugin;

use app\common\value\installation\ApplicationSourceIdentity;
use app\common\value\installation\ServerReleaseIdentity;
use PeanutAdmin\Modules\Identity\Contract\TenantModuleStateQueries;
use app\common\infrastructure\module\ModuleHostLayoutFactory;
use app\platform\exception\plugin\PluginLifecycleException;
use app\platform\infrastructure\plugin\ModuleCatalogApplier;
use app\platform\infrastructure\plugin\ModuleUninstallPlanCodec;
use app\platform\infrastructure\plugin\PluginLockResolver;
use app\platform\policy\plugin\ModuleLifecyclePolicy;
use app\platform\value\plugin\PluginDescriptor;
use app\platform\value\plugin\ModuleFrontendLayout;
use app\common\persistence\AdvisoryLockExecution;
use app\common\persistence\AdvisoryLockUnavailable;
use PeanutAdmin\Kernel\Module\ManifestLoader;
use PeanutAdmin\Kernel\Module\ModuleKey;
use think\facade\Db;

/** Deployment-scoped retire/purge state machine. It never mutates pa_tenant_module. */
final class PluginRuntimeGovernanceService
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
        $scope = $this->scope($moduleOrPackageKey);
        if ($scope === null) {
            return [
                'operation' => 'preview',
                'plan_digest' => $this->codec()->digest($plan = $this->cleanPlan($moduleOrPackageKey, $purge)),
                'confirm_plan' => $plan,
                'affected_modules' => $plan['affected_modules'],
                'preserved' => $plan['preserved'],
                'removed' => $plan['removed'],
                'blockers' => $plan['blockers'],
            ];
        }
        $plan = $this->buildPlan($scope, $purge);
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
            throw new PluginLifecycleException('MODULE_UNINSTALL_PLAN_CHANGED', 'Confirmed Module uninstall plan digest differs.');
        }
        $operation = $purge ? 'purge' : 'retire';
        if (($confirmPlan['schema_version'] ?? null) !== 1 || ($confirmPlan['operation'] ?? null) !== $operation
            || !is_string($confirmPlan['package_key'] ?? null)
            || !is_array($confirmPlan['affected_modules'] ?? null)
            || !is_array($confirmPlan['removed'] ?? null)
            || !is_array($confirmPlan['preserved'] ?? null)
            || !is_array($confirmPlan['blockers'] ?? null)) {
            throw new PluginLifecycleException('MODULE_UNINSTALL_PLAN_CHANGED', 'Confirmed Module uninstall plan is invalid.');
        }
        $packageKey = (string) $confirmPlan['package_key'];
        if (!$this->inputBelongsToPackage($moduleOrPackageKey, $packageKey)) {
            throw new PluginLifecycleException('MODULE_UNINSTALL_PLAN_CHANGED', 'Confirmed package key differs.');
        }
        if ($confirmPlan['blockers'] !== []) {
            throw new PluginLifecycleException('MODULE_UNINSTALL_BLOCKED', 'Module uninstall plan contains blockers.');
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
                    return ['operation' => 'unchanged', 'package_key' => $packageKey, 'removed' => []];
                }
                $marker = $purge ? 'MODULE_PURGE_IN_PROGRESS' : 'MODULE_RETIRE_IN_PROGRESS';
                $resuming = is_array($state) && $state['status'] === 'maintenance' && $state['last_error_code'] === $marker;
                if (!$resuming) {
                    $scope = $this->scope($moduleOrPackageKey);
                    if ($scope === null) {
                        throw new PluginLifecycleException('PLUGIN_NOT_INSTALLED', 'Module package is not installed.');
                    }
                    $current = $this->buildPlan($scope, $purge);
                    if (!hash_equals($codec->encode($confirmPlan), $codec->encode($current))) {
                        throw new PluginLifecycleException('MODULE_UNINSTALL_PLAN_CHANGED', 'Module uninstall plan changed before execution.');
                    }
                } else {
                    $this->assertResumeScope($confirmPlan, $packageKey);
                }

                $moduleKeys = $this->confirmedModuleKeys($confirmPlan);
                $ownedTablesByModule = $this->confirmedOwnedTablesByModule($confirmPlan);
                $ownedTables = $this->confirmedOwnedTables($ownedTablesByModule);
                $this->markMaintenance($packageKey, $moduleKeys, $marker);
                $this->inject('after-marker');

                $catalog = $this->catalogs;
                $currentCatalog = $catalog->plan($moduleKeys, $purge);
                if ($currentCatalog['blockers'] !== []) {
                    throw new PluginLifecycleException('MODULE_UNINSTALL_BLOCKED', 'New catalog references block Module uninstall.');
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
                            throw new PluginLifecycleException('MODULE_PURGE_INCOMPLETE', 'Module catalog remains after purge.');
                        }
                    });
                    $this->inject('after-database-clean');
                }

                $this->finalizeFilesystem($packageKey, $confirmPlan, $confirmPlanDigest, $purge);
                $this->finalizeInstallation($packageKey, $moduleKeys, $purge);
                return [
                    'operation' => $purge ? 'purged' : 'retired',
                    'package_key' => $packageKey,
                    'affected_modules' => $confirmPlan['affected_modules'],
                    'removed' => $confirmPlan['removed'],
                    'preserved' => $confirmPlan['preserved'],
                ];
            });
        } catch (AdvisoryLockUnavailable) {
            throw new PluginLifecycleException('MODULE_LIFECYCLE_BUSY', 'Module lifecycle is busy.');
        }
    }

    /** @param array{package_key:string,package_manifest_digest:string,descriptor:PluginDescriptor,affected_modules:list<array<string,mixed>>} $scope @return array<string,mixed> */
    private function buildPlan(array $scope, bool $purge): array
    {
        $moduleKeys = array_column($scope['affected_modules'], 'module_key');
        $catalog = $this->catalogs->plan($moduleKeys, $purge);
        $removed = $catalog['removed'];
        $preserved = $catalog['preserved'];
        $blockers = [...$catalog['blockers'], ...$this->lifecycleBlockers($scope, $purge)];
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
        ];
    }

    /** @return array{package_key:string,package_manifest_digest:string,affected_modules:list<array<string,mixed>>}|null */
    private function scope(string $moduleOrPackageKey): ?array
    {
        return $this->lockedScope($moduleOrPackageKey)
            ?? $this->quarantinedScope($moduleOrPackageKey);
    }

    /** @return array{package_key:string,package_manifest_digest:string,descriptor:PluginDescriptor,affected_modules:list<array<string,mixed>>}|null */
    private function lockedScope(string $moduleOrPackageKey): ?array
    {
        $resolver = PluginLockResolver::forRuntime(
            $this->serverRoot,
            (string) ($this->moduleConfig['plugin_lock'] ?? 'plugins.lock'),
        );
        $descriptor = null;
        foreach ($resolver->all() as $plugin) {
            if ($plugin->key === $moduleOrPackageKey || isset($plugin->moduleRoots[$moduleOrPackageKey])) {
                $descriptor = $plugin;
                break;
            }
        }
        if (!$descriptor instanceof PluginDescriptor) {
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
                    throw new PluginLifecycleException('MODULE_TABLE_OWNERSHIP_INVALID', 'Module owned table declaration is invalid.');
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
        $installation = Db::name('plugin_installation')->alias('plugin')
            ->join('plugin_module member', 'member.plugin_key=plugin.plugin_key')
            ->where(function ($query) use ($moduleOrPackageKey): void {
                $query->where('plugin.plugin_key', $moduleOrPackageKey)->whereOr('member.module_key', $moduleOrPackageKey);
            })
            ->where('plugin.status', 'uninstalled')->whereNull('plugin.last_error_code')
            ->distinct(true)->field('plugin.plugin_key,plugin.installed_version,plugin.artifact_sha256')
            ->order('plugin.plugin_key')->select()->toArray();
        if ($installation === []) {
            return null;
        }
        if (count($installation) !== 1) {
            throw new PluginLifecycleException('MODULE_QUARANTINE_CONFLICT', 'Retired Module package identity is ambiguous.');
        }

        $packageKey = (string) $installation[0]['plugin_key'];
        $quarantines = $this->quarantineDirectories($packageKey);
        if ($quarantines === []) {
            return null;
        }
        if (count($quarantines) !== 1) {
            throw new PluginLifecycleException('MODULE_QUARANTINE_CONFLICT', 'Retired Module package has multiple quarantine identities.');
        }
        $quarantine = $quarantines[0];
        $pluginManifestPath = $quarantine . '/plugins/' . $packageKey . '/plugin.json';
        try {
            $plugin = json_decode((string) file_get_contents($pluginManifestPath), true, 128, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new PluginLifecycleException('MODULE_QUARANTINE_INVALID', 'Retired Module package manifest is invalid.');
        }
        if (!is_array($plugin) || ($plugin['key'] ?? null) !== $packageKey
            || ($plugin['version'] ?? null) !== $installation[0]['installed_version']
            || ($plugin['source']['sha256'] ?? null) !== $installation[0]['artifact_sha256']
            || !is_array($plugin['modules'] ?? null)) {
            throw new PluginLifecycleException('MODULE_QUARANTINE_INVALID', 'Retired Module package identity changed.');
        }

        $ownedManifests = Db::name('plugin_module')->where('plugin_key', $packageKey)
            ->order('module_key')->column('manifest_digest', 'module_key');
        $affected = [];
        $seenTables = [];
        $layout = ModuleHostLayoutFactory::pathLayout();
        foreach ($plugin['modules'] as $module) {
            if (!is_array($module) || !is_string($module['key'] ?? null) || !is_string($module['root'] ?? null)) {
                throw new PluginLifecycleException('MODULE_QUARANTINE_INVALID', 'Retired Module package scope is invalid.');
            }
            $key = ModuleKey::fromString($module['key']);
            $relativeRoot = rtrim($layout->backendRelativePath($key), '/');
            if ($module['root'] !== $relativeRoot) {
                throw new PluginLifecycleException('MODULE_QUARANTINE_INVALID', 'Retired Module path is not derived from its key.');
            }
            $root = realpath($quarantine . '/' . $relativeRoot);
            if ($root === false || !str_starts_with($root, $quarantine . DIRECTORY_SEPARATOR)) {
                throw new PluginLifecycleException('MODULE_QUARANTINE_INVALID', 'Retired Module source is unavailable.');
            }
            $manifest = (new ManifestLoader())->load($root);
            if (!isset($ownedManifests[$key->value()])
                || !hash_equals((string) $ownedManifests[$key->value()], $manifest->digest)) {
                throw new PluginLifecycleException('MODULE_QUARANTINE_INVALID', 'Retired Module manifest digest changed.');
            }
            $owned = array_values((array) ($manifest->data['database']['owned_tables'] ?? []));
            sort($owned, SORT_STRING);
            foreach ($owned as $table) {
                if (!is_string($table) || preg_match('/^pa_[a-z0-9_]+$/D', $table) !== 1 || isset($seenTables[$table])) {
                    throw new PluginLifecycleException('MODULE_TABLE_OWNERSHIP_INVALID', 'Module owned table declaration is invalid.');
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
            throw new PluginLifecycleException('MODULE_QUARANTINE_INVALID', 'Retired Module ownership scope changed.');
        }
        usort($affected, static fn(array $a, array $b): int => strcmp($a['module_key'], $b['module_key']));
        $manifestDigest = hash_file('sha256', $pluginManifestPath);
        if (!is_string($manifestDigest)) {
            throw new PluginLifecycleException('MODULE_QUARANTINE_INVALID', 'Retired package manifest cannot be digested.');
        }
        return ['package_key' => $packageKey, 'package_manifest_digest' => $manifestDigest, 'affected_modules' => $affected];
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
        $notDisabled = array_values(array_filter($moduleKeys, static fn(string $key): bool => ($states[$key]['status'] ?? null) !== 'maintenance'));
        if ($notDisabled !== []) {
            $blockers[] = ['code' => 'MODULE_DISABLE_REQUIRED', 'kind' => 'lifecycle', 'identifiers' => $notDisabled];
        }
        try {
            ModuleLifecyclePolicy::assertNoOutstandingWorkflow($moduleKeys);
        } catch (PluginLifecycleException $exception) {
            $blockers[] = ['code' => $exception->errorCode, 'kind' => 'lifecycle', 'identifiers' => $moduleKeys];
        }

        $dependents = ModuleLifecyclePolicy::installedBusinessDependents(
            PluginLockResolver::forRuntime(
                $this->serverRoot,
                (string) ($this->moduleConfig['plugin_lock'] ?? 'plugins.lock'),
            ),
            $moduleKeys,
            $this->tenantStates,
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
            throw new PluginLifecycleException('MODULE_OWNED_TABLE_FK_CYCLE', 'Owned table foreign keys contain a cycle.');
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
                throw new PluginLifecycleException('MODULE_TABLE_OWNERSHIP_INVALID', 'Owned table name is invalid.');
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
            throw new PluginLifecycleException('MODULE_PURGE_INCOMPLETE', 'Owned tables remain after purge.');
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
    private function markMaintenance(string $packageKey, array $moduleKeys, string $marker): void
    {
        $now = gmdate('Y-m-d H:i:s.v');
        Db::transaction(function () use ($packageKey, $moduleKeys, $marker, $now): void {
            $this->tenantStates->lockLifecycleRows($moduleKeys, [$packageKey]);
            $dependents = ModuleLifecyclePolicy::installedBusinessDependents(
                PluginLockResolver::forRuntime($this->serverRoot, (string) ($this->moduleConfig['plugin_lock'] ?? '../plugins.lock')),
                $moduleKeys,
                $this->tenantStates,
            );
            if ($dependents !== []) {
                throw new PluginLifecycleException('MODULE_DEPENDENT_INSTALLED', 'Installed Module dependencies must be removed before uninstall.');
            }
            ModuleLifecyclePolicy::assertNoOutstandingWorkflow($moduleKeys);
            if ($this->tenantStates->enabledReferencesForUpdate($moduleKeys) !== []) {
                throw new PluginLifecycleException('PLUGIN_TENANT_MODULE_ACTIVE', 'New TenantModule references block Module uninstall.');
            }
            Db::name('plugin_installation')->where('plugin_key', $packageKey)->update([
                'status' => 'maintenance', 'last_error_code' => $marker,
                'revision' => Db::raw('revision+1'), 'updated_at' => $now,
            ]);
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
            Db::name('plugin_installation')->where('plugin_key', $packageKey)->update([
                'status' => 'uninstalled', 'last_error_code' => null,
                'revision' => Db::raw('revision+1'), 'uninstalled_at' => $now, 'updated_at' => $now,
            ]);
        });
    }

    /** @param array<string,mixed> $plan */
    private function finalizeFilesystem(string $packageKey, array $plan, string $digest, bool $purge): void
    {
        $this->assertMutableSourceLock();
        $projectRoot = realpath(dirname($this->serverRoot)) ?: dirname($this->serverRoot);
        $paths = ['plugins/' . $packageKey];
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
                throw new PluginLifecycleException('MODULE_QUARANTINE_INVALID', 'Module source path escapes its application.');
            }
        }
        $lockPath = $projectRoot . '/plugins.lock';
        if (is_file($lockPath)) {
            $lock = json_decode((string) file_get_contents($lockPath), true, 128, JSON_THROW_ON_ERROR);
            if (!is_array($lock) || !is_array($lock['plugins'] ?? null)) {
                throw new PluginLifecycleException('PLUGIN_LOCK_INVALID', 'Plugin lock is invalid.');
            }
            $lock['plugins'] = array_values(array_filter($lock['plugins'], static fn(mixed $entry): bool => !is_array($entry) || ($entry['key'] ?? null) !== $packageKey));
            $contents = json_encode($lock, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
            $temporary = $lockPath . '.tmp-' . bin2hex(random_bytes(8));
            $stream = fopen($temporary, 'xb');
            if (!is_resource($stream) || fwrite($stream, $contents) !== strlen($contents) || !fflush($stream)) {
                throw new PluginLifecycleException('PLUGIN_LOCK_WRITE_FAILED', 'Plugin lock cannot be written.');
            }
            if (function_exists('fsync')) {
                fsync($stream);
            }
            fclose($stream);
            if (!rename($temporary, $lockPath)) {
                throw new PluginLifecycleException('PLUGIN_LOCK_WRITE_FAILED', 'Plugin lock cannot be promoted.');
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
                    throw new PluginLifecycleException('MODULE_QUARANTINE_FAILED', 'Module quarantine cannot be created.');
                }
                if (!rename($source, $target)) {
                    throw new PluginLifecycleException('MODULE_QUARANTINE_FAILED', 'Module path cannot enter quarantine.');
                }
                $moved++;
                if ($moved === 1) {
                    $this->inject('after-first-quarantine');
                }
            }
        }
        if ($purge) {
            foreach ($this->quarantineDirectories($packageKey) as $directory) {
                $this->removeTree($directory);
            }
        }
    }

    private function assertMutableSourceLock(): void
    {
        $identityPath = $this->serverRoot . '/.peanut/release-identity.json';
        if (file_exists($identityPath) || is_link($identityPath)) {
            ServerReleaseIdentity::resolve($this->serverRoot);
            throw new PluginLifecycleException(
                'PLUGIN_RELEASE_CHANGE_REQUIRED',
                'Server Plugin lock is part of the release identity; change Plugins through a new application release.',
            );
        }
        $manifestPath = dirname($this->serverRoot) . '/.peanut/application-manifest.json';
        $applicationSource = file_exists($manifestPath) || is_link($manifestPath);
        if ($applicationSource) {
            ApplicationSourceIdentity::load($this->serverRoot);
        }
        $lockPath = dirname($this->serverRoot) . '/plugins.lock';
        if ((!$applicationSource && env('PEANUT_INSTALLATION_SOURCE_MODE', false) !== 'development')
            || ($this->moduleConfig['plugin_lock'] ?? null) !== '../plugins.lock'
            || !is_file($lockPath) || is_link($lockPath)) {
            throw new PluginLifecycleException(
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
            throw new PluginLifecycleException('MODULE_QUARANTINE_INVALID', 'Module package key is invalid.');
        }
        $projectRoot = realpath(dirname($this->serverRoot)) ?: dirname($this->serverRoot);
        $root = $projectRoot . '/.local/module-quarantine';
        if (!is_dir($root)) {
            return [];
        }
        $resolvedRoot = realpath($root);
        if (!is_string($resolvedRoot)) {
            throw new PluginLifecycleException('MODULE_QUARANTINE_INVALID', 'Module quarantine root is invalid.');
        }
        $directories = [];
        foreach (glob($root . '/' . $packageKey . '-*', GLOB_ONLYDIR) ?: [] as $candidate) {
            $resolved = realpath($candidate);
            if (is_link($candidate) || !is_string($resolved)
                || !str_starts_with($resolved, $resolvedRoot . DIRECTORY_SEPARATOR)) {
                throw new PluginLifecycleException('MODULE_QUARANTINE_INVALID', 'Module quarantine path is invalid.');
            }
            $directories[] = $resolved;
        }
        sort($directories, SORT_STRING);
        return $directories;
    }

    /** @param array<string,mixed> $plan */
    private function assertResumeScope(array $plan, string $packageKey): void
    {
        $modules = $this->confirmedModuleKeys($plan);
        $actual = Db::name('plugin_module')->where('plugin_key', $packageKey)
            ->order('module_key')->column('manifest_digest', 'module_key');
        foreach ($plan['affected_modules'] as $module) {
            if (!is_array($module) || !isset($actual[$module['module_key']]) || !hash_equals((string) $actual[$module['module_key']], (string) $module['manifest_digest'])) {
                throw new PluginLifecycleException('MODULE_UNINSTALL_PLAN_CHANGED', 'Module ownership changed during recovery.');
            }
        }
        if (count($actual) !== count($modules)) {
            throw new PluginLifecycleException('MODULE_UNINSTALL_PLAN_CHANGED', 'Package Module scope changed during recovery.');
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
                throw new PluginLifecycleException('MODULE_UNINSTALL_PLAN_CHANGED', 'New removal scope appeared during recovery.');
            }
            foreach ($entry['identifiers'] as $identifier) {
                if (!isset($allowed[$key][$identifier])) {
                    throw new PluginLifecycleException('MODULE_UNINSTALL_PLAN_CHANGED', 'New removal target appeared during recovery.');
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
        $modules = $this->confirmedModuleKeys($plan);
        if ($modules === []) {
            return true;
        }
        return count($this->tenantStates->installationStates($modules)) === 0;
    }

    /** @return array<string,mixed>|null */
    private function installationState(string $packageKey): ?array
    {
        $row = Db::name('plugin_installation')->where('plugin_key', $packageKey)
            ->field('status,last_error_code')->find();
        return $row === null ? null : $row;
    }

    private function inputBelongsToPackage(string $input, string $packageKey): bool
    {
        if ($input === $packageKey) {
            return true;
        }
        return Db::name('plugin_module')->where('plugin_key', $packageKey)->where('module_key', $input)->count() === 1;
    }

    /** @param array<string,mixed> $plan @return list<string> */
    private function confirmedModuleKeys(array $plan): array
    {
        $keys = [];
        foreach ((array) ($plan['affected_modules'] ?? []) as $module) {
            $key = is_array($module) ? ($module['module_key'] ?? null) : null;
            if (!is_string($key) || isset($keys[$key])) {
                throw new PluginLifecycleException('MODULE_UNINSTALL_PLAN_CHANGED', 'Confirmed Module scope is invalid.');
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
                throw new PluginLifecycleException('MODULE_UNINSTALL_PLAN_CHANGED', 'Confirmed Module source scope is invalid.');
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
                throw new PluginLifecycleException('MODULE_UNINSTALL_PLAN_CHANGED', 'Confirmed owned table scope is invalid.');
            }
            $tables = [];
            foreach ((array) ($module['owned_tables'] ?? []) as $table) {
                if (!is_string($table) || preg_match('/^pa_[a-z0-9_]+$/D', $table) !== 1
                    || isset($tables[$table]) || isset($seen[$table])) {
                    throw new PluginLifecycleException('MODULE_UNINSTALL_PLAN_CHANGED', 'Confirmed owned table scope is invalid.');
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
        $package = Db::name('plugin_module')->where(function ($query) use ($key): void {
            $query->where('plugin_key', $key)->whereOr('module_key', $key);
        })->order('plugin_key')->value('plugin_key');
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
