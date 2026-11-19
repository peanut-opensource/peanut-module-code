<?php

declare(strict_types=1);

namespace app\command;

use app\platform\exception\module\ModuleLifecycleException;
use app\platform\exception\module\ModulePackageException;
use app\platform\composition\module\ModuleDefinitionRegistryFactory;
use app\platform\infrastructure\module\ModuleLockResolver;
use app\platform\value\module\ModuleDescriptor;
use app\common\module\PackageCheck;
use app\common\execution\ModuleContextualCommand;
use PeanutAdmin\Modules\Identity\Contract\TenantModuleStateQueries;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use think\facade\Config;
use think\facade\Db;

/** Reconciles the official, release, or explicitly selected installed lock scope. */
final class ModuleReconcile extends ModuleContextualCommand
{
    use ModuleCommandSupport;

    /** Declares mutually exclusive locked scopes; selected preparation accepts installed packages only. */
    protected function configure()
    {
        $this->setName('module:reconcile')
            ->setDescription('Reconcile immutable locked Plugin installations')
            ->addOption('official-locked', null, Option::VALUE_NONE, 'Reconcile the default initial Module packages in modules.lock')
            ->addOption('release-locked', null, Option::VALUE_NONE, 'Reconcile installed active Modules to modules.lock, retaining disabled installations')
            ->addOption('prepared-release', null, Option::VALUE_NONE, 'Verify already prepared dependencies without builds for the release or selected installed scope')
            ->addOption('selected-installed', null, Option::VALUE_REQUIRED | Option::VALUE_IS_ARRAY, 'Prepare and reconcile only these already installed locked package keys')
            ->addOption('plan-only', null, Option::VALUE_NONE, 'Read the locked Plugin plans without lifecycle changes');
    }

    /** Resolves the fixed lock scope and applies each eligible lifecycle operation. */
    protected function handle(Input $input, Output $output): int
    {
        require_once dirname(__DIR__, 2) . '/database/InitialInstallationSource.php';
        $officialLocked = (bool) $input->getOption('official-locked');
        $releaseLocked = (bool) $input->getOption('release-locked');
        $selected = array_values(array_unique((array) $input->getOption('selected-installed')));
        $preparedRelease = (bool) $input->getOption('prepared-release');
        if ($preparedRelease && ($officialLocked || (!$releaseLocked && $selected === []))) {
            $output->writeln('{"error":"PLUGIN_RECONCILE_SCOPE_CONFLICT"}');
            return 1;
        }
        if ((bool) $input->getOption('plan-only')) {
            return $this->releasePlan($input, $output, $releaseLocked && !$officialLocked && $selected === [], $preparedRelease);
        }
        if (!$officialLocked && !$releaseLocked && $selected === []) {
            $output->writeln('{"error":"OFFICIAL_LOCKED_REQUIRED"}');
            return 1;
        }
        if (count(array_filter([$officialLocked, $releaseLocked, $selected !== []])) !== 1) {
            $output->writeln('{"error":"PLUGIN_RECONCILE_SCOPE_CONFLICT"}');
            return 1;
        }

        if ($selected !== []) {
            return $this->runModuleOperation($output, function ($service) use ($selected, $preparedRelease): array {
                $config = Config::get('modules', []);
                $lock = is_array($config) ? trim((string) ($config['module_lock'] ?? '')) : '';
                if ($lock === '') {
                    throw new ModuleLifecycleException('PLUGIN_LOCK_INVALID', 'Plugin lock path is not configured.');
                }
                $resolver = ModuleLockResolver::forRuntime(dirname(__DIR__, 2), $lock);
                $ordered = $this->dependencyOrderedPlugins($resolver, $selected, $config, $preparedRelease);
                if (!$preparedRelease) {
                    return $service->reconcileInstalled($ordered);
                }
                // Prepared adoption must not turn an absent/disabled selection into an installation.
                $states = Db::name('module_installation')->whereIn('module_key', $ordered)->column('status', 'module_key');
                foreach ($ordered as $key) {
                    if (($states[$key] ?? null) !== 'active') {
                        throw new ModuleLifecycleException('PLUGIN_STATE_INVALID', 'Prepared selected adoption requires active installed packages.');
                    }
                }
                $results = [];
                foreach ($ordered as $key) {
                    $results[] = $service->reconcile($key, true);
                }
                return ['scope' => 'selected-installed', 'packages' => $results];
            });
        }

        return $this->runModuleOperation(
            $output,
            function ($service) use ($releaseLocked, $preparedRelease): array {
                $config = Config::get('modules', []);
                $lockPath = is_array($config) ? trim((string) ($config['module_lock'] ?? '')) : '';
                if ($lockPath === '') {
                    throw new ModuleLifecycleException('PLUGIN_LOCK_INVALID', 'Plugin lock path is not configured.');
                }
                $resolver = ModuleLockResolver::forRuntime(dirname(__DIR__, 2), $lockPath);
                if ($releaseLocked) {
                    $selection = $this->releaseModuleSelection($resolver->all(), $preparedRelease);
                } else {
                    $defaults = \InitialInstallationSource::modules(dirname(__DIR__, 2));
                    $officialKeys = array_keys(array_filter(
                        $resolver->all(),
                        static fn(ModuleDescriptor $plugin): bool => array_intersect(
                            array_keys($plugin->moduleRoots), $defaults,
                        ) !== [],
                    ));
                    $selection = ['reconcile' => $officialKeys, 'preserved_disabled' => [], 'preserved_uninstalled' => []];
                }
                $keys = $this->dependencyOrderedPlugins($resolver, $selection['reconcile'], $config);
                if ($keys === [] && !$releaseLocked) {
                    throw new ModuleLifecycleException(
                        'OFFICIAL_PLUGIN_SET_EMPTY',
                        'modules.lock contains no Plugin selected for reconciliation.',
                    );
                }

                $results = [
                    'installed' => [],
                    'upgraded' => [],
                    'unchanged' => [],
                    'preserved_disabled' => array_map(
                        static fn(string $key): array => ['key' => $key, 'operation' => 'preserve-disabled'],
                        $selection['preserved_disabled'],
                    ),
                    'preserved_uninstalled' => array_map(
                        static fn(string $key): array => ['key' => $key, 'operation' => 'preserve-uninstalled'],
                        $selection['preserved_uninstalled'],
                    ),
                ];
                foreach ($keys as $key) {
                    $result = $service->reconcile($key, $preparedRelease);
                    $operation = (string) ($result['operation'] ?? '');
                    if (!array_key_exists($operation, $results)) {
                        throw new ModuleLifecycleException(
                            'PLUGIN_RECONCILE_RESULT_INVALID',
                            "Plugin reconciliation returned an unsupported operation: {$operation}",
                        );
                    }
                    $results[$operation][] = $result;
                }

                return $results;
            },
        );
    }

    /** The plan branch observes the current locked selection without mutation. */
    private function releasePlan(Input $input, Output $output, bool $validScope, bool $preparedRelease): int
    {
        return $this->runModuleOperation($output, function ($service) use ($validScope, $preparedRelease): array {
            if (!$validScope) {
                throw new ModuleLifecycleException('PLUGIN_RECONCILE_SCOPE_CONFLICT', 'Plans require release-locked scope.');
            }
            $config = Config::get('modules', []);
            $lock = is_array($config) ? trim((string) ($config['module_lock'] ?? '')) : '';
            if ($lock === '') {
                throw new ModuleLifecycleException('PLUGIN_LOCK_INVALID', 'Plugin lock path is not configured.');
            }
            $resolver = ModuleLockResolver::forRuntime(dirname(__DIR__, 2), $lock);
            $selection = $this->releaseModuleSelection($resolver->all(), $preparedRelease);
            $this->dependencyOrderedPlugins($resolver, $selection['reconcile'], $config);
            $packages = [];
            foreach ([...$selection['reconcile'], ...$selection['preserved_disabled']] as $key) {
                $packages[] = $service->reconcilePlan(
                    $key, in_array($key, $selection['preserved_disabled'], true), null, $preparedRelease,
                );
            }
            return ['readonly' => true, 'packages' => $packages,
                'preserved_uninstalled' => $selection['preserved_uninstalled'],
                'may_apply' => !in_array(false, array_column($packages, 'may_apply'), true)];
        });
    }

    /**
     * Project the verified Module graph onto atomic Plugin lifecycle owners.
     * Do not activate an unselected/disabled required dependency implicitly.
     *
     * @param list<string> $keys
     * @param array<string,mixed> $config
     * @return list<string>
     */
    private function dependencyOrderedPlugins(ModuleLockResolver $resolver, array $keys, array $config, bool $useInstalledDependencies = false): array
    {
        $server = dirname(__DIR__, 2);
        $locked = $resolver->all();
        // The existing Kernel compiler validates Module dependency versions,
        // missing declarations and cycles against the fixed locked sources.
        $registry = (new ModuleDefinitionRegistryFactory($server))->fromModuleLock($resolver, $config, false);
        $owners = [];
        foreach ($locked as $pluginKey => $plugin) {
            foreach ($plugin->moduleRoots as $moduleKey => $_root) {
                if (isset($owners[$moduleKey])) {
                    throw new ModuleLifecycleException('PLUGIN_MODULE_CONFLICT', 'Module lifecycle owner is ambiguous.');
                }
                $owners[$moduleKey] = $pluginKey;
            }
        }
        $selected = array_fill_keys($keys, true);
        $packages = [];
        foreach ($keys as $key) {
            if (!isset($locked[$key])) {
                throw new ModuleLifecycleException('PLUGIN_LOCK_INVALID', 'Selected lifecycle package is not locked.');
            }
            $dependencies = [];
            foreach ($locked[$key]->moduleRoots as $moduleKey => $_root) {
                $manifest = $registry->requireManifest($moduleKey)->data;
                $required = $manifest['dependencies'] ?? [];
                $optional = array_filter($manifest['optional_dependencies'] ?? [],
                    static fn(array $edge): bool => isset($selected[$owners[$edge['module_key']] ?? '']));
                foreach ([...$required, ...$optional] as $edge) {
                    $owner = $owners[$edge['module_key']] ?? null;
                    if (!is_string($owner)) {
                        throw new ModuleLifecycleException('MODULE_PACKAGE_DEPENDENCY_MISSING', 'Module dependency has no locked lifecycle owner.');
                    }
                    if ($owner !== $key) {
                        // Module versions were checked above; the package graph
                        // only orders these exact immutable lifecycle owners.
                        $dependencies[$owner] = ['module_key' => $owner, 'version' => $locked[$owner]->version];
                    }
                }
            }
            $packages[$key] = ['version' => $locked[$key]->version, 'dependencies' => array_values($dependencies)];
        }
        try {
            $available = $useInstalledDependencies
                ? Db::name('module_installation')->where('status', 'active')->column('installed_version', 'module_key')
                : [];
            return (new PackageCheck($server))->dependencyOrder($packages, $available);
        } catch (ModulePackageException $exception) {
            throw new ModuleLifecycleException($exception->errorCode, $exception->getMessage());
        }
    }

    /**
     * A lock entry makes source available; only active installations select release adoption.
     * New Modules require explicit installation; disabled and retired Modules retain their state.
     * A source/lock deletion is not permission to discard a live installation or its data.
     *
     * @param array<string,ModuleDescriptor> $locked
     * @return array{reconcile:list<string>,preserved_disabled:list<string>,preserved_uninstalled:list<string>}
     */
    private function releaseModuleSelection(array $locked, bool $preparedRelease = false): array
    {
        $keys = [];
        $preserved = [];
        $retired = [];
        $rows = Db::name('module_installation')->field('module_key,status,last_error_code,activated_at')
            ->order('module_key')->select()->toArray();
        foreach ($rows as $row) {
            $key = (string) $row['module_key'];
            if ($row['status'] === 'uninstalled') {
                unset($keys[$key]);
                $retired[] = $key;
                continue;
            }
            if (!isset($locked[$key])) {
                throw new ModuleLifecycleException('PLUGIN_STATE_INVALID',
                    "Release reconciliation requires a locked, active, single-Module installation: {$key}");
            }
            $state = $row;
            // Prepared deployment can finish a failed upgrade without rebuilding assets.
            if ($preparedRelease && $state['status'] === 'failed' && $state['activated_at'] !== null) {
                $keys[$key] = true;
                continue;
            }
            if (($state['status'] ?? null) === 'active' && ($state['last_error_code'] ?? null) === null) {
                $keys[$key] = true;
                continue;
            }
            if (($state['status'] ?? null) === 'maintenance' && ($state['last_error_code'] ?? null) === null) {
                unset($keys[$key]);
                $preserved[] = $key;
                continue;
            }
            throw new ModuleLifecycleException('PLUGIN_STATE_INVALID',
                "Release reconciliation found a transitional Module state: {$key}");
        }
        sort($preserved, SORT_STRING);
        sort($retired, SORT_STRING);
        return ['reconcile' => array_keys($keys), 'preserved_disabled' => $preserved, 'preserved_uninstalled' => $retired];
    }
}
