<?php

declare(strict_types=1);

namespace app\platform\services\plugin;

use PeanutAdmin\Modules\Identity\Contract\TenantModuleStateQueries;
use app\platform\composition\plugin\PluginModuleRegistryFactory;
use app\platform\exception\plugin\PluginLifecycleException;
use app\platform\infrastructure\plugin\ModuleCatalogApplier;
use app\platform\infrastructure\plugin\PluginLockResolver;
use app\platform\policy\plugin\ModuleLifecyclePolicy;
use app\platform\value\plugin\PluginDescriptor;
use app\common\contract\module\PluginLifecycleCommands;
use app\common\persistence\AdvisoryLockExecution;
use app\common\persistence\AdvisoryLockUnavailable;
use app\common\module\ModuleMigrationRun;
use app\common\module\Dependencies;
use app\platform\exception\plugin\PluginPackageException;
use PeanutAdmin\Kernel\Module\ManifestLoader;
use PeanutAdmin\Kernel\Module\ManifestDocument;
use think\console\Input;
use think\console\Output;
use think\facade\Db;

/** Deployment-scoped Plugin lifecycle. It deliberately never mutates pa_tenant_module. */
final readonly class PluginLifecycleService implements PluginLifecycleCommands
{
    public function __construct(
        private PluginLockResolver $resolver,
        private PluginModuleRegistryFactory $registries,
        private array $moduleConfig,
        private ModuleCatalogApplier $catalogs,
        private TenantModuleStateQueries $tenantStates,
    ) {}

    /**
     * Installs or resumes the immutable package selected by the deployment lock.
     *
     * @return array<string,mixed>
     */
    public function install(string $pluginKey): array
    {
        return $this->withPackageLock($pluginKey, fn(): array => $this->installLocked($pluginKey));
    }

    private function installLocked(string $pluginKey): array
    {
        $plugin = $this->resolver->require($pluginKey);
        $this->assertTrustEligible($plugin);
        $current = $this->pluginInstallation($pluginKey, false);
        if (is_array($current) && $this->sameIdentity($plugin, $current) && $current['status'] === 'active'
            && $this->allPluginModulesActive($pluginKey)) {
            $manifests = $this->pluginManifests($plugin);
            $this->assertMigrationsComplete($manifests);
            $this->assertModuleSchema($manifests);
            return $plugin->publicIdentity() + ['operation' => 'unchanged'];
        }
        if (is_array($current) && $current['status'] === 'maintenance'
            && in_array($current['last_error_code'] ?? null, ['MODULE_PURGE_IN_PROGRESS', 'MODULE_RETIRE_IN_PROGRESS'], true)) {
            throw new PluginLifecycleException((string) $current['last_error_code'], 'Module uninstall recovery must finish before install.');
        }
        $recovery = null;
        if (is_array($current) && in_array($current['status'], ['failed', 'installing'], true)) {
            $recovery = $this->installRecoveryIdentity($plugin, $current);
        } elseif (is_array($current) && $current['status'] !== 'uninstalled'
            && !($current['status'] === 'active' && $this->sameIdentity($plugin, $current))) {
            throw new PluginLifecycleException('PLUGIN_ALREADY_INSTALLED', 'Use module upgrade for an installed package.');
        }
        $dependencies = $this->prepareDependencies($plugin);
        $manifests = $this->pluginManifests($plugin);
        $this->assertPreflightOwnership($plugin, $manifests, false);
        $result = $this->activate($plugin, $manifests, false);
        if ($recovery !== null) {
            $result['operation'] = $recovery['higher_version'] ? 'recovered' : 'resumed';
        } elseif (is_array($current) && $current['status'] === 'active') {
            $result['operation'] = 'reactivated';
        }
        return $result + ['dependencies' => $dependencies];
    }

    /**
     * Installs a missing package or reconciles the active install to the locked identity.
     *
     * @return array<string,mixed>
     */
    public function reconcile(string $pluginKey): array
    {
        return $this->withPackageLock($pluginKey, fn(): array => $this->reconcileLocked($pluginKey));
    }

    /** Uses the native lock/manifest/ownership rules, without entering lifecycle mutation or recovery. */
    public function reconcilePlan(string $pluginKey, bool $preserveDisabled = false, ?callable $consumePlan = null): array
    {
        return $this->nativeReadPlan($pluginKey, false, $preserveDisabled, $consumePlan);
    }

    /** Read only the actual install/resume policy; release plans retain their independent active-state gate. */
    public function installPlan(string $pluginKey, ?callable $consumePlan = null): array
    {
        return $this->nativeReadPlan($pluginKey, true, false, $consumePlan);
    }

    private function installRecoveryIdentity(PluginDescriptor $plugin, array $current): array
    {
        $sameIdentity = $this->sameIdentity($plugin, $current);
        $forwardRepair = version_compare($plugin->version, (string) $current['installed_version'], '>');
        if (!$sameIdentity && !$forwardRepair) {
            throw new PluginLifecycleException('PLUGIN_INSTALL_RECOVERY_IDENTITY_MISMATCH',
                'Install recovery requires the same immutable package or a higher repair version.',
            );
        }
        return ['original_status' => $current['status'], 'original_installation_sha256' => hash('sha256', self::json($current)),
            'same_identity' => $sameIdentity, 'higher_version' => $forwardRepair];
    }

    private function nativeReadPlan(string $pluginKey, bool $install, bool $preserveDisabled, ?callable $consumePlan): array
    {
        return $this->withPackageLock($pluginKey, function () use ($pluginKey, $install, $preserveDisabled, $consumePlan): array {
            $plugin = $this->resolver->require($pluginKey);
            $this->assertTrustEligible($plugin);
            $manifests = $this->pluginManifests($plugin);
            $current = $this->pluginInstallation($pluginKey, false);
            $recovery = null; $installAction = 'planned_install';
            if ($install && is_array($current)) {
                if ($current['status'] === 'active' && $this->sameIdentity($plugin, $current)) {
                    $installAction = $this->allPluginModulesActive($pluginKey) ? 'planned_unchanged' : 'planned_reactivate';
                } elseif (in_array($current['status'], ['failed', 'installing'], true)) {
                    $recovery = $this->installRecoveryIdentity($plugin, $current);
                } elseif ($current['status'] === 'maintenance'
                    && in_array($current['last_error_code'] ?? null, ['MODULE_PURGE_IN_PROGRESS', 'MODULE_RETIRE_IN_PROGRESS'], true)) {
                    throw new PluginLifecycleException((string) $current['last_error_code'], 'Module uninstall recovery must finish before install.');
                } elseif ($current['status'] !== 'uninstalled') {
                    throw new PluginLifecycleException('PLUGIN_ALREADY_INSTALLED', 'Use plugin:upgrade for an installed Plugin.');
                }
            }
            if (!$install && is_array($current) && $current['status'] !== 'active') {
                throw new PluginLifecycleException('PLUGIN_STATE_INVALID', 'Release plans require an active installation.');
            }
            $this->assertPreflightOwnership($plugin, $manifests, !$install && is_array($current));
            if ($preserveDisabled && !is_array($current)) {
                throw new PluginLifecycleException('PLUGIN_STATE_INVALID', 'A missing package cannot be preserved disabled.');
            }
            if (!$install && is_array($current) && version_compare($plugin->version, (string) $current['installed_version'], '<')) {
                throw new PluginLifecycleException('PLUGIN_DOWNGRADE_REJECTED', 'Release plans cannot downgrade a Plugin.');
            }
            $action = $install ? $installAction : ($preserveDisabled ? 'preserve_disabled' : (!is_array($current) ? 'planned_install'
                : ($this->sameIdentity($plugin, $current) ? 'planned_unchanged' : 'planned_upgrade')));
            $willMigrate = in_array($action, ['planned_install', 'planned_upgrade', 'planned_reactivate'], true);
            $migrationPlan = $this->migrationPlan($manifests);
            $applicationMigrations = $migrationPlan['migrations'];
            $pending = array_filter($applicationMigrations, static fn(array $row): bool => $row['disposition'] === 'pending');
            $modules = [];
            $missingSchemaTables = $this->missingSchemaTables($manifests);
            $mayApply = $migrationPlan['blockers'] === [] && ($willMigrate || $pending === []);
            foreach ($manifests as $moduleKey => $manifest) {
                $manifestSha = hash_file('sha256', $plugin->moduleRoots[$moduleKey] . '/module.json');
                if (!is_string($manifestSha)) {
                    throw new PluginLifecycleException('PLUGIN_MODULE_MISMATCH', 'Module manifest is unreadable.');
                }
                $modules[] = ['module_key' => $moduleKey, 'manifest_sha256' => $manifestSha];
            }
            usort($modules, static fn(array $left, array $right): int => strcmp($left['module_key'], $right['module_key']));
            $plan = array_replace($plugin->publicIdentity(), ['action' => $action, 'may_apply' => $mayApply,
                'planning_complete' => true,
                'status' => $mayApply ? 'ready' : 'blocked', 'execution_admitted' => false,
                'from_version' => is_array($current) ? (string) $current['installed_version'] : null,
                'manifest_sha256' => $plugin->manifestDigest, 'modules' => $modules,
                'application_migrations' => $applicationMigrations,
                'migration_scope' => $migrationPlan,
                'missing_schema_tables' => $missingSchemaTables,
                'reference_code_catalog' => $this->catalogs->referenceCodePlan($manifests),
                'observation' => 'read-only interval; execution revalidates all native guards']);
            if ($recovery !== null) { $plan['install_recovery'] = $recovery; }
            return $consumePlan === null ? $plan : $consumePlan($plan);
        });
    }

    private function reconcileLocked(string $pluginKey): array
    {
        $current = $this->pluginInstallation($pluginKey, false);
        if (!is_array($current)) {
            return $this->install($pluginKey);
        }

        if ((string) $current['status'] !== 'active') {
            throw new PluginLifecycleException(
                'PLUGIN_STATE_INVALID',
                'Plugin reconciliation only accepts an active installation.',
            );
        }

        $plugin = $this->resolver->require($pluginKey);
        $this->assertTrustEligible($plugin);
        if ($this->sameIdentity($plugin, $current)) {
            $manifests = $this->pluginManifests($plugin);
            $this->assertMigrationsComplete($manifests);
            $this->assertModuleSchema($manifests);
            return $plugin->publicIdentity() + ['operation' => 'unchanged'];
        }

        return $this->upgrade($pluginKey, false);
    }

    /**
     * Returns an upgrade plan in dry-run mode; lower package versions are rejected.
     *
     * @return array<string,mixed>
     */
    public function upgrade(string $pluginKey, bool $dryRun, ?callable $consumePlan = null): array
    {
        if (!$dryRun && $consumePlan !== null) { throw new PluginLifecycleException('PLUGIN_PLAN_CONSUMER_READ_ONLY', 'A plan consumer cannot authorize lifecycle writes.'); }
        return $this->withPackageLock($pluginKey, fn(): array => $this->upgradeLocked($pluginKey, $dryRun, $consumePlan));
    }

    private function upgradeLocked(string $pluginKey, bool $dryRun, ?callable $consumePlan = null): array
    {
        $plugin = $this->resolver->require($pluginKey);
        $this->assertTrustEligible($plugin);
        $current = $this->pluginInstallation($pluginKey, false);
        if (!is_array($current)) {
            throw new PluginLifecycleException('PLUGIN_NOT_INSTALLED', 'Plugin must be installed before upgrade.');
        }
        if (!in_array($current['status'], ['active', 'failed'], true)) {
            throw new PluginLifecycleException('PLUGIN_STATE_INVALID', 'Plugin cannot be upgraded from its current state.');
        }
        if (version_compare($plugin->version, (string) $current['installed_version'], '<')) {
            throw new PluginLifecycleException('PLUGIN_DOWNGRADE_REJECTED', 'Upgrade cannot install an older Plugin version.');
        }
        $prepared = false;
        $activationStarted = false;
        $failureManifests = [];
        foreach ($plugin->moduleRoots as $key => $root) {
            $failureManifests[$key] = (new ManifestLoader())->load($root);
        }
        try {
            $dependencies = $dryRun || ($current['status'] === 'active' && $this->sameIdentity($plugin, $current))
                ? null : $this->prepareDependencies($plugin);
            $prepared = $dependencies !== null;
            $manifests = $this->pluginManifests($plugin);
            $this->assertPreflightOwnership($plugin, $manifests, true);
            $plan = $this->plan($plugin, $manifests, $current);
            if ($dryRun) {
                $plan += ['dry_run' => true, 'operation' => 'upgrade'];
                return $consumePlan === null ? $plan : $consumePlan($plan);
            }
            if ($plan['migration_scope']['blockers'] !== []) {
                $blocker = $plan['migration_scope']['blockers'][0];
                throw new PluginLifecycleException($blocker['code'], $blocker['message']);
            }
            if ($plan['pending_migrations'] === []) {
                $this->assertModuleSchema($manifests);
            }
            if ($this->sameIdentity($plugin, $current) && $current['status'] === 'active') {
                if (!$plan['may_apply'] || $plan['pending_migrations'] !== []) {
                    throw new PluginLifecycleException('MODULE_MIGRATION_REQUIRED', 'An unchanged active package has pending Module migrations; review its locked identity before resuming.');
                }
                return $plan + ['dry_run' => false, 'operation' => 'unchanged', 'dependencies' => $dependencies];
            }
            if (!$plan['may_apply']) {
                throw new PluginLifecycleException('MODULE_MIGRATION_REQUIRED', 'The selected Module migration plan cannot be applied.');
            }
            $activationStarted = true;
            return $this->activate($plugin, $manifests, true) + ['plan' => $plan, 'dependencies' => $dependencies];
        } catch (\Throwable $exception) {
            // Dependencies and static assets may already have changed before migration planning fails.
            if ($prepared && !$activationStarted) {
                $this->markFailed($plugin, $failureManifests,
                    $exception instanceof PluginLifecycleException || $exception instanceof PluginPackageException
                        ? $exception->errorCode : 'PLUGIN_LIFECYCLE_FAILED');
            }
            throw $exception;
        }
    }

    /** Native dependencies must be ready before registry compilation can load new module providers. */
    private function prepareDependencies(PluginDescriptor $plugin): array
    {
        $serverRoot = rtrim(app()->getRootPath(), '/');
        $rows = Db::name('plugin_module')->field('module_key,module_version')->select()->toArray();
        $states = $this->tenantStates->installationStates(array_column($rows, 'module_key'));
        $installed = [];
        foreach ($rows as $row) {
            $key = (string) $row['module_key'];
            $installed[$key] = ['version' => (string) $row['module_version'], 'status' => (string) ($states[$key]['status'] ?? 'uninstalled')];
        }
        try {
            return (new Dependencies($serverRoot))->install($plugin, $this->resolver, $installed);
        } catch (\Throwable $exception) {
            $manifests = [];
            foreach ($plugin->moduleRoots as $key => $root) {
                $manifests[$key] = (new ManifestLoader())->load($root);
            }
            if (!is_array($this->pluginInstallation($plugin->key, false))) {
                $this->beginLifecycle($plugin, $manifests, false);
            }
            $this->markFailed($plugin, $manifests, $exception instanceof PluginPackageException ? $exception->errorCode : 'MODULE_DEPENDENCY_INSTALL_FAILED');
            throw $exception;
        }
    }

    /** Describes manual rollback steps; it does not change the installed package or database. */
    public function rollbackPlan(string $pluginKey): array
    {
        $current = $this->pluginInstallation($pluginKey, false);
        if (!is_array($current)) {
            throw new PluginLifecycleException('PLUGIN_NOT_INSTALLED', 'Plugin is not installed.');
        }
        return [
            'plugin_key' => $pluginKey,
            'installed_version' => (string) $current['installed_version'],
            'operation' => 'rollback-plan',
            'automatic' => false,
            'preserve_data' => true,
            'steps' => [
                'restore the verified application source and database backup together',
                'review native migration status before resuming application traffic',
            ],
            'applied_migrations' => array_values(array_filter($this->migrationPlan($this->pluginManifests($this->resolver->require($pluginKey)))['migrations'],
                static fn(array $row): bool => $row['disposition'] === 'already_applied')),
        ];
    }

    /**
     * Retires the package only after dependent and tenant-enabled modules are disabled.
     *
     * @return array<string,mixed>
     */
    public function uninstall(string $pluginKey): array
    {
        return $this->withPackageLock($pluginKey, fn(): array => $this->uninstallLocked($pluginKey));
    }

    private function uninstallLocked(string $pluginKey): array
    {
        $current = $this->pluginInstallation($pluginKey, false);
        if (!is_array($current) || $current['status'] === 'uninstalled') {
            throw new PluginLifecycleException('PLUGIN_NOT_INSTALLED', 'Plugin is not installed.');
        }
        $plugin = $this->resolver->require($pluginKey);
        $manifests = $this->pluginManifests($plugin);
        ModuleLifecyclePolicy::assertMutable($manifests);
        ModuleLifecyclePolicy::assertNoActiveBusinessDependents(
            $this->resolver,
            array_keys($manifests),
        );
        $ownedModuleKeys = Db::name('plugin_module')->where('plugin_key', $pluginKey)->column('module_key');
        if ($ownedModuleKeys !== [] && $this->tenantStates->hasEnabledModules($ownedModuleKeys)) {
            throw new PluginLifecycleException('PLUGIN_TENANT_MODULE_ACTIVE', 'Disable every TenantModule before uninstall.');
        }
        $modules = $this->pluginModuleRows($pluginKey);
        $now = $this->now();
        Db::transaction(function () use ($pluginKey, $modules, $now): void {
            $moduleKeys = array_column($modules, 'module_key');
            ModuleLifecyclePolicy::assertNoOutstandingWorkflow($moduleKeys);
            $this->tenantStates->lockLifecycleRows($moduleKeys, [$pluginKey]);
            if ($this->tenantStates->enabledReferencesForUpdate($moduleKeys) !== []) {
                throw new PluginLifecycleException('PLUGIN_TENANT_MODULE_ACTIVE', 'Disable every TenantModule before uninstall.');
            }
            $this->catalogs->retire(array_map(
                static fn(array $row): string => (string) $row['module_key'],
                $modules,
            ));
            $this->catalogs->retirePackageModules(array_values(array_map(
                static fn(array $row): string => (string) $row['module_key'],
                $modules,
            )), $now);
            Db::name('plugin_installation')->where('plugin_key', $pluginKey)->update([
                'status' => 'uninstalled',
                'revision' => Db::raw('revision+1'),
                'uninstalled_at' => $now,
                'last_error_code' => null,
                'updated_at' => $now,
            ]);
        });
        return [
            'plugin_key' => $pluginKey,
            'operation' => 'uninstall',
            'status' => 'uninstalled',
            'preserve_data' => true,
            'preserved' => ['migrations', 'plugin_module_ownership', 'business_data'],
        ];
    }

    /** @param array<string,ManifestDocument> $manifests @return array<string,mixed> */
    private function activate(PluginDescriptor $plugin, array $manifests, bool $upgrade): array
    {

        $this->beginLifecycle($plugin, $manifests, $upgrade);
        try {
            $this->applyMigrations($plugin);
            $this->assertModuleSchema($manifests);
            $this->registerCatalog($plugin, $manifests, $upgrade);
            return $plugin->publicIdentity() + ['operation' => $upgrade ? 'upgraded' : 'installed'];
        } catch (\Throwable $exception) {
            $errorCode = $exception instanceof PluginLifecycleException
                ? $exception->errorCode
                : 'PLUGIN_LIFECYCLE_FAILED';
            $this->markFailed($plugin, $manifests, $errorCode);
            throw $exception;
        }
    }

    /** @param array<string,ManifestDocument> $manifests */
    private function beginLifecycle(PluginDescriptor $plugin, array $manifests, bool $upgrade): void
    {

        $now = $this->now();
        Db::transaction(function () use ($plugin, $manifests, $upgrade, $now): void {
            $this->tenantStates->lockLifecycleRows(array_keys($manifests), [$plugin->key]);
            $parameters = $this->pluginParameters($plugin);
            $values = [
                'installed_version' => $parameters['version'], 'source' => $parameters['source'],
                'artifact_sha256' => $parameters['artifact_sha256'], 'lock_digest' => $parameters['lock_digest'],
                'composer_identity_json' => $parameters['composer'], 'npm_identity_json' => $parameters['npm'],
                'frontend_identity_json' => $parameters['frontend'], 'status' => 'installing',
                'last_error_code' => null, 'updated_at' => $now,
            ];
            $existing = Db::name('plugin_installation')->where('plugin_key', $plugin->key)->lock(true)->value('plugin_key');
            if ($existing === null) {
                Db::name('plugin_installation')->insert([
                    'plugin_key' => $plugin->key, ...$values, 'revision' => 1,
                    'installed_at' => $now, 'created_at' => $now,
                ]);
            } else {
                Db::name('plugin_installation')->where('plugin_key', $plugin->key)
                    ->update([...$values, 'revision' => Db::raw('revision+1')]);
            }
            $this->catalogs->beginPackageDeployment($manifests, $upgrade, $now);

        });
    }

    /** @param array<string,ManifestDocument> $manifests */
    private function assertPreflightOwnership(
        PluginDescriptor $plugin,
        array $manifests,
        bool $upgrade,
    ): void {
        foreach ($manifests as $moduleKey => $_manifest) {
            $existing = Db::name('plugin_module')->where('module_key', $moduleKey)->value('plugin_key');
            if (is_string($existing) && $existing !== $plugin->key) {
                throw new PluginLifecycleException('PLUGIN_MODULE_CONFLICT', "Module has another Plugin owner: {$moduleKey}");
            }
            if ($upgrade && !is_string($existing)) {
                throw new PluginLifecycleException('PLUGIN_MODULE_OWNERSHIP_MISSING', "Plugin does not own Module: {$moduleKey}");
            }
        }
    }

    /** Recheck the selected closure under the existing migration lock, then run natively. */
    private function applyMigrations(PluginDescriptor $plugin): void
    {
        $database = Db::connect()->query('SELECT DATABASE() AS database_name', [], true)[0]['database_name'];
        try {
            (new AdvisoryLockExecution())->run('pa:native-migration:' . substr(hash('sha256', $database), 0, 32), 0,
                function () use ($plugin): void {
                    $this->resolver->reload();
                    $selected = $this->resolver->require($plugin->key);
                    if ($selected->publicIdentity() !== $plugin->publicIdentity()) {
                        throw new PluginLifecycleException('MODULE_MIGRATION_SOURCE_CHANGED', 'Locked package changed before migration.');
                    }
                    $plan = $this->migrationPlan($this->pluginManifests($selected));
                    if ($plan['blockers'] !== []) {
                        throw new PluginLifecycleException($plan['blockers'][0]['code'], $plan['blockers'][0]['message']);
                    }
                    if (array_filter($plan['migrations'], static fn(array $row): bool => $row['disposition'] === 'pending') === []) {
                        return;
                    }
                    $migration = new ModuleMigrationRun($plan['migrations']);
                    $migration->setApp(app());
                    $migration->run(new Input([]), new Output('buffer'));
                    $this->assertMigrationsComplete($this->pluginManifests($selected));
                });
        } catch (AdvisoryLockUnavailable) {
            throw new PluginLifecycleException('APPLICATION_MIGRATION_BUSY', 'Another application migration is running.');
        }
    }

    private function registerCatalog(PluginDescriptor $plugin, array $manifests, bool $upgrade): void
    {

        $now = $this->now();
        Db::transaction(function () use ($plugin, $manifests, $upgrade, $now): void {
            $this->tenantStates->lockLifecycleRows(array_keys($manifests), [$plugin->key]);
            $compiled = $this->registries
                ->fromPluginLock($this->resolver, $this->moduleConfig)
                ->compiled();
            $this->catalogs->apply($compiled, array_keys($manifests));
            foreach ($manifests as $moduleKey => $manifest) {
                $ownership = Db::name('plugin_module')->where('module_key', $moduleKey)
                    ->field('plugin_key,created_at')->lock(true)->find();
                $existingOwner = $ownership['plugin_key'] ?? null;
                if (is_string($existingOwner) && $existingOwner !== $plugin->key) {
                    throw new PluginLifecycleException('PLUGIN_MODULE_CONFLICT', "Module has another Plugin owner: {$moduleKey}");
                }
                $catalogValues = [
                    'plugin_key' => $plugin->key,
                    'module_key' => $moduleKey,
                    'module_version' => $manifest->data['version'],
                    'manifest_digest' => $manifest->digest,
                    'updated_at' => $now,
                ];
                if ($ownership === null) {
                    Db::name('plugin_module')->insert([...$catalogValues, 'created_at' => $now]);
                } else {
                    Db::name('plugin_module')->where('module_key', $moduleKey)->update($catalogValues);
                }
            }
            $this->catalogs->activatePackageDeployment($manifests, $upgrade, $now);
            $parameters = $this->pluginParameters($plugin);
            $activatedAt = Db::name('plugin_installation')->where('plugin_key', $plugin->key)
                ->lock(true)->value('activated_at');
            Db::name('plugin_installation')->where('plugin_key', $plugin->key)->update([
                'installed_version' => $parameters['version'], 'source' => $parameters['source'],
                'artifact_sha256' => $parameters['artifact_sha256'], 'lock_digest' => $parameters['lock_digest'],
                'composer_identity_json' => $parameters['composer'], 'npm_identity_json' => $parameters['npm'],
                'frontend_identity_json' => $parameters['frontend'], 'status' => 'active',
                'revision' => Db::raw('revision+1'), 'activated_at' => $activatedAt ?? $now,
                'upgraded_at' => $upgrade ? $now : null,
                'uninstalled_at' => null, 'last_error_code' => null, 'updated_at' => $now,
            ]);

        });
    }

    /** @param array<string,ManifestDocument> $manifests */
    private function markFailed(PluginDescriptor $plugin, array $manifests, string $errorCode): void
    {
        try {

            $now = $this->now();
            Db::transaction(function () use ($plugin, $manifests, $errorCode, $now): void {
                $this->tenantStates->lockLifecycleRows(array_keys($manifests), [$plugin->key]);
                Db::name('plugin_installation')->where('plugin_key', $plugin->key)->update([
                    'status' => 'failed', 'revision' => Db::raw('revision+1'),
                    'last_error_code' => $errorCode, 'updated_at' => $now,
                ]);
                $this->catalogs->markPackageDeploymentFailed(array_keys($manifests), $errorCode, $now);

            });
        } catch (\Throwable) {
        }
    }

    /** @return array<string,ManifestDocument> */
    private function pluginManifests(PluginDescriptor $plugin): array
    {
        $registry = $this->registries->fromPluginLock($this->resolver, $this->moduleConfig);
        $manifests = [];
        foreach ($registry->compiled()->modules as $manifest) {
            $key = (string) ($manifest->data['key'] ?? '');
            if (isset($plugin->moduleRoots[$key])) {
                $manifests[$key] = $manifest;
            }
        }
        $compiledKeys = array_keys($manifests);
        $lockedKeys = array_keys($plugin->moduleRoots);
        sort($compiledKeys, SORT_STRING);
        sort($lockedKeys, SORT_STRING);
        if ($compiledKeys !== $lockedKeys) {
            throw new PluginLifecycleException('PLUGIN_MODULE_MISMATCH', 'Compiled Plugin modules differ from plugins.lock.');
        }
        return $manifests;
    }

    private function assertTrustEligible(PluginDescriptor $plugin): void
    {
        if (($plugin->trustResult()['status'] ?? null) !== 'eligible') {
            throw new PluginLifecycleException(
                'PLUGIN_TRUST_QUALIFICATION_INVALID',
                'Plugin is not eligible for installation from the locked bundled channel.',
            );
        }
    }

    /** @param array<string,ManifestDocument> $manifests @param array<string,mixed> $current */
    private function plan(PluginDescriptor $plugin, array $manifests, array $current): array
    {
        $migrationPlan = $this->migrationPlan($manifests);
        $pending = array_values(array_filter($migrationPlan['migrations'],
            static fn(array $row): bool => $row['disposition'] === 'pending'));
        $willMigrate = !$this->sameIdentity($plugin, $current) || $current['status'] !== 'active';
        $missingSchemaTables = $this->missingSchemaTables($manifests);
        $mayApply = $migrationPlan['blockers'] === [] && ($willMigrate || $pending === []);
        $modules = array_map(static fn(string $key): array => ['module_key' => $key], array_keys($manifests));
        return [
            'plugin_key' => $plugin->key,
            'from_version' => (string) $current['installed_version'],
            'to_version' => $plugin->version,
            'identity_changed' => !$this->sameIdentity($plugin, $current),
            'pending_migrations' => $pending,
            'migration_scope' => $migrationPlan,
            'missing_schema_tables' => $missingSchemaTables,
            'modules' => $modules, 'may_apply' => $mayApply, 'planning_complete' => true,
            'reference_code_catalog' => $this->catalogs->referenceCodePlan($manifests),
            'status' => $mayApply ? 'ready' : 'blocked', 'execution_admitted' => false,
            'rollback' => ['automatic' => false, 'requires_verified_backup' => $pending !== []],
            'trust' => $plugin->trustResult(),
        ];
    }

    /** @param array<string,ManifestDocument> $manifests */
    private function migrationPlan(array $manifests): array
    {
        $available = [];
        foreach ($this->registries->fromPluginLock($this->resolver, $this->moduleConfig)->compiled()->modules as $manifest) {
            $available[$manifest->data['key']] = $manifest;
        }
        $selected = $manifests;
        $queue = array_keys($selected);
        for ($index = 0; isset($queue[$index]); ++$index) {
            foreach ($selected[$queue[$index]]->data['dependencies'] ?? [] as $dependency) {
                $key = $dependency['module_key'];
                if (isset($selected[$key])) {
                    continue;
                }
                $selected[$key] = $available[$key] ?? throw new PluginLifecycleException(
                    'MODULE_MIGRATION_DEPENDENCY_MISSING', "Required Module is not compiled: {$key}",
                );
                $queue[] = $key;
            }
        }
        $table = (string) config('database.migration_table', 'migrations');
        $applied = Db::name($table)->column('migration_name', 'version');
        $appliedNames = [];
        foreach ($applied as $version => $name) {
            $appliedNames[strtolower((string) $name)] = (string) $version;
        }
        $migrations = [];
        $host = [];
        $blockers = [];
        $pendingByModule = [];
        // Host sources are checked for identity collisions and prerequisites, never selected for execution here.
        foreach (ModuleMigrationRun::sources($selected, root_path(), true) as $source) {
            $version = $source['migration_key'];
            $name = $source['name'];
            if ((isset($applied[$version]) && $applied[$version] !== $name)
                || (isset($appliedNames[strtolower($name)]) && $appliedNames[strtolower($name)] !== $version)) {
                throw new PluginLifecycleException('MODULE_MIGRATION_IDENTITY_CONFLICT', "Migration conflicts with the native ledger: {$source['file']}");
            }
            $row = $source + ['disposition' => isset($applied[$version]) ? 'already_applied' : 'pending'];
            if ($source['module_key'] === null) {
                $host[] = $row;
                if ($row['disposition'] === 'pending') {
                    $blockers[] = ['code' => 'APPLICATION_MIGRATION_REQUIRED',
                        'message' => "Apply the Host migration through its native entry first: {$source['file']}"];
                }
                continue;
            }
            $migrations[] = $row;
            if ($row['disposition'] === 'pending') {
                $pendingByModule[$source['module_key']][] = $version;
            }
        }
        $missingByModule = [];
        foreach ($selected as $key => $manifest) {
            $missingByModule[$key] = $this->missingSchemaTables([$key => $manifest]);
            if ($missingByModule[$key] !== [] && !isset($pendingByModule[$key])) {
                $blockers[] = ['code' => 'MODULE_SCHEMA_REQUIRED',
                    'message' => "No pending migration supplies the missing declared schema for {$key}: " . implode(', ', $missingByModule[$key])];
            }
        }
        foreach ($selected as $key => $manifest) {
            if (!isset($pendingByModule[$key])) {
                continue;
            }
            foreach ($manifest->data['dependencies'] ?? [] as $dependency) {
                $required = $dependency['module_key'];
                if ($missingByModule[$required] !== [] && isset($pendingByModule[$required])
                    && max($pendingByModule[$required]) >= min($pendingByModule[$key])) {
                    $blockers[] = ['code' => 'MODULE_MIGRATION_DEPENDENCY_ORDER_INVALID',
                        'message' => "Native numeric order does not establish the missing schema of {$required} before {$key}."];
                }
            }
        }
        return ['database' => Db::connect()->query('SELECT DATABASE() AS database_name', [], true)[0]['database_name'],
            'module_keys' => array_keys($selected), 'migrations' => $migrations, 'host_migrations' => $host,
            'blockers' => $blockers, 'direction' => 'up', 'ledger' => (string) Db::connect()->getConfig('prefix') . $table,
            'preconditions' => 'Declared required schema and native numeric order are checked; each migration must enforce its actual SQL prerequisites through native preFlightCheck/up/change.',
            'preview_limit' => 'Declared tables and author descriptions do not statically describe every SQL statement in arbitrary PHP.'];
    }

    /** @param array<string,ManifestDocument> $manifests @return list<string> */
    private function missingSchemaTables(array $manifests): array
    {
        $required = [];
        foreach ($manifests as $manifest) {
            foreach ($manifest->data['database']['owned_tables'] ?? [] as $table) {
                $required[$table] = true;
            }
        }
        if ($required === []) {
            return [];
        }
        $present = Db::table('information_schema.TABLES')->where('TABLE_SCHEMA', Db::raw('DATABASE()'))
            ->whereIn('TABLE_NAME', array_keys($required))->column('TABLE_NAME');
        $missing = array_values(array_diff(array_keys($required), $present));
        sort($missing, SORT_STRING);
        return $missing;
    }

    /** @param array<string,ManifestDocument> $manifests */
    private function assertModuleSchema(array $manifests): void
    {
        $missing = $this->missingSchemaTables($manifests);
        if ($missing !== []) {
            throw new PluginLifecycleException('MODULE_SCHEMA_REQUIRED',
                'Deliver the required schema through application migrations before installing this module: ' . implode(', ', $missing));
        }
    }

    private function assertMigrationsComplete(array $manifests): void
    {
        $plan = $this->migrationPlan($manifests);
        if ($plan['blockers'] !== []) {
            throw new PluginLifecycleException($plan['blockers'][0]['code'], $plan['blockers'][0]['message']);
        }
        foreach ($plan['migrations'] as $migration) {
            if ($migration['disposition'] === 'pending') {
                throw new PluginLifecycleException('MODULE_MIGRATION_REQUIRED', 'The selected Module has a pending native migration: ' . $migration['file']);
            }
        }
    }

    private function pluginInstallation(string $pluginKey, bool $lock): array|false
    {
        $query = Db::name('plugin_installation')->where('plugin_key', $pluginKey);
        if ($lock) {
            $query->lock(true);
        }
        return $query->find() ?? false;
    }

    /** @return list<array<string,mixed>> */
    private function pluginModuleRows(string $pluginKey): array
    {
        return Db::name('plugin_module')->where('plugin_key', $pluginKey)->order('module_key')->select()->toArray();
    }

    private function allPluginModulesActive(string $pluginKey): bool
    {
        $moduleKeys = Db::name('plugin_module')->where('plugin_key', $pluginKey)->column('module_key');
        return $moduleKeys !== []
            && $this->tenantStates->activeInstallationCount(array_values(array_map('strval', $moduleKeys)))
                === count($moduleKeys);
    }

    /** @param array<string,mixed> $current */
    private function sameIdentity(PluginDescriptor $plugin, array $current): bool
    {
        return (string) $current['installed_version'] === $plugin->version
            && hash_equals((string) $current['artifact_sha256'], $plugin->source['sha256'])
            && $this->jsonColumn($current['composer_identity_json']) === $this->json($plugin->composer)
            && $this->jsonColumn($current['npm_identity_json']) === $this->json($plugin->npm)
            && $this->jsonColumn($current['frontend_identity_json']) === $this->json($plugin->frontend);
    }

    /** @return array<string,mixed> */
    private function pluginParameters(PluginDescriptor $plugin): array
    {
        return [
            'plugin_key' => $plugin->key,
            'version' => $plugin->version,
            'source' => $plugin->source['type'] . ':' . $plugin->source['reference'],
            'artifact_sha256' => $plugin->source['sha256'],
            'lock_digest' => $plugin->lockDigest,
            'composer' => $this->json($plugin->composer),
            'npm' => $this->json($plugin->npm),
            'frontend' => $this->json($plugin->frontend),
        ];
    }

    private static function json(mixed $value): string
    {
        return (string) json_encode(
            self::canonicalJsonValue($value),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );
    }

    private function jsonColumn(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }
        try {
            return $this->json(json_decode($value, true, 64, JSON_THROW_ON_ERROR));
        } catch (\JsonException) {
            return '';
        }
    }

    private static function canonicalJsonValue(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(static fn(mixed $item): mixed => self::canonicalJsonValue($item), $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = self::canonicalJsonValue($item);
        }
        return $value;
    }

    private function now(): string
    {
        return gmdate('Y-m-d H:i:s.v');
    }

    private function lockName(string $pluginKey): string
    {
        return 'pa:module-runtime:' . substr(hash('sha256', $pluginKey), 0, 40);
    }

    private function withPackageLock(string $pluginKey, callable $operation): array
    {
        try {
            return (new AdvisoryLockExecution())->run($this->lockName($pluginKey), 0, function () use ($operation): array {
                $this->resolver->reload();
                return $operation();
            });
        } catch (AdvisoryLockUnavailable) {
            throw new PluginLifecycleException('MODULE_LIFECYCLE_BUSY', 'Module lifecycle is busy.');
        }
    }

}
