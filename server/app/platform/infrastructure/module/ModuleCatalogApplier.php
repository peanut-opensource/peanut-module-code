<?php

declare(strict_types=1);

namespace app\platform\infrastructure\module;

use app\platform\exception\module\ModuleLifecycleException;
use DateTimeImmutable;
use DateTimeZone;
use PeanutAdmin\Modules\Identity\Authorization\ModuleAuthorizationCatalogSynchronizer;
use PeanutAdmin\Modules\Identity\Authorization\CatalogLifecycleService;
use PeanutAdmin\Modules\Identity\Menu\MenuCatalogSynchronizer;
use PeanutAdmin\Kernel\Menu\MenuCatalogRepository;
use PeanutAdmin\Kernel\Module\CompiledModuleRegistry;
use PeanutAdmin\Kernel\Module\ManifestDocument;
use PeanutAdmin\Modules\Settings\Service\SettingCatalogService;
use PeanutAdmin\Kernel\Module\ModuleException;
use think\facade\Db;

/** The single application entry point for applying, retiring, and purging Module catalog contributions. */
final readonly class ModuleCatalogApplier
{
    public const AUTHORIZATION_CATALOG_OWNER = 'peanut.identity';

    public function __construct(
        private SettingCatalogService $settings,
        private ModuleAuthorizationCatalogSynchronizer $authorization,
        private MenuCatalogRepository $menuCatalog,
        private CatalogLifecycleService $identityLifecycle,
    ) {}

    /**
     * @param null|list<string> $moduleKeys Null applies the complete compiled registry; a list applies only that scope.
     * @return array{operation:string,modules:list<string>,catalog_revision:string,changes:array{menus:int,permissions:int,settings:int,reference_codes:int}}
     */
    public function apply(CompiledModuleRegistry $registry, ?array $moduleKeys = null, bool $invalidate = true, bool $refreshActiveIdentities = false): array
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
                throw new ModuleLifecycleException('MODULE_NOT_REGISTERED', 'Module is not present in the compiled registry.');
            }
        }

        $selected = array_intersect_key($manifests, array_fill_keys($selectedKeys, true));
        $compiledScope = $this->scope($registry, $selected);
        $before = $this->catalogRevision();
        $identityChanged = false;
        $after = Db::transaction(function () use ($compiledScope, $fullRegistry, $registry, $selected, $selectedKeys, $before, $invalidate, $refreshActiveIdentities, &$identityChanged): string {
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
            } catch (ModuleException $exception) {
                // Keep the deployment-facing failure contract; the owner never exposes its storage.
                throw new ModuleLifecycleException($exception->errorCode, $exception->getMessage());
            }

            $mutations = new ModuleCatalogMutationRepository($this->settings, $this->identityLifecycle);
            $this->identityLifecycle->retireMissing($selected, false);
            if ($fullRegistry) {
                $absent = array_values(array_diff($mutations->activeModuleKeys(), $selectedKeys));
                if ($absent !== []) {
                    $this->retire($absent, false);
                }
            }
            $identityChanged = $refreshActiveIdentities && $this->identityLifecycle->refreshActiveCatalogIdentities($selected) > 0;
            $after = $this->catalogRevision();
            if ($invalidate && ($identityChanged || !hash_equals($before, $after))) {
                $this->authorization->invalidateTenantAuthorization(['core', ...$selectedKeys]);
            }
            return $after;
        });

        return [
            'operation' => !$identityChanged && hash_equals($before, $after) ? 'unchanged' : 'synced',
            'modules' => $selectedKeys,
            'catalog_revision' => $after,
            'changes' => $this->activeCounts($selectedKeys),
        ];
    }

    /** @param list<string> $moduleKeys */
    public function retire(array $moduleKeys, bool $invalidate = true): void
    {
        Db::transaction(function () use ($moduleKeys, $invalidate): void {
            $this->identityLifecycle->retire($moduleKeys, false);
            $this->settings->retire($moduleKeys);
            if ($invalidate) {
                $this->authorization->invalidateTenantAuthorization($moduleKeys);
            }
        });
    }

    /** @param list<string> $moduleKeys */
    public function purge(array $moduleKeys): void
    {
        Db::transaction(function () use ($moduleKeys): void {
            $this->settings->purge($moduleKeys);
            $this->identityLifecycle->purge($moduleKeys, false);
            $this->authorization->invalidateTenantAuthorization($moduleKeys);
        });
    }

    /** @param list<string> $moduleKeys @return array{removed:list<array<string,mixed>>,preserved:list<array<string,mixed>>,blockers:list<array<string,mixed>>} */
    public function plan(array $moduleKeys, bool $purge): array
    {
        return (new ModuleCatalogMutationRepository($this->settings, $this->identityLifecycle))->plan($moduleKeys, $purge);
    }

    /** Check the locked owner’s catalog shape; fresh installation can precede Identity activation. */
    public function authorizationCatalogSchemaReady(ManifestDocument $owner): bool
    {
        if (($owner->data['key'] ?? null) !== self::AUTHORIZATION_CATALOG_OWNER) {
            return false;
        }
        $ownedTables = array_fill_keys($owner->data['database']['owned_tables'] ?? [], true);
        // Shared read/write projections of the Identity permission, API and menu catalogs.
        $endpoints = ['id', 'method', 'path', 'audience', 'module_key', 'access_mode', 'status',
            'default_permission_keys', 'prerequisite_permission_keys', 'created_at', 'updated_at', 'retired_at'];
        $menus = ['id', 'key', 'module_key', 'scope', 'parent_key', 'type', 'name', 'route_name', 'route_path',
            'component_key', 'icon', 'sort_order', 'required_permission_id', 'client_keys_json', 'status',
            'manifest_digest', 'created_at', 'updated_at', 'upstream_defaults_json', 'menu_conflict_json',
            'is_show', 'is_cache', 'is_disable'];
        $permissions = ['id', 'key', 'module_key', 'type', 'name', 'risk_level', 'status', 'manifest_version',
            'created_at', 'updated_at', 'retired_at'];
        $required = match (true) {
            isset($ownedTables['pa_system_api']) => [
                'system_api' => $endpoints,
                'system_menu' => [...$menus, 'source'],
                'permission' => [...$permissions, 'revision', 'api_configured'],
            ],
            isset($ownedTables['pa_api_endpoint'], $ownedTables['pa_menu_definition'], $ownedTables['pa_permission_api_config']) => [
                'api_endpoint' => $endpoints,
                'menu_definition' => $menus,
                'permission' => $permissions,
                'permission_api_config' => ['permission_id', 'revision', 'configured', 'updated_at'],
                'protected_resource' => ['id', 'key', 'module_key', 'name', 'ownership', 'provider_key', 'status',
                    'manifest_version', 'manifest_digest', 'created_at', 'updated_at', 'retired_at'],
                'target_type' => ['id', 'key', 'module_key', 'name', 'resolver_key', 'catalog_provider_key', 'id_format',
                    'status', 'manifest_version', 'manifest_digest', 'created_at', 'updated_at'],
                'data_condition_definition' => ['id', 'key', 'module_key', 'category', 'target_mode', 'config_schema_json',
                    'status', 'manifest_version', 'manifest_digest', 'created_at', 'updated_at'],
                'resource_operation' => ['id', 'protected_resource_id', 'operation', 'access_mode', 'target_cardinality',
                    'permission_match', 'audit_level', 'status', 'manifest_digest', 'created_at', 'updated_at'],
                'resource_operation_permission' => ['id', 'resource_operation_id', 'permission_id', 'sort_order'],
                'resource_operation_target_type' => ['id', 'resource_operation_id', 'target_type_id', 'target_role',
                    'input_mode', 'policy_selection_permission_id', 'status'],
                'resource_operation_condition' => ['id', 'resource_operation_id', 'condition_definition_id',
                    'selector_resource_key', 'selector_resource_key_norm', 'status'],
            ],
            default => [],
        };
        if ($required === []) {
            return false;
        }
        $required['permission_api'] = ['id', 'permission_id', 'api_id', 'created_at'];
        $required['tenant'] = ['authorization_revision', 'revision', 'updated_at'];

        $prefix = (string) Db::connect()->getConfig('prefix');
        $missing = [];
        foreach ($required as $table => $fields) {
            $missing[$prefix . $table] = array_fill_keys($fields, true);
        }
        $columns = Db::table('information_schema.COLUMNS')->alias('catalog_column')
            ->join('information_schema.TABLES catalog_table', 'catalog_table.TABLE_SCHEMA=catalog_column.TABLE_SCHEMA AND catalog_table.TABLE_NAME=catalog_column.TABLE_NAME')
            ->where('catalog_column.TABLE_SCHEMA', Db::raw('DATABASE()'))
            ->where('catalog_table.TABLE_TYPE', 'BASE TABLE')
            ->whereIn('catalog_column.TABLE_NAME', array_keys($missing))
            ->field('catalog_column.TABLE_NAME as table_name,catalog_column.COLUMN_NAME as column_name')->select()->toArray();
        foreach ($columns as $column) {
            unset($missing[$column['table_name']][$column['column_name']]);
        }
        return array_filter($missing) === [];
    }

    public function catalogRevision(): string
    {
        $rows = [];
        $rows['pa_permission'] = $this->authorization->revisionRows();
        $rows['api_permissions'] = (new \PeanutAdmin\Modules\Identity\Authorization\ApiPermissionCatalog())->revisionRows();
        $rows['pa_system_menu'] = (new MenuCatalogSynchronizer($this->menuCatalog))->revisionRows();
        $rows['pa_setting_definition'] = $this->settings->revisionRows();
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
    public function activatePackageDeployment(array $manifests, bool $upgrade, string $now, bool $invalidate = true): void
    {
        $this->identityLifecycle->activatePackageDeployment($manifests, $upgrade, $now, $invalidate);
    }

    /** @param list<string> $moduleKeys */
    public function markPackageDeploymentFailed(array $moduleKeys, string $errorCode, string $now): void
    {
        $this->identityLifecycle->markPackageDeploymentFailed($moduleKeys, $errorCode, $now);
    }

    /** @param list<string> $moduleKeys */
    public function disableActivePackageModules(array $moduleKeys, bool $invalidate = true): void
    {
        $this->identityLifecycle->disableActivePackageModules($moduleKeys, $invalidate);
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
        $counts['reference_codes'] = 0;
        return $counts;
    }
}
