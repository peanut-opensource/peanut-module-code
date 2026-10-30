<?php

declare(strict_types=1);

namespace app\command;

use app\platform\exception\plugin\PluginLifecycleException;
use app\platform\exception\plugin\PluginPackageException;
use app\platform\composition\plugin\ModuleDefinitionRegistryFactory;
use app\platform\infrastructure\plugin\PluginLockResolver;
use app\common\module\PackageCheck;
use app\common\execution\ModuleContextualCommand;
use PeanutAdmin\Modules\Identity\Contract\TenantModuleStateQueries;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use think\facade\Config;
use think\facade\Db;

/** Reconciles the official, release, or explicitly selected installed lock scope. */
final class PluginReconcile extends ModuleContextualCommand
{
    use PluginCommandSupport;

    /** Declares mutually exclusive locked scopes; selected preparation accepts installed packages only. */
    protected function configure()
    {
        $this->setName('plugin:reconcile')
            ->setDescription('Reconcile immutable locked Plugin installations')
            ->addOption('official-locked', null, Option::VALUE_NONE, 'Reconcile every official.* Plugin in plugins.lock')
            ->addOption('release-locked', null, Option::VALUE_NONE, 'Reconcile official and already installed Plugins in plugins.lock')
            ->addOption('prepared-release', null, Option::VALUE_NONE, 'Verify already prepared release dependencies without builds')
            ->addOption('selected-installed', null, Option::VALUE_REQUIRED | Option::VALUE_IS_ARRAY, 'Prepare and reconcile only these already installed locked package keys')
            ->addOption('plan-only', null, Option::VALUE_NONE, 'Read the locked Plugin plans without lifecycle changes');
    }

    /** Resolves the fixed lock scope and applies each eligible lifecycle operation. */
    protected function handle(Input $input, Output $output): int
    {
        $officialLocked = (bool) $input->getOption('official-locked');
        $releaseLocked = (bool) $input->getOption('release-locked');
        $selected = array_values(array_unique((array) $input->getOption('selected-installed')));
        $preparedRelease = (bool) $input->getOption('prepared-release');
        if ($preparedRelease && (!$releaseLocked || $officialLocked || $selected !== [])) {
            $output->writeln('{"error":"PLUGIN_RECONCILE_SCOPE_CONFLICT"}');
            return 1;
        }
        if ((bool) $input->getOption('plan-only')) {
            return $this->releasePlan($input, $output, $releaseLocked && !$officialLocked && $selected === []);
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
            return $this->runPluginOperation($output, function ($service) use ($selected): array {
                $config = Config::get('modules', []);
                $lock = is_array($config) ? trim((string) ($config['plugin_lock'] ?? '')) : '';
                if ($lock === '') {
                    throw new PluginLifecycleException('PLUGIN_LOCK_INVALID', 'Plugin lock path is not configured.');
                }
                $resolver = PluginLockResolver::forRuntime(dirname(__DIR__, 2), $lock);
                return $service->reconcileInstalled($this->dependencyOrderedPlugins($resolver, $selected, $config));
            });
        }

        return $this->runPluginOperation(
            $output,
            function ($service) use ($releaseLocked, $preparedRelease): array {
                $config = Config::get('modules', []);
                $lockPath = is_array($config) ? trim((string) ($config['plugin_lock'] ?? '')) : '';
                if ($lockPath === '') {
                    throw new PluginLifecycleException('PLUGIN_LOCK_INVALID', 'Plugin lock path is not configured.');
                }
                $resolver = PluginLockResolver::forRuntime(dirname(__DIR__, 2), $lockPath);
                $officialKeys = array_values(array_filter(
                    array_keys($resolver->all()),
                    static fn(string $key): bool => str_starts_with($key, 'peanut.'),
                ));
                $selection = $releaseLocked
                    ? $this->releasePluginSelection($resolver->all(), $officialKeys)
                    : ['reconcile' => $officialKeys, 'preserved_disabled' => []];
                $keys = $this->dependencyOrderedPlugins($resolver, $selection['reconcile'], $config);
                if ($keys === [] && !$releaseLocked) {
                    throw new PluginLifecycleException(
                        'OFFICIAL_PLUGIN_SET_EMPTY',
                        'plugins.lock contains no Plugin selected for reconciliation.',
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
                ];
                foreach ($keys as $key) {
                    $result = $service->reconcile($key, $preparedRelease);
                    $operation = (string) ($result['operation'] ?? '');
                    if (!array_key_exists($operation, $results)) {
                        throw new PluginLifecycleException(
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
    private function releasePlan(Input $input, Output $output, bool $validScope): int
    {
        return $this->runPluginOperation($output, function ($service) use ($validScope): array {
            if (!$validScope) {
                throw new PluginLifecycleException('PLUGIN_RECONCILE_SCOPE_CONFLICT', 'Plans require release-locked scope.');
            }
            $config = Config::get('modules', []);
            $lock = is_array($config) ? trim((string) ($config['plugin_lock'] ?? '')) : '';
            if ($lock === '') {
                throw new PluginLifecycleException('PLUGIN_LOCK_INVALID', 'Plugin lock path is not configured.');
            }
            $resolver = PluginLockResolver::forRuntime(dirname(__DIR__, 2), $lock);
            $official = array_values(array_filter(array_keys($resolver->all()),
                static fn(string $key): bool => str_starts_with($key, 'peanut.')));
            $selection = $this->releasePluginSelection($resolver->all(), $official);
            $this->dependencyOrderedPlugins($resolver, $selection['reconcile'], $config);
            $packages = [];
            foreach ([...$selection['reconcile'], ...$selection['preserved_disabled']] as $key) {
                $packages[] = $service->reconcilePlan($key, in_array($key, $selection['preserved_disabled'], true));
            }
            return ['readonly' => true, 'packages' => $packages,
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
    private function dependencyOrderedPlugins(PluginLockResolver $resolver, array $keys, array $config): array
    {
        $server = dirname(__DIR__, 2);
        $locked = $resolver->all();
        // The existing Kernel compiler validates Module dependency versions,
        // missing declarations and cycles against the fixed locked sources.
        $registry = (new ModuleDefinitionRegistryFactory($server))->fromPluginLock($resolver, $config, false);
        $owners = [];
        foreach ($locked as $pluginKey => $plugin) {
            foreach ($plugin->moduleRoots as $moduleKey => $_root) {
                if (isset($owners[$moduleKey])) {
                    throw new PluginLifecycleException('PLUGIN_MODULE_CONFLICT', 'Module lifecycle owner is ambiguous.');
                }
                $owners[$moduleKey] = $pluginKey;
            }
        }
        $selected = array_fill_keys($keys, true);
        $packages = [];
        foreach ($keys as $key) {
            if (!isset($locked[$key])) {
                throw new PluginLifecycleException('PLUGIN_LOCK_INVALID', 'Selected lifecycle package is not locked.');
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
                        throw new PluginLifecycleException('MODULE_PACKAGE_DEPENDENCY_MISSING', 'Module dependency has no locked lifecycle owner.');
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
            return (new PackageCheck($server))->dependencyOrder($packages, []);
        } catch (PluginPackageException $exception) {
            throw new PluginLifecycleException($exception->errorCode, $exception->getMessage());
        }
    }

    /**
     * Release replacement reconciles active packages, preserves fully disabled packages, and rejects transitions.
     *
     * @param array<string,\app\platform\value\plugin\PluginDescriptor> $locked
     * @param list<string> $officialKeys
     * @return array{reconcile:list<string>,preserved_disabled:list<string>}
     */
    private function releasePluginSelection(array $locked, array $officialKeys): array
    {
        $keys = array_fill_keys($officialKeys, true);
        $preserved = [];
        $rows = Db::name('plugin_installation')->where('status', '<>', 'uninstalled')
            ->field('plugin_key,status')->order('plugin_key')->select()->toArray();
        $pluginKeys = array_values(array_map(static fn(array $row): string => (string) $row['plugin_key'], $rows));
        $membersByPlugin = [];
        $moduleKeys = [];
        if ($pluginKeys !== []) {
            foreach (Db::name('plugin_module')->whereIn('plugin_key', $pluginKeys)
                ->field('plugin_key,module_key')->order('plugin_key')->order('module_key')->select()->toArray() as $member) {
                $pluginKey = (string) ($member['plugin_key'] ?? '');
                $moduleKey = (string) ($member['module_key'] ?? '');
                $membersByPlugin[$pluginKey][] = $moduleKey;
                $moduleKeys[$moduleKey] = true;
            }
        }
        $states = (new TenantModuleStateQueries())->installationStates(array_keys($moduleKeys));
        foreach ($rows as $row) {
            $key = (string) ($row['plugin_key'] ?? '');
            $memberKeys = $membersByPlugin[$key] ?? [];
            $members = count($memberKeys);
            if ((string) ($row['status'] ?? '') !== 'active' || $members < 1 || !isset($locked[$key])) {
                throw new PluginLifecycleException(
                    'PLUGIN_STATE_INVALID',
                    "Release reconciliation found an invalid or unlocked Plugin installation: {$key}",
                );
            }
            $active = 0;
            $disabled = 0;
            foreach ($memberKeys as $moduleKey) {
                $state = $states[$moduleKey] ?? null;
                if (($state['status'] ?? null) === 'active' && ($state['last_error_code'] ?? null) === null) {
                    ++$active;
                } elseif (($state['status'] ?? null) === 'maintenance' && ($state['last_error_code'] ?? null) === null) {
                    ++$disabled;
                }
            }
            if ($active === $members) {
                $keys[$key] = true;
                continue;
            }
            if ($disabled === $members) {
                unset($keys[$key]);
                $preserved[] = $key;
                continue;
            }
            throw new PluginLifecycleException(
                'PLUGIN_STATE_INVALID',
                "Release reconciliation found mixed or transitional Module states: {$key}",
            );
        }
        sort($preserved, SORT_STRING);
        return ['reconcile' => array_keys($keys), 'preserved_disabled' => $preserved];
    }
}
