<?php

declare(strict_types=1);

namespace app\platform\policy\module;

use app\platform\exception\module\ModuleLifecycleException;
use app\platform\infrastructure\module\ModuleLockResolver;
use PeanutAdmin\Kernel\Module\ManifestDocument;
use PeanutAdmin\Kernel\Module\ManifestLoader;
use PeanutAdmin\Modules\Identity\Contract\TenantModuleStateQueries;
use app\common\infrastructure\module\ModuleHostLayoutFactory;
use PeanutAdmin\Kernel\Module\ModuleKey;
use think\facade\App;

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
            throw new ModuleLifecycleException(
                'MODULE_LIFECYCLE_PROTECTED',
                'A protected core Module cannot be disabled, retired, or purged.',
            );
        }
    }

    /** @param list<string> $moduleKeys @return list<string> */
    public static function activeBusinessDependents(
        ModuleLockResolver $resolver,
        array $moduleKeys,
        TenantModuleStateQueries $installationStates = new TenantModuleStateQueries(),
    ): array {
        return self::businessDependents($resolver, $moduleKeys, $installationStates, true);
    }

    /** Includes disabled and failed installations until their package has actually been removed.
     * @param list<string> $moduleKeys @param array<string,ManifestDocument> $verifiedTargets @return list<string>
     */
    public static function installedBusinessDependents(
        ModuleLockResolver $resolver,
        array $moduleKeys,
        TenantModuleStateQueries $installationStates,
        array $verifiedTargets = [],
    ): array {
        return self::businessDependents($resolver, $moduleKeys, $installationStates, false, $verifiedTargets);
    }

    /** @param list<string> $moduleKeys @return list<string> */
    private static function businessDependents(
        ModuleLockResolver $resolver,
        array $moduleKeys,
        TenantModuleStateQueries $installationStates,
        bool $activeOnly,
        array $verifiedTargets = [],
    ): array {
        $targets = array_fill_keys($moduleKeys, true);
        $dependents = [];
        $roots = [];
        foreach ($resolver->all() as $descriptor) {
            $roots += $descriptor->moduleRoots;
        }
        foreach ($verifiedTargets as $key => $manifest) {
            if (!isset($targets[$key]) || !$manifest instanceof ManifestDocument || ($manifest->data['key'] ?? null) !== $key) {
                throw new ModuleLifecycleException('MODULE_INSTALLED_MANIFEST_UNAVAILABLE', 'Verified removal source does not match the target Module.');
            }
            $roots[$key] = $manifest->root;
        }
        $installedKeys = $installationStates->installedModuleKeys();
        foreach ($installedKeys as $installedKey) {
            if (isset($roots[$installedKey])) {
                continue;
            }
            $path = ModuleHostLayoutFactory::pathLayout()->backendRelativePath(ModuleKey::fromString($installedKey));
            $root = dirname(rtrim(App::getRootPath(), '/')) . '/' . rtrim($path, '/');
            if (!is_file($root . '/module.json') || is_link($root)) {
                throw new ModuleLifecycleException('MODULE_INSTALLED_MANIFEST_UNAVAILABLE', 'An installed Module outside the lock has no readable source declaration.');
            }
            $roots[$installedKey] = $root;
        }
        // Host pages can consume a Module without belonging to another Module.
        // Keep those native package requirements in the same lifecycle dependency check.
        $project = dirname(rtrim(App::getRootPath(), '/'));
        $webPath = $project . '/web/package.json';
        if (is_file($webPath)) {
            $web = json_decode((string) file_get_contents($webPath), true, 512, JSON_THROW_ON_ERROR);
            foreach ($moduleKeys as $key) {
                $manifest = (new ManifestLoader())->load($roots[$key])->data;
                if (isset($manifest['web_package'], $web['dependencies'][$manifest['web_package']])) {
                    $dependents[] = 'host:web->' . $key;
                }
            }
        }
        foreach ($roots as $dependentKey => $root) {
            if (isset($targets[$dependentKey])) {
                continue;
            }
            $manifest = (new ManifestLoader())->load($root);
            if (($manifest->data['key'] ?? null) !== $dependentKey) {
                throw new ModuleLifecycleException('MODULE_INSTALLED_MANIFEST_UNAVAILABLE', 'Installed Module source identity does not match its ownership.');
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
        ModuleLockResolver $resolver,
        array $moduleKeys,
    ): void {
        if (self::activeBusinessDependents($resolver, $moduleKeys) !== []) {
            throw new ModuleLifecycleException(
                'MODULE_DEPENDENT_INSTALLED',
                'An active Module has an explicit business dependency on this Module.',
            );
        }
    }

    /** Called inside the existing package lifecycle lock/transaction. */
    public static function assertNoOutstandingWorkflow(array $moduleKeys): void
    {
        if (in_array('peanut.workflow', $moduleKeys, true)
            && \PeanutAdmin\Modules\Workflow\Application\WorkflowLifecycleQueries::outstanding() > 0) {
            throw new ModuleLifecycleException('WORKFLOW_LIFECYCLE_ACTIVE', 'Workflow instances or business effects must finish before uninstall.');
        }
    }
}
