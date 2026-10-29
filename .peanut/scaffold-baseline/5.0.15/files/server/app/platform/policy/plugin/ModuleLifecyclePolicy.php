<?php

declare(strict_types=1);

namespace app\platform\policy\plugin;

use app\platform\exception\plugin\PluginLifecycleException;
use app\platform\infrastructure\plugin\PluginLockResolver;
use PeanutAdmin\Kernel\Module\ManifestDocument;
use PeanutAdmin\Kernel\Module\ManifestLoader;
use PeanutAdmin\Modules\Identity\Contract\TenantModuleStateQueries;

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
        foreach ($resolver->all() as $descriptor) {
            foreach ($descriptor->moduleRoots as $dependentKey => $root) {
                if (isset($targets[$dependentKey])) {
                    continue;
                }
                $manifest = (new ManifestLoader())->load($root);
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
