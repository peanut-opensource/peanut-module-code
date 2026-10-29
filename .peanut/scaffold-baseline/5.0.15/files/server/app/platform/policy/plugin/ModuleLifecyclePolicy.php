<?php

declare(strict_types=1);

namespace app\platform\policy\plugin;

use app\platform\exception\plugin\PluginLifecycleException;
use app\platform\infrastructure\plugin\PluginLockResolver;
use PeanutAdmin\Kernel\Module\ManifestDocument;
use PeanutAdmin\Kernel\Module\ManifestLoader;
use PeanutAdmin\Modules\Identity\Contract\TenantModuleStateQueries;
use app\common\infrastructure\module\ModuleHostLayoutFactory;
use PeanutAdmin\Kernel\Module\ModuleKey;
use think\facade\App;
use think\facade\Db;

/** Enforces manifest-owned core protection and explicit business dependencies. */
final class ModuleLifecyclePolicy
{
    private function __construct() {}

    public static function isProtected(ManifestDocument $manifest): bool
    {
        $lifecycle = $manifest->data['lifecycle'] ?? null;
        return is_array($lifecycle) && ($lifecycle['protected'] ?? false) === true;
    }

    /** @param array<string,ManifestDocument> $manifests @return list<string> */
    public static function protectedModuleKeys(array $manifests): array
    {
        $protected = [];
        foreach ($manifests as $moduleKey => $manifest) {
            if (self::isProtected($manifest)) {
                $protected[] = $moduleKey;
            }
        }
        sort($protected, SORT_STRING);
        return $protected;
    }

    /** @param array<string,ManifestDocument> $manifests */
    public static function assertMutable(array $manifests): void
    {
        if (self::protectedModuleKeys($manifests) !== []) {
            throw new PluginLifecycleException(
                'MODULE_LIFECYCLE_PROTECTED',
                'A protected core Module cannot be disabled, retired, or purged.',
            );
        }
    }

    /** @param list<string> $moduleKeys @return list<string> */
    public static function activeBusinessDependents(
        PluginLockResolver $resolver,
        array $moduleKeys,
        TenantModuleStateQueries $installationStates = new TenantModuleStateQueries(),
    ): array {
        return self::businessDependents($resolver, $moduleKeys, $installationStates, true);
    }

    /** Includes disabled and failed installations until their package has actually been removed. @param list<string> $moduleKeys @return list<string> */
    public static function installedBusinessDependents(
        PluginLockResolver $resolver,
        array $moduleKeys,
        TenantModuleStateQueries $installationStates,
    ): array {
        return self::businessDependents($resolver, $moduleKeys, $installationStates, false);
    }

    /** @param list<string> $moduleKeys @return list<string> */
    private static function businessDependents(
        PluginLockResolver $resolver,
        array $moduleKeys,
        TenantModuleStateQueries $installationStates,
        bool $activeOnly,
    ): array {
        $targets = array_fill_keys($moduleKeys, true);
        $dependents = [];
        $roots = [];
        foreach ($resolver->all() as $descriptor) {
            $roots += $descriptor->moduleRoots;
        }
        $installedKeys = Db::name('plugin_module')->alias('member')
            ->join('plugin_installation plugin', 'plugin.plugin_key=member.plugin_key')
            ->where('plugin.status', '<>', 'uninstalled')->distinct(true)->column('member.module_key');
        foreach ($installedKeys as $installedKey) {
            if (isset($roots[$installedKey]) || isset($targets[$installedKey])) {
                continue;
            }
            $path = ModuleHostLayoutFactory::pathLayout()->backendRelativePath(ModuleKey::fromString($installedKey));
            $root = dirname(rtrim(App::getRootPath(), '/')) . '/' . rtrim($path, '/');
            if (!is_file($root . '/module.json') || is_link($root)) {
                throw new PluginLifecycleException('MODULE_INSTALLED_MANIFEST_UNAVAILABLE', 'An installed Module outside the lock has no readable source declaration.');
            }
            $roots[$installedKey] = $root;
        }
        foreach ($roots as $dependentKey => $root) {
            if (isset($targets[$dependentKey])) {
                continue;
            }
            $manifest = (new ManifestLoader())->load($root);
            if (($manifest->data['key'] ?? null) !== $dependentKey) {
                throw new PluginLifecycleException('MODULE_INSTALLED_MANIFEST_UNAVAILABLE', 'Installed Module source identity does not match its ownership.');
            }
            foreach ((array) ($manifest->data['dependencies'] ?? []) as $dependency) {
                $dependencyKey = is_array($dependency) ? ($dependency['module_key'] ?? null) : null;
                if (!is_string($dependencyKey) || !isset($targets[$dependencyKey])) {
                    continue;
                }
                if ($activeOnly
                    ? $installationStates->activeInstallationCount([$dependentKey]) !== 0
                    : $installationStates->installedPackageModuleCount([$dependentKey]) !== 0) {
                    $dependents[] = $dependentKey . '->' . $dependencyKey;
                }
            }
        }
        sort($dependents, SORT_STRING);
        return array_values(array_unique($dependents));
    }

    /** @param list<string> $moduleKeys */
    public static function assertNoActiveBusinessDependents(
        PluginLockResolver $resolver,
        array $moduleKeys,
    ): void {
        if (self::activeBusinessDependents($resolver, $moduleKeys) !== []) {
            throw new PluginLifecycleException(
                'MODULE_DEPENDENT_INSTALLED',
                'An active Module has an explicit business dependency on this Bundle.',
            );
        }
    }

    /** Called inside the existing package lifecycle lock/transaction. */
    public static function assertNoOutstandingWorkflow(array $moduleKeys): void
    {
        if (in_array('peanut.workflow', $moduleKeys, true)
            && \PeanutAdmin\Modules\Workflow\Application\WorkflowLifecycleQueries::outstanding() > 0) {
            throw new PluginLifecycleException('WORKFLOW_LIFECYCLE_ACTIVE', 'Workflow instances or business effects must finish before uninstall.');
        }
    }
}
