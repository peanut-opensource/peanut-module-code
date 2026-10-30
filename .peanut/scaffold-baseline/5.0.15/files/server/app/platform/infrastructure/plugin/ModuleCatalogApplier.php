<?php

declare(strict_types=1);

namespace app\platform\infrastructure\plugin;

use app\platform\exception\plugin\PluginLifecycleException;
use DateTimeImmutable;
use DateTimeZone;
use PeanutAdmin\Modules\Identity\Authorization\ModuleAuthorizationCatalogSynchronizer;
use PeanutAdmin\Modules\Identity\Authorization\CatalogLifecycleService;
use PeanutAdmin\Modules\Identity\Menu\MenuCatalogSynchronizer;
use PeanutAdmin\Kernel\Menu\MenuCatalogRepository;
use PeanutAdmin\Kernel\Module\CompiledModuleRegistry;
use PeanutAdmin\Kernel\Module\ManifestDocument;
use PeanutAdmin\Modules\Settings\Service\SettingCatalogService;
use PeanutAdmin\Modules\ReferenceCodes\Service\ReferenceCodeCatalogService;
use PeanutAdmin\Kernel\Module\ModuleException;
use think\facade\Db;

/** The single application entry point for applying, retiring, and purging Module catalog contributions. */
final readonly class ModuleCatalogApplier
{
    public function __construct(
        private SettingCatalogService $settings,
        private ModuleAuthorizationCatalogSynchronizer $authorization,
        private MenuCatalogRepository $menuCatalog,
        private ReferenceCodeCatalogService $referenceCodes,
        private CatalogLifecycleService $identityLifecycle,
    ) {}

    /**
     * @param null|list<string> $moduleKeys Null applies the complete compiled registry; a list applies only that scope.
     * @return array{operation:string,modules:list<string>,catalog_revision:string,changes:array{menus:int,permissions:int,settings:int,reference_codes:int}}
     */
    public function apply(CompiledModuleRegistry $registry, ?array $moduleKeys = null): array
    {
        $manifests = [];
        foreach ($registry->modules as $manifest) {
            $key = (string) ($manifest->data['key'] ?? '');
            $manifests[$key] = $manifest;
        }
        $fullRegistry = $moduleKeys === null;
        $selectedKeys = $fullRegistry ? array_keys($manifests) : array_values(array_unique($moduleKeys));
        sort($selectedKeys, SORT_STRING);
        foreach ($selectedKeys as $key) {
            if (!isset($manifests[$key])) {
                throw new PluginLifecycleException('MODULE_NOT_REGISTERED', 'Module is not present in the compiled registry.');
            }
        }

        $selected = array_intersect_key($manifests, array_fill_keys($selectedKeys, true));
        $compiledScope = $this->scope($registry, $selected);
        $before = $this->catalogRevision();
        Db::transaction(function () use ($compiledScope, $fullRegistry, $registry, $selected, $selectedKeys): void {
            $this->authorization->synchronize($compiledScope);
            (new \PeanutAdmin\Modules\Identity\Authorization\ApiPermissionCatalog())->synchronize(
                \app\common\services\authorization\GeneratedApiCatalog::load(dirname(__DIR__, 4)), $selectedKeys, $fullRegistry,
            );

            $menus = $fullRegistry
                ? $this->menuCatalog
                : new ScopedMenuCatalogRepository($this->menuCatalog, $selectedKeys);
            (new MenuCatalogSynchronizer($menus))->synchronize($fullRegistry ? $registry : $compiledScope);

            $now = new DateTimeImmutable(
                (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.v'),
                new DateTimeZone('UTC'),
            );
            try {
                $this->settings->synchronize($selected, $now);
                $this->referenceCodes->synchronize($selected, $now);
            } catch (ModuleException $exception) {
                // Keep the deployment-facing failure contract; the owner never exposes its storage.
                throw new PluginLifecycleException($exception->errorCode, $exception->getMessage());
            }

            $mutations = new ModuleCatalogMutationRepository($this->settings, $this->identityLifecycle);
            $mutations->retireMissing($selected);
            if ($fullRegistry) {
                $absent = array_values(array_diff($mutations->activeModuleKeys(), $selectedKeys));
                if ($absent !== []) {
                    $mutations->retire($absent);
                }
            }
        });

        $after = $this->catalogRevision();
        return [
            'operation' => hash_equals($before, $after) ? 'unchanged' : 'synced',
            'modules' => $selectedKeys,
            'catalog_revision' => $after,
            'changes' => $this->activeCounts($selectedKeys),
        ];
    }

    /** @param list<string> $moduleKeys */
    public function retire(array $moduleKeys): void
    {
        (new ModuleCatalogMutationRepository($this->settings, $this->identityLifecycle))->retire($moduleKeys);
    }

    /** @param list<string> $moduleKeys */
    public function purge(array $moduleKeys): void
    {
        (new ModuleCatalogMutationRepository($this->settings, $this->identityLifecycle))->purge($moduleKeys);
    }

    /** @param list<string> $moduleKeys @return array{removed:list<array<string,mixed>>,preserved:list<array<string,mixed>>,blockers:list<array<string,mixed>>} */
    public function plan(array $moduleKeys, bool $purge): array
    {
        return (new ModuleCatalogMutationRepository($this->settings, $this->identityLifecycle))->plan($moduleKeys, $purge);
    }

    /**
     * Complete native selected contributions, shared with the catalog writer.
     * @param array<string, ManifestDocument> $manifests
     */
    public function referenceCodePlan(array $manifests): array
    {
        return $this->referenceCodes->definitionPlan($manifests);
    }

    public function catalogRevision(): string
    {
        $rows = [];
        $rows['pa_permission'] = $this->authorization->revisionRows();
        $rows['api_permissions'] = (new \PeanutAdmin\Modules\Identity\Authorization\ApiPermissionCatalog())->revisionRows();
        $rows['pa_menu_definition'] = (new MenuCatalogSynchronizer($this->menuCatalog))->revisionRows();
        $rows['pa_setting_definition'] = $this->settings->revisionRows();
        $rows['pa_reference_code_set'] = $this->referenceCodes->revisionRows();
        return hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /** @param list<string> $moduleKeys */
    public function invalidateTenantAuthorization(array $moduleKeys): void
    {
        $this->authorization->invalidateTenantAuthorization($moduleKeys);
    }

    /** @param array<string,ManifestDocument> $manifests */
    public function beginPackageDeployment(array $manifests, bool $upgrade, string $now): void
    {
        $this->identityLifecycle->beginPackageDeployment($manifests, $upgrade, $now);
    }

    /** @param array<string,ManifestDocument> $manifests */
    public function activatePackageDeployment(array $manifests, bool $upgrade, string $now): void
    {
        $this->identityLifecycle->activatePackageDeployment($manifests, $upgrade, $now);
    }

    /** @param list<string> $moduleKeys */
    public function markPackageDeploymentFailed(array $moduleKeys, string $errorCode, string $now): void
    {
        $this->identityLifecycle->markPackageDeploymentFailed($moduleKeys, $errorCode, $now);
    }

    /** @param list<string> $moduleKeys */
    public function retirePackageModules(array $moduleKeys, string $now): void
    {
        $this->identityLifecycle->retirePackageModules($moduleKeys, $now);
    }

    /** @param list<string> $moduleKeys */
    public function disableActivePackageModules(array $moduleKeys): void
    {
        $this->identityLifecycle->disableActivePackageModules($moduleKeys);
    }

    /** @param list<string> $moduleKeys */
    public function markPackageModulesForRecovery(array $moduleKeys, string $marker, string $now): void
    {
        $this->identityLifecycle->markPackageModulesForRecovery($moduleKeys, $marker, $now);
    }

    /** @param list<string> $moduleKeys */
    public function finalizePackageRemoval(array $moduleKeys, bool $purge, string $now): void
    {
        $this->identityLifecycle->finalizePackageRemoval($moduleKeys, $purge, $now);
    }

    /** @param array<string,ManifestDocument> $manifests */
    private function scope(CompiledModuleRegistry $registry, array $manifests): CompiledModuleRegistry
    {
        $keys = array_fill_keys(array_keys($manifests), true);
        $menus = array_filter(
            $registry->menus,
            static fn(array $menu): bool => isset($keys[(string) ($menu['module_key'] ?? '')]),
        );
        return new CompiledModuleRegistry(
            array_values($manifests),
            array_filter($registry->targetTypeOwners, static fn(string $owner): bool => isset($keys[$owner])),
            array_filter($registry->ownedTableOwners, static fn(string $owner): bool => isset($keys[$owner])),
            $menus,
            hash('sha256', implode('|', array_map(static fn(ManifestDocument $manifest): string => $manifest->digest, $manifests))),
        );
    }

    /** @param list<string> $moduleKeys @return array{menus:int,permissions:int,settings:int,reference_codes:int} */
    private function activeCounts(array $moduleKeys): array
    {
        if ($moduleKeys === []) {
            return ['menus' => 0, 'permissions' => 0, 'settings' => 0, 'reference_codes' => 0];
        }
        $counts = [
            'menus' => (new MenuCatalogSynchronizer($this->menuCatalog))->activeCount($moduleKeys),
            'permissions' => $this->authorization->activeCount($moduleKeys),
        ];
        $counts['settings'] = $this->settings->activeCount($moduleKeys);
        $counts['reference_codes'] = $this->referenceCodes->activeCount($moduleKeys);
        return $counts;
    }
}
