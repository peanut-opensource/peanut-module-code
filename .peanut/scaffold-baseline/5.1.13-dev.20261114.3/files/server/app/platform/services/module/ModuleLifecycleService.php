<?php

declare(strict_types=1);

namespace app\platform\services\module;

use PeanutAdmin\Modules\Identity\Contract\TenantModuleStateQueries;
use app\platform\composition\module\ModuleDefinitionRegistryFactory;
use app\platform\exception\module\ModuleLifecycleException;
use app\platform\infrastructure\module\ModuleCatalogApplier;
use app\platform\infrastructure\module\ModuleLockResolver;
use app\platform\policy\module\ModuleLifecyclePolicy;
use app\platform\value\module\ModuleDescriptor;
use app\platform\value\module\ModuleFrontendLayout;
use app\common\contract\module\ModuleLifecycleCommands;
use app\common\persistence\AdvisoryLockExecution;
use app\common\persistence\AdvisoryLockUnavailable;
use app\common\module\ModuleMigrationRun;
use app\common\module\ModuleSeedRun;
use app\common\module\Dependencies;
use app\platform\exception\module\ModulePackageException;
use PeanutAdmin\Kernel\Module\ManifestLoader;
use PeanutAdmin\Kernel\Module\ManifestDocument;
use PeanutAdmin\Kernel\Module\CompiledModuleRegistry;
use think\console\Input;
use think\console\Output;
use think\facade\Db;

/** Deployment-scoped Plugin lifecycle; tenant license plans own commercial availability. */
final class ModuleLifecycleService implements ModuleLifecycleCommands
{
    private ?CompiledModuleRegistry $compiledRegistryCache = null;

    private ?string $compiledRegistryLockDigest = null;

    private bool $preparedBatchActive = false;

    public function __construct(
        private readonly ModuleLockResolver $resolver,
        private readonly ModuleDefinitionRegistryFactory $registries,
        private readonly array $moduleConfig,
        private readonly ModuleCatalogApplier $catalogs,
        private readonly TenantModuleStateQueries $tenantStates,
        private readonly ?CompiledModuleRegistry $applicationRegistry = null,
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

    /** Native initial installation: the product has already prepared the selected dependency graph. */
    public function installPrepared(array $pluginKeys, ?callable $onPackageStarting = null): array
    {
        $dependencies = (new Dependencies(rtrim(app()->getRootPath(), '/')))->verifyPreparedSelection(
            $this->resolver, $pluginKeys, $this->installedModules(),
        );
        // Compile once for the prepared batch; per-package locks still verify modules.lock is unchanged.
        $this->compiledRegistry();
        $results = [];
        $this->preparedBatchActive = true;
        try {
            foreach ($pluginKeys as $pluginKey) {
                if ($onPackageStarting !== null) {
                    $onPackageStarting($pluginKey);
                }
                // Keep the existing per-package admission, lifecycle lock, seed and recovery contract.
                $results[$pluginKey] = $this->withPackageLock(
                    $pluginKey,
                    fn(): array => $this->installLocked($pluginKey, $dependencies),
                );
            }
        } finally {
            $this->preparedBatchActive = false;
        }
        return ['dependencies' => $dependencies, 'packages' => $results];
    }

    private function installLocked(string $pluginKey, ?array $preparedDependencies = null): array
    {
        $plugin = $this->resolver->require($pluginKey);
        $this->assertTrustEligible($plugin);
        $current = $this->pluginInstallation($pluginKey, false);
        if (is_array($current) && $this->sameIdentity($plugin, $current) && $current['status'] === 'active'
            && $this->allModuleModulesActive($pluginKey)) {
            $manifests = $this->pluginManifests($plugin);
            $this->assertMigrationsComplete($manifests);
            return $plugin->publicIdentity() + ['operation' => 'unchanged'];
        }
        if (is_array($current) && $current['status'] === 'maintenance'
            && in_array($current['last_error_code'] ?? null, ['MODULE_PURGE_IN_PROGRESS', 'MODULE_RETIRE_IN_PROGRESS'], true)) {
            throw new ModuleLifecycleException((string) $current['last_error_code'], 'Module uninstall recovery must finish before install.');
        }
        $recovery = null;
        if (is_array($current) && in_array($current['status'], ['failed', 'installing'], true)) {
            $recovery = $this->installRecoveryIdentity($plugin, $current);
        } elseif (is_array($current) && $current['status'] !== 'uninstalled'
            && !((($current['status'] === 'active')
                    || ($current['status'] === 'maintenance' && $current['last_error_code'] === null))
                && $this->sameIdentity($plugin, $current))) {
            throw new ModuleLifecycleException('PLUGIN_ALREADY_INSTALLED', 'Use module upgrade for an installed package.');
        }
        $dependencies = $preparedDependencies ?? $this->prepareDependencies($plugin);
        $manifests = $this->pluginManifests($plugin);
        $this->assertPreflightOwnership($plugin, $manifests, false);
        $result = $this->activate($plugin, $manifests, false);
        if ($recovery !== null) {
            $result['operation'] = $recovery['higher_version'] ? 'recovered' : 'resumed';
        } elseif (is_array($current) && in_array($current['status'], ['active', 'maintenance'], true)) {
            $result['operation'] = 'reactivated';
        }
        return $result + ['dependencies' => $dependencies];
    }

    /**
     * Installs a missing package or reconciles the active install to the locked identity.
     *
     * @return array<string,mixed>
     */
    public function reconcile(string $pluginKey, bool $preparedRelease = false): array
    {
        return $this->withPackageLock($pluginKey, function () use ($pluginKey, $preparedRelease): array {
            $dependencies = $preparedRelease
                ? (new Dependencies(rtrim(app()->getRootPath(), '/')))->verifyInstalled(
                    $this->resolver->require($pluginKey), $this->resolver, $this->installedModules(),
                )
                : null;
            return $this->reconcileLocked($pluginKey, $dependencies);
        });
    }

    /** The native command supplies dependency-ordered, explicitly selected installed package keys. */
    public function reconcileInstalled(array $pluginKeys): array
    {
        $moduleKeys = [];
        $clients = [];
        foreach ($pluginKeys as $key) {
            $plugin = $this->resolver->require($key);
            $this->assertTrustEligible($plugin);
            $current = $this->pluginInstallation($key, false);
            if (!is_array($current) || !in_array($current['status'], ['active', 'failed'], true)) {
                throw new ModuleLifecycleException('PLUGIN_STATE_INVALID', 'Selected dependency preparation requires an active or failed installed package.');
            }
            if (version_compare($plugin->version, (string) $current['installed_version'], '<')) {
                throw new ModuleLifecycleException('PLUGIN_DOWNGRADE_REJECTED', 'Upgrade cannot install an older Plugin version.');
            }
            foreach ($plugin->moduleRoots as $moduleKey => $root) {
                $moduleKeys[$moduleKey] = true;
                $manifest = (new ManifestLoader())->load($root)->data;
                foreach (ModuleFrontendLayout::contributions($manifest['frontend'] ?? [], $moduleKey) as $contribution) {
                    $clients[explode('/', $contribution['root'])[0]] = true;
                }
            }
        }
        $serverRoot = rtrim(app()->getRootPath(), '/');
        return (new Dependencies($serverRoot))->prepareRelease($this->resolver, array_keys($clients),
            array_keys($moduleKeys), $this->installedModules(), function (array $prepared) use ($pluginKeys): array {
                $results = [];
                foreach ($pluginKeys as $key) {
                    $result = $this->withPackageLock($key, function () use ($key, $prepared): array {
                        $current = $this->pluginInstallation($key, false);
                        if (!is_array($current) || !in_array($current['status'], ['active', 'failed'], true)) {
                            throw new ModuleLifecycleException('PLUGIN_STATE_INVALID', 'Selected installed package changed before reconciliation.');
                        }
                        if ($current['status'] === 'failed') {
                            // A failed first install has no prior Module ownership to upgrade.
                            // Successful activation timestamps survive failure and uninstall.
                            $firstInstall = $current['activated_at'] === null
                                && $current['upgraded_at'] === null && $current['uninstalled_at'] === null;
                            return $firstInstall
                                ? $this->installLocked($key, $prepared)
                                : $this->upgradeLocked($key, false, null, $prepared);
                        }
                        return $this->reconcileLocked($key, $prepared);
                    });
                    unset($result['dependencies']);
                    $results[] = $result;
                }
                return ['dependencies' => $prepared, 'packages' => $results];
            });
    }

    /** Uses the native lock/manifest/ownership rules, without entering lifecycle mutation or recovery. */
    public function reconcilePlan(string $pluginKey, bool $preserveDisabled = false, ?callable $consumePlan = null, bool $preparedRelease = false): array
    {
        return $this->nativeReadPlan($pluginKey, false, $preserveDisabled, $consumePlan, $preparedRelease);
    }

    /** Read only the actual install/resume policy; release plans retain their independent active-state gate. */
    public function installPlan(string $pluginKey, ?callable $consumePlan = null): array
    {
        return $this->nativeReadPlan($pluginKey, true, false, $consumePlan);
    }

    private function installRecoveryIdentity(ModuleDescriptor $plugin, array $current): array
    {
        $sameIdentity = $this->sameIdentity($plugin, $current);
        $forwardRepair = version_compare($plugin->version, (string) $current['installed_version'], '>');
        if (!$sameIdentity && !$forwardRepair) {
            throw new ModuleLifecycleException('PLUGIN_INSTALL_RECOVERY_IDENTITY_MISMATCH',
                'Install recovery requires the same immutable package or a higher repair version.',
            );
        }
        return ['original_status' => $current['status'], 'original_installation_sha256' => hash('sha256', self::json($current)),
            'same_identity' => $sameIdentity, 'higher_version' => $forwardRepair];
    }

    private function nativeReadPlan(string $pluginKey, bool $install, bool $preserveDisabled, ?callable $consumePlan, bool $preparedRelease = false): array
    {
        return $this->withPackageLock($pluginKey, function () use ($pluginKey, $install, $preserveDisabled, $consumePlan, $preparedRelease): array {
            $plugin = $this->resolver->require($pluginKey);
            $this->assertTrustEligible($plugin);
            $manifests = $this->pluginManifests($plugin);
            $current = $this->pluginInstallation($pluginKey, false);
            if (!$install && $preparedRelease && is_array($current)
                && $current['status'] === 'failed' && $current['activated_at'] !== null) {
                return $this->upgradeLocked($pluginKey, true, $consumePlan);
            }
            $recovery = null; $installAction = 'planned_install';
            if ($install && is_array($current)) {
                if (in_array($current['status'], ['active', 'maintenance'], true)
                    && $current['last_error_code'] === null && $this->sameIdentity($plugin, $current)) {
                    $installAction = $this->allModuleModulesActive($pluginKey) ? 'planned_unchanged' : 'planned_reactivate';
                } elseif (in_array($current['status'], ['failed', 'installing'], true)) {
                    $recovery = $this->installRecoveryIdentity($plugin, $current);
                } elseif ($current['status'] === 'maintenance'
                    && in_array($current['last_error_code'] ?? null, ['MODULE_PURGE_IN_PROGRESS', 'MODULE_RETIRE_IN_PROGRESS'], true)) {
                    throw new ModuleLifecycleException((string) $current['last_error_code'], 'Module uninstall recovery must finish before install.');
                } elseif ($current['status'] !== 'uninstalled') {
                    throw new ModuleLifecycleException('PLUGIN_ALREADY_INSTALLED', 'Use module:upgrade for an installed Plugin.');
                }
            }
            if (!$install && is_array($current) && $current['status'] !== 'active'
                && !($preserveDisabled && $current['status'] === 'maintenance' && $current['last_error_code'] === null)) {
                throw new ModuleLifecycleException('PLUGIN_STATE_INVALID', 'Release plans require an active installation.');
            }
            $this->assertPreflightOwnership($plugin, $manifests, !$install && is_array($current));
            if ($preserveDisabled && !is_array($current)) {
                throw new ModuleLifecycleException('PLUGIN_STATE_INVALID', 'A missing package cannot be preserved disabled.');
            }
            if (!$install && is_array($current) && version_compare($plugin->version, (string) $current['installed_version'], '<')) {
                throw new ModuleLifecycleException('PLUGIN_DOWNGRADE_REJECTED', 'Release plans cannot downgrade a Plugin.');
            }
            $action = $install ? $installAction : ($preserveDisabled ? 'preserve_disabled' : (!is_array($current) ? 'planned_install'
                : ($this->sameIdentity($plugin, $current) ? 'planned_unchanged' : 'planned_upgrade')));
            $willMigrate = in_array($action, ['planned_install', 'planned_upgrade', 'planned_reactivate'], true);
            $migrationPlan = $this->migrationPlan($manifests);
            $applicationMigrations = $migrationPlan['migrations'];
            $pending = array_filter($applicationMigrations, static fn(array $row): bool => $row['disposition'] === 'pending');
            $modules = [];
            $missingSchemaTables = $this->missingSchemaTables($manifests);
            $mayApply = $migrationPlan['blockers'] === [] && ($preserveDisabled || $willMigrate || $pending === []);
            foreach ($manifests as $moduleKey => $manifest) {
                $manifestSha = hash_file('sha256', $plugin->moduleRoots[$moduleKey] . '/module.json');
                if (!is_string($manifestSha)) {
                    throw new ModuleLifecycleException('PLUGIN_MODULE_MISMATCH', 'Module manifest is unreadable.');
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

    private function reconcileLocked(string $pluginKey, ?array $preparedDependencies = null): array
    {
        $current = $this->pluginInstallation($pluginKey, false);
        if (!is_array($current)) {
            // A newly selected locked Module is installed once, under the same package lock.
            return $this->installLocked($pluginKey, $preparedDependencies);
        }

        if ($preparedDependencies !== null && $current['status'] === 'failed' && $current['activated_at'] !== null) {
            return $this->upgradeLocked($pluginKey, false, null, $preparedDependencies);
        }

        if ((string) $current['status'] !== 'active') {
            throw new ModuleLifecycleException(
                'PLUGIN_STATE_INVALID',
                'Plugin reconciliation only accepts an active installation.',
            );
        }

        $plugin = $this->resolver->require($pluginKey);
        $this->assertTrustEligible($plugin);
        if ($this->sameIdentity($plugin, $current)) {
            $manifests = $this->pluginManifests($plugin);
            $this->assertMigrationsComplete($manifests);
            return $plugin->publicIdentity() + ['operation' => 'unchanged'];
        }

        return $preparedDependencies === null ? $this->upgrade($pluginKey, false) : $this->upgradeLocked($pluginKey, false, null, $preparedDependencies);
    }

    /**
     * Returns an upgrade plan in dry-run mode; lower package versions are rejected.
     *
     * @return array<string,mixed>
     */
    public function upgrade(string $pluginKey, bool $dryRun, ?callable $consumePlan = null): array
    {
        if (!$dryRun && $consumePlan !== null) { throw new ModuleLifecycleException('PLUGIN_PLAN_CONSUMER_READ_ONLY', 'A plan consumer cannot authorize lifecycle writes.'); }
        return $this->withPackageLock($pluginKey, fn(): array => $this->upgradeLocked($pluginKey, $dryRun, $consumePlan));
    }

    private function upgradeLocked(string $pluginKey, bool $dryRun, ?callable $consumePlan = null, ?array $preparedDependencies = null): array
    {
        $plugin = $this->resolver->require($pluginKey);
        $this->assertTrustEligible($plugin);
        $hostVersion = \app\common\module\PackageCheck::compositionHostVersion(
            (string) file_get_contents(rtrim(app()->getRootPath(), '/') . '/composer.json'),
        );
        foreach ($plugin->moduleRoots as $key => $root) {
            $requirement = \app\common\module\PackageCheck::compositionHostRequirement($root);
            if ($requirement !== null) {
                \app\common\module\PackageCheck::assertCompositionHost($key, $requirement, $hostVersion);
            }
        }
        $current = $this->pluginInstallation($pluginKey, false);
        if (!is_array($current)) {
            throw new ModuleLifecycleException('PLUGIN_NOT_INSTALLED', 'Plugin must be installed before upgrade.');
        }
        // Activation writes the catalog and active state in one transaction. After interruption,
        // finish only that transaction for a previously active, fully migrated, identical target.
        if ($current['status'] === 'upgrading' && $current['activated_at'] !== null && $current['last_error_code'] === null
            && $this->sameIdentity($plugin, $current) && hash_equals((string) $current['lock_digest'], $plugin->lockDigest)) {
            $dependencies = (new Dependencies(rtrim(app()->getRootPath(), '/')))->verifyInstalled(
                $plugin, $this->resolver, $this->installedModules(),
            );
            return $this->withMigrationLock($plugin, function (ModuleDescriptor $plugin) use ($dependencies, $dryRun, $consumePlan, $current): array {
                $manifests = $this->pluginManifests($plugin);
                $this->assertPreflightOwnership($plugin, $manifests, true);
                $this->assertMigrationsComplete($manifests);
                $this->assertModuleSchema($manifests);
                if ($dryRun) {
                    $plan = $this->plan($plugin, $manifests, $current)
                        + ['dry_run' => true, 'operation' => 'upgrade'];
                    return $consumePlan === null ? $plan : $consumePlan($plan);
                }
                $this->registerCatalog($plugin, $manifests, true);
                return $plugin->publicIdentity() + ['operation' => 'upgraded', 'resumed' => true, 'dependencies' => $dependencies];
            });
        }
        if (!in_array($current['status'], ['active', 'failed'], true)
            && !($current['status'] === 'maintenance' && $current['last_error_code'] === null)) {
            throw new ModuleLifecycleException('PLUGIN_STATE_INVALID', 'Plugin cannot be upgraded from its current state.');
        }
        if (version_compare($plugin->version, (string) $current['installed_version'], '<')) {
            throw new ModuleLifecycleException('PLUGIN_DOWNGRADE_REJECTED', 'Upgrade cannot install an older Plugin version.');
        }
        $prepared = false;
        $activationStarted = false;
        $failureManifests = [];
        foreach ($plugin->moduleRoots as $key => $root) {
            $failureManifests[$key] = (new ManifestLoader())->load($root);
        }
        try {
            $dependencies = $dryRun || ($current['status'] === 'active' && $this->sameIdentity($plugin, $current))
                ? null : ($preparedDependencies ?? $this->prepareDependencies($plugin));
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
                throw new ModuleLifecycleException($blocker['code'], $blocker['message']);
            }
            if ($plan['pending_migrations'] === []) {
                $this->assertModuleSchema($manifests);
            }
            if ($this->sameIdentity($plugin, $current) && $current['status'] === 'active') {
                if (!$plan['may_apply'] || $plan['pending_migrations'] !== []) {
                    throw new ModuleLifecycleException('MODULE_MIGRATION_REQUIRED', 'An unchanged active package has pending Module migrations; review its locked identity before resuming.');
                }
                return $plan + ['dry_run' => false, 'operation' => 'unchanged', 'dependencies' => $dependencies];
            }
            if (!$plan['may_apply']) {
                throw new ModuleLifecycleException('MODULE_MIGRATION_REQUIRED', 'The selected Module migration plan cannot be applied.');
            }
            $activationStarted = true;
            return $this->activate($plugin, $manifests, true) + ['plan' => $plan, 'dependencies' => $dependencies];
        } catch (\Throwable $exception) {
            // Dependencies and static assets may already have changed before migration planning fails.
            if ($prepared && !$activationStarted) {
                $this->markFailed($plugin, $failureManifests,
                    $exception instanceof ModuleLifecycleException || $exception instanceof ModulePackageException
                        ? $exception->errorCode : 'PLUGIN_LIFECYCLE_FAILED');
            }
            throw $exception;
        }
    }

    /** Native dependencies must be ready before registry compilation can load new module providers. */
    private function prepareDependencies(ModuleDescriptor $plugin): array
    {
        $serverRoot = rtrim(app()->getRootPath(), '/');
        try {
            return (new Dependencies($serverRoot))->install($plugin, $this->resolver, $this->installedModules());
        } catch (\Throwable $exception) {
            $manifests = [];
            foreach ($plugin->moduleRoots as $key => $root) {
                $manifests[$key] = (new ManifestLoader())->load($root);
            }
            if (!is_array($this->pluginInstallation($plugin->key, false))) {
                $this->beginLifecycle($plugin, $manifests, false);
            }
            $this->markFailed($plugin, $manifests, $exception instanceof ModulePackageException ? $exception->errorCode : 'MODULE_DEPENDENCY_INSTALL_FAILED');
            throw $exception;
        }
    }

    /** @return array<string,array{version:string,status:string}> */
    private function installedModules(): array
    {
        $rows = Db::name('module_installation')->field('module_key,installed_version')->select()->toArray();
        $states = $this->tenantStates->installationStates(array_column($rows, 'module_key'));
        $installed = [];
        foreach ($rows as $row) {
            $key = (string) $row['module_key'];
            $installed[$key] = ['version' => (string) $row['installed_version'], 'status' => (string) ($states[$key]['status'] ?? 'uninstalled')];
        }
        return $installed;
    }

    /** Describes manual rollback steps; it does not change the installed package or database. */
    public function rollbackPlan(string $pluginKey): array
    {
        $current = $this->pluginInstallation($pluginKey, false);
        if (!is_array($current)) {
            throw new ModuleLifecycleException('PLUGIN_NOT_INSTALLED', 'Plugin is not installed.');
        }
        return [
            'module_key' => $pluginKey,
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
            throw new ModuleLifecycleException('PLUGIN_NOT_INSTALLED', 'Plugin is not installed.');
        }
        $plugin = $this->resolver->require($pluginKey);
        $manifests = $this->pluginManifests($plugin);
        ModuleLifecyclePolicy::assertMutable($manifests);
        if (ModuleLifecyclePolicy::installedBusinessDependents($this->resolver, array_keys($manifests), $this->tenantStates) !== []) {
            throw new ModuleLifecycleException('MODULE_DEPENDENT_INSTALLED', 'Installed dependent Modules must be removed before uninstall.');
        }
        $ownedModuleKeys = Db::name('module_installation')->where('module_key', $pluginKey)->column('module_key');
        if ($ownedModuleKeys !== [] && $this->tenantStates->hasEnabledModules($ownedModuleKeys)) {
            throw new ModuleLifecycleException('PLUGIN_TENANT_MODULE_ACTIVE', 'Remove the modules from tenant license plans before uninstall.');
        }
        $modules = $this->pluginModuleRows($pluginKey);
        $now = $this->now();
        Db::transaction(function () use ($pluginKey, $modules, $now): void {
            $moduleKeys = array_column($modules, 'module_key');
            ModuleLifecyclePolicy::assertNoOutstandingWorkflow($moduleKeys);
            $this->tenantStates->lockLifecycleRows($moduleKeys, [$pluginKey]);
            if ($this->tenantStates->enabledReferencesForUpdate($moduleKeys) !== []) {
                throw new ModuleLifecycleException('PLUGIN_TENANT_MODULE_ACTIVE', 'Remove the modules from tenant license plans before uninstall.');
            }
            $this->catalogs->retire($moduleKeys, false);
            $this->catalogs->finalizePackageRemoval($moduleKeys, false, $now);
        });
        return [
            'module_key' => $pluginKey,
            'operation' => 'uninstall',
            'status' => 'uninstalled',
            'preserve_data' => true,
            'preserved' => ['migrations', 'module_installation_ownership', 'business_data'],
        ];
    }

    /** @param array<string,ManifestDocument> $manifests @return array<string,mixed> */
    private function activate(ModuleDescriptor $plugin, array $manifests, bool $upgrade): array
    {
        $firstActivation = Db::name('module_installation')->where('module_key', $plugin->key)
            ->value('activated_at') === null;
        $this->beginLifecycle($plugin, $manifests, $upgrade);
        try {
            $this->applyMigrations($plugin);
            $this->assertModuleSchema($manifests);
            if ($firstActivation) {
                foreach ($manifests as $manifest) {
                    if (isset($manifest->data['backend']['seeds'])) {
                        $seeds = new ModuleSeedRun($manifest);
                        $seeds->setApp(app());
                        $seeds->run(new Input([]), new Output('buffer'));
                    }
                }
            }
            $this->registerCatalog($plugin, $manifests, $upgrade);
            return $plugin->publicIdentity() + ['operation' => $upgrade ? 'upgraded' : 'installed'];
        } catch (\Throwable $exception) {
            $errorCode = $exception instanceof ModuleLifecycleException
                ? $exception->errorCode
                : 'PLUGIN_LIFECYCLE_FAILED';
            $this->markFailed($plugin, $manifests, $errorCode);
            throw $exception;
        }
    }

    /** @param array<string,ManifestDocument> $manifests */
    private function beginLifecycle(ModuleDescriptor $plugin, array $manifests, bool $upgrade): void
    {

        $now = $this->now();
        Db::transaction(function () use ($plugin, $manifests, $upgrade, $now): void {
            $this->tenantStates->lockLifecycleRows(array_keys($manifests), [$plugin->key]);
            $this->catalogs->beginPackageDeployment($manifests, $upgrade, $now);
            $parameters = $this->pluginParameters($plugin);
            $values = [
                'source' => $parameters['source'],
                'artifact_sha256' => $parameters['artifact_sha256'], 'lock_digest' => $parameters['lock_digest'],
                'composer_identity_json' => $parameters['composer'], 'npm_identity_json' => $parameters['npm'],
                'frontend_identity_json' => $parameters['frontend'],
            ];
            Db::name('module_installation')->where('module_key', $plugin->key)->update($values);

        });
    }

    /** @param array<string,ManifestDocument> $manifests */
    private function assertPreflightOwnership(
        ModuleDescriptor $plugin,
        array $manifests,
        bool $upgrade,
    ): void {
        foreach ($manifests as $moduleKey => $_manifest) {
            $existing = Db::name('module_installation')->where('module_key', $moduleKey)->value('module_key');
            if (is_string($existing) && $existing !== $plugin->key) {
                throw new ModuleLifecycleException('PLUGIN_MODULE_CONFLICT', "Module has another Plugin owner: {$moduleKey}");
            }
            if ($upgrade && !is_string($existing)) {
                throw new ModuleLifecycleException('PLUGIN_MODULE_OWNERSHIP_MISSING', "Plugin does not own Module: {$moduleKey}");
            }
        }
    }

    private function applyMigrations(ModuleDescriptor $plugin): void
    {
        $this->withMigrationLock($plugin, function (ModuleDescriptor $plugin): void {
            $plan = $this->migrationPlan($this->pluginManifests($plugin));
            if ($plan['blockers'] !== []) {
                throw new ModuleLifecycleException($plan['blockers'][0]['code'], $plan['blockers'][0]['message']);
            }
            if (array_filter($plan['migrations'], static fn(array $row): bool => $row['disposition'] === 'pending') === []) {
                return;
            }
            $migration = new ModuleMigrationRun($plan['migrations']);
            $migration->setApp(app());
            $migration->run(new Input([]), new Output('buffer'));
            $this->assertMigrationsComplete($this->pluginManifests($plugin));
        });
    }

    /** Recheck the selected identity under the native migration lock before applying or verifying its schema. */
    private function withMigrationLock(ModuleDescriptor $plugin, callable $operation): mixed
    {
        $database = Db::connect()->query('SELECT DATABASE() AS database_name', [], true)[0]['database_name'];
        try {
            return (new AdvisoryLockExecution())->run('pa:native-migration:' . substr(hash('sha256', $database), 0, 32), 0,
                function () use ($plugin, $operation): mixed {
                    if ($this->preparedBatchActive) {
                        $this->resolver->assertLockFileUnchanged();
                    } else {
                        $this->resolver->reload();
                    }
                    $selected = $this->resolver->require($plugin->key);
                    if ($selected->publicIdentity() !== $plugin->publicIdentity()) {
                        throw new ModuleLifecycleException('MODULE_MIGRATION_SOURCE_CHANGED', 'Locked package changed before migration.');
                    }
                    return $operation($selected);
                });
        } catch (AdvisoryLockUnavailable) {
            throw new ModuleLifecycleException('APPLICATION_MIGRATION_BUSY', 'Another application migration is running.');
        }
    }

    private function registerCatalog(ModuleDescriptor $plugin, array $manifests, bool $upgrade): void
    {

        $now = $this->now();
        Db::transaction(function () use ($plugin, $manifests, $upgrade, $now): void {
            $this->tenantStates->lockLifecycleRows(array_keys($manifests), [$plugin->key]);
            $compiled = $this->compiledRegistry();
            $this->catalogs->apply($compiled, array_keys($manifests), false);
            $this->catalogs->activatePackageDeployment($manifests, $upgrade, $now, false);
            $parameters = $this->pluginParameters($plugin);
            Db::name('module_installation')->where('module_key', $plugin->key)->update([
                'source' => $parameters['source'],
                'artifact_sha256' => $parameters['artifact_sha256'], 'lock_digest' => $parameters['lock_digest'],
                'composer_identity_json' => $parameters['composer'], 'npm_identity_json' => $parameters['npm'],
                'frontend_identity_json' => $parameters['frontend'],
            ]);
            $this->catalogs->invalidateTenantAuthorization(array_keys($manifests));
        });
    }

    /** @param array<string,ManifestDocument> $manifests */
    private function markFailed(ModuleDescriptor $plugin, array $manifests, string $errorCode): void
    {
        try {

            $now = $this->now();
            Db::transaction(function () use ($plugin, $manifests, $errorCode, $now): void {
                $this->tenantStates->lockLifecycleRows(array_keys($manifests), [$plugin->key]);
                $this->catalogs->markPackageDeploymentFailed(array_keys($manifests), $errorCode, $now);

            });
        } catch (\Throwable) {
        }
    }

    /** @return array<string,ManifestDocument> */
    private function pluginManifests(ModuleDescriptor $plugin): array
    {
        $registry = $this->compiledRegistry();
        $manifests = [];
        foreach ($registry->modules as $manifest) {
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
            throw new ModuleLifecycleException('PLUGIN_MODULE_MISMATCH', 'Compiled Plugin modules differ from modules.lock.');
        }
        return $manifests;
    }

    private function assertTrustEligible(ModuleDescriptor $plugin): void
    {
        if (($plugin->trustResult()['status'] ?? null) !== 'eligible') {
            throw new ModuleLifecycleException(
                'PLUGIN_TRUST_QUALIFICATION_INVALID',
                'Plugin is not eligible for installation from the locked bundled channel.',
            );
        }
    }

    /** @param array<string,ManifestDocument> $manifests @param array<string,mixed> $current */
    private function plan(ModuleDescriptor $plugin, array $manifests, array $current): array
    {
        $migrationPlan = $this->migrationPlan($manifests);
        $pending = array_values(array_filter($migrationPlan['migrations'],
            static fn(array $row): bool => $row['disposition'] === 'pending'));
        $willMigrate = !$this->sameIdentity($plugin, $current) || $current['status'] !== 'active';
        $missingSchemaTables = $this->missingSchemaTables($manifests);
        $mayApply = $migrationPlan['blockers'] === [] && ($willMigrate || $pending === []);
        $modules = array_map(static fn(string $key): array => ['module_key' => $key], array_keys($manifests));
        return [
            'module_key' => $plugin->key,
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
        foreach ($this->compiledRegistry()->modules as $manifest) {
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
                $selected[$key] = $available[$key] ?? throw new ModuleLifecycleException(
                    'MODULE_MIGRATION_DEPENDENCY_MISSING', "Required Module is not compiled: {$key}",
                );
                $queue[] = $key;
            }
        }
        $table = (string) config('database.migration_table', 'migrations');
        $applied = Db::name($table)->column('migration_name', 'version');
        $sources = ModuleMigrationRun::sources($selected, root_path(), true);
        ModuleMigrationRun::assertLedgerIdentity($sources, $applied);
        $migrations = [];
        $host = [];
        $blockers = [];
        $pendingByModule = [];
        // Host sources are checked for identity collisions and prerequisites, never selected for execution here.
        foreach ($sources as $source) {
            $version = $source['migration_key'];
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

    private function compiledRegistry(): CompiledModuleRegistry
    {
        // HTTP retains the caller's already verified immutable snapshot; CLI caches per lock digest.
        if ($this->applicationRegistry !== null) {
            return $this->applicationRegistry;
        }
        $plugins = $this->resolver->all();
        $first = reset($plugins);
        $lockDigest = $first instanceof ModuleDescriptor ? $first->lockDigest : '';
        if ($this->compiledRegistryCache !== null && $this->compiledRegistryLockDigest === $lockDigest) {
            return $this->compiledRegistryCache;
        }
        $compiled = $this->registries->fromModuleLock($this->resolver, $this->moduleConfig);
        $this->compiledRegistryLockDigest = $lockDigest;
        return $this->compiledRegistryCache = $compiled;
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
            throw new ModuleLifecycleException('MODULE_SCHEMA_REQUIRED',
                'Deliver the required schema through application migrations before installing this module: ' . implode(', ', $missing));
        }
    }

    private function assertMigrationsComplete(array $manifests): void
    {
        $plan = $this->migrationPlan($manifests);
        if ($plan['blockers'] !== []) {
            throw new ModuleLifecycleException($plan['blockers'][0]['code'], $plan['blockers'][0]['message']);
        }
        foreach ($plan['migrations'] as $migration) {
            if ($migration['disposition'] === 'pending') {
                throw new ModuleLifecycleException('MODULE_MIGRATION_REQUIRED', 'The selected Module has a pending native migration: ' . $migration['file']);
            }
        }
    }

    private function pluginInstallation(string $pluginKey, bool $lock): array|false
    {
        $query = Db::name('module_installation')->where('module_key', $pluginKey);
        if ($lock) {
            $query->lock(true);
        }
        return $query->find() ?? false;
    }

    /** @return list<array<string,mixed>> */
    private function pluginModuleRows(string $pluginKey): array
    {
        return Db::name('module_installation')->where('module_key', $pluginKey)->order('module_key')->select()->toArray();
    }

    private function allModuleModulesActive(string $pluginKey): bool
    {
        $moduleKeys = Db::name('module_installation')->where('module_key', $pluginKey)->column('module_key');
        return $moduleKeys !== []
            && $this->tenantStates->activeInstallationCount(array_values(array_map('strval', $moduleKeys)))
                === count($moduleKeys);
    }

    /** @param array<string,mixed> $current */
    public static function sameIdentity(ModuleDescriptor $plugin, array $current): bool
    {
        return (string) $current['installed_version'] === $plugin->version
            && hash_equals((string) $current['artifact_sha256'], $plugin->source['sha256'])
            && self::jsonColumn($current['composer_identity_json']) === self::json($plugin->composer)
            && self::jsonColumn($current['npm_identity_json']) === self::json($plugin->npm)
            && self::jsonColumn($current['frontend_identity_json']) === self::json($plugin->frontend);
    }

    /** @return array<string,mixed> */
    private function pluginParameters(ModuleDescriptor $plugin): array
    {
        return [
            'module_key' => $plugin->key,
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

    private static function jsonColumn(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }
        try {
            return self::json(json_decode($value, true, 64, JSON_THROW_ON_ERROR));
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
                if ($this->preparedBatchActive) {
                    $this->resolver->assertLockFileUnchanged();
                } else {
                    $this->resolver->reload();
                }
                return $operation();
            });
        } catch (AdvisoryLockUnavailable) {
            throw new ModuleLifecycleException('MODULE_LIFECYCLE_BUSY', 'Module lifecycle is busy.');
        }
    }

}
