<?php

declare(strict_types=1);

namespace app\common\infrastructure\authorization;

use app\platform\infrastructure\module\ThinkPhpModuleGovernanceProvider;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Authorization\TenantAuthorizationRepository;
use PeanutAdmin\Kernel\Menu\MenuDefinition;
use PeanutAdmin\Kernel\Menu\MenuCatalogRepository;
use PeanutAdmin\Kernel\Menu\MenuRegistry;
use PeanutAdmin\Modules\Identity\Authorization\CorePermissionCatalog;
use PeanutAdmin\Modules\Identity\Contract\TenantAuthorizationQuery;
use PeanutAdmin\Modules\Identity\Menu\MenuAdministrationService;
use PeanutAdmin\Modules\Identity\Module\TenantEntitlementService;
use PeanutAdmin\Modules\Identity\Auth\TenantAuthService;
use think\facade\Cache;
use think\facade\Db;

/**
 * Adapts the deployment catalog and tenant license to the Admin Shell menu payload.
 *
 * The bridge is read-only: Plugin installation owns deployment state, the platform
 * owns tenant licenses, and Core RBAC owns member Permission grants.
 */
final class CoreTenantModuleAdminBridge
{
    private const APPLICATION_PERMISSION_OWNER = 'peanut.admin';

    /** @var array<string,array{key:string,module_key:string|null,status:string}|null> */
    private array $permissionStateCache = [];

    /** @var array<int,array{modules:list<string>,permissions:list<string>}> */
    private array $entitlementProjectionCache = [];

    /** @var array<int,list<string>> */
    private array $tenantModuleKeysCache = [];

    /** @var list<string>|null */
    private ?array $deploymentModulesCache = null;

    /** @var list<array<string,mixed>>|null */
    private ?array $menuRecordsCache = null;

    public function __construct(
        private readonly ThinkPhpModuleGovernanceProvider $moduleGovernance,
        private readonly TenantAuthorizationRepository $authorization,
        private readonly MenuCatalogRepository $menuCatalog,
        private readonly TenantAuthorizationQuery $identityAuthorization,
        private readonly MenuAdministrationService $menuAdministration,
        private readonly TenantEntitlementService $entitlements,
    ) {}

    /** @return list<string> */
    public function managedMenuKeys(): array
    {
        return array_column($this->menuAdministration->records(), 'key');
    }

    /**
     * Returns the effective permission keys without constructing menu projections.
     * @return list<string>
     */
    public function permissionsOnly(TenantContext $tenantContext): array
    {
        if ($tenantContext->tenantId < 1
            || $tenantContext->memberId < 1) {
            return [];
        }

        $permissions = $this->basePermissions($tenantContext);
        return $permissions;
    }

    /** @return array{menu:list<array<string,mixed>>,permissions:list<string>} */
    public function accessData(mixed $tenantContext): array
    {
        $data = $this->accessProjection($tenantContext, false);
        return ['menu' => $data['menu'], 'permissions' => $data['permissions']];
    }

    /**
     * One read-only Admin Shell projection; all qualification inputs are read here.
     * @return array{menu:list<array<string,mixed>>,permissions:list<string>,registered_permissions:list<string>,registered_system_menu_permissions:list<string>,managed_menu_keys:list<string>,system_menu_rows:list<array<string,mixed>>,assignable_menu_records:list<array<string,mixed>>}
     */
    public function adminShellAccessData(TenantContext $tenantContext): array
    {
        return $this->accessProjection($tenantContext, true);
    }

    /** Local values never survive this call or bypass a later independent qualification. */
    private function accessProjection(mixed $tenantContext, bool $includeShell): array
    {
        if (!$tenantContext instanceof TenantContext
            || $tenantContext->tenantId < 1
            || $tenantContext->memberId < 1) {
            return ['menu' => [], 'permissions' => [], 'registered_permissions' => [],
                'registered_system_menu_permissions' => [], 'managed_menu_keys' => [],
                'system_menu_rows' => [], 'assignable_menu_records' => []];
        }

        $permissions = $this->basePermissions($tenantContext);
        $tenantOwner = $this->isTenantOwner($tenantContext);
        $deploymentModules = $this->cachedDeploymentModules();
        $entitlements = ($tenantOwner || $includeShell)
            ? $this->cachedEntitlementProjection($tenantContext->tenantId)
            : ['modules' => [], 'permissions' => []];
        $tenantModules = ($tenantOwner || $includeShell)
            ? $entitlements['modules']
            : $this->cachedTenantModuleKeys($tenantContext->tenantId);
        $installed = array_fill_keys($deploymentModules, true);
        $activeOwners = array_values(array_unique([
            self::APPLICATION_PERMISSION_OWNER,
            'instance.tenant',
            ...array_filter($tenantModules, static fn(string $key): bool => isset($installed[$key])),
        ]));
        $nativeRecords = $this->cachedMenuRecords();
        $definitions = $this->activeDefinitionsFromRecords($nativeRecords);
        $corePermissions = ($tenantOwner || $includeShell) ? $this->registeredCoreTenantPermissions() : [];
        $registeredPermissions = ($tenantOwner || $includeShell)
            ? $this->registeredLicensedSubset(
                array_values(array_intersect($this->registeredPermissionsForOwners($activeOwners, $corePermissions), $entitlements['permissions'])),
                $entitlements['modules'],
                installed: $installed,
            ) : [];
        $visible = (new MenuRegistry($definitions))->visible(
            'admin-web',
            static fn(string $moduleKey): bool => in_array($moduleKey, $deploymentModules, true),
            static fn(string $moduleKey): bool => in_array($moduleKey, $tenantModules, true),
            static fn(string $permission): bool => in_array($permission, $permissions, true),
        );
        $systemMenus = [];
        $data = [
            'menu' => $visible === [] ? [] : $this->renderServerMenuRecords(
                $visible,
                $systemMenus,
                $nativeRecords,
                $permissions,
            ),
            'permissions' => array_values(array_unique($permissions)),
        ];
        if ($includeShell) {
            $data['registered_permissions'] = $registeredPermissions;
            $data['registered_system_menu_permissions'] = $this->registeredLicensedSubset(
                array_values(array_intersect(
                    $this->registeredSystemMenuPermissionsForOwners($activeOwners, $corePermissions, $nativeRecords),
                    $entitlements['permissions'],
                )),
                $entitlements['modules'],
                installed: $installed,
            );
            $data['managed_menu_keys'] = array_column($nativeRecords, 'key');
            $data['system_menu_rows'] = $systemMenus;
            $assignable = (new MenuRegistry($definitions))->visible(
                'admin-web',
                static fn(string $moduleKey): bool => isset($installed[$moduleKey]),
                static fn(string $moduleKey): bool => in_array($moduleKey, $tenantModules, true),
                static fn(string $permission): bool => in_array($permission, $entitlements['permissions'], true),
            );
            $data['assignable_menu_records'] = $this->renderServerMenuRecords($assignable, $systemMenus, $nativeRecords, null);
        }
        return $data;
    }

    /** @return list<array<string,mixed>> */
    public function assignableMenuRecords(int $tenantId): array
    {
        if ($tenantId < 1) {
            return [];
        }
        $qualification = $this->moduleGovernance->qualification();
        $deploymentModules = array_map(
            static fn($module): string => $module->moduleKey,
            $qualification->installedModules(),
        );
        $tenantModules = $this->entitlements->moduleKeys($tenantId);
        $permissions = $this->entitlements->permissionKeys($tenantId);
        $visible = (new MenuRegistry($this->menuCatalog->activeDefinitions('tenant')))->visible(
            'admin-web',
            static fn(string $moduleKey): bool => in_array($moduleKey, $deploymentModules, true),
            static fn(string $moduleKey): bool => in_array($moduleKey, $tenantModules, true),
            static fn(string $permission): bool => in_array($permission, $permissions, true),
        );

        return $this->serverMenuRecords($visible);
    }

    /** @return list<string> */
    public function registeredPermissions(int $tenantId): array
    {
        if ($tenantId < 1) {
            return [];
        }
        $qualification = $this->moduleGovernance->qualification();
        $installed = array_fill_keys(array_map(
            static fn($module): string => $module->moduleKey,
            $qualification->installedModules(),
        ), true);
        $active = array_values(array_unique([
            self::APPLICATION_PERMISSION_OWNER,
            'instance.tenant',
            ...array_filter(
                $this->entitlements->moduleKeys($tenantId),
                static fn(string $moduleKey): bool => isset($installed[$moduleKey]),
            ),
        ]));
        return $this->registeredPermissionSubset($tenantId, $this->registeredPermissionsForOwners($active, $this->registeredCoreTenantPermissions()));
    }

    /** A decision checks only its permission owner; menu projections still enumerate the catalog. */
    public function registeredPermission(int $tenantId, string $permission): bool
    {
        if ($tenantId < 1 || $permission === '') {
            return false;
        }
        if (!in_array($permission, $this->entitlements->permissionKeys($tenantId), true)) {
            return false;
        }
        $state = $this->identityAuthorization->permissionStates([$permission])[$permission] ?? null;
        if (($state['key'] ?? null) !== $permission || ($state['status'] ?? null) !== 'active') {
            return false;
        }
        $owner = $state['module_key'] ?? null;
        if ($owner === 'core') {
            return in_array($permission, CorePermissionCatalog::TENANT, true);
        }
        if (in_array($owner, [self::APPLICATION_PERMISSION_OWNER, 'instance.tenant'], true)) {
            return true;
        }
        if (!is_string($owner) || $owner === '') {
            return false;
        }
        if (!in_array($owner, $this->entitlements->moduleKeys($tenantId), true)) {
            return false;
        }
        $this->moduleGovernance->registry()->requireInstalled($owner, false);
        return true;
    }

    /** One admitted read shares its versioned effective grants across endpoint and business decisions.
     * @param list<string> $permissions
     * @return array{registered:list<string>,owned:list<string>}
     */
    public function permissionInputs(TenantContext $context, array $permissions): array
    {
        if (TenantAuthService::authenticatedRead($context) !== null) {
            $license = $this->entitlements->license($context->tenantId, context: $context);
            // Time passing can change the commercial ceiling without a database write.
            $key = implode(':', ['identity:permission-snapshot:v1', $context->tenantId, $context->memberId,
                $context->authorizationRevision, $context->tenantAuthorizationRevision, $license['state'] ?? 'missing']);
            $snapshot = Cache::remember($key,
                fn(): array => $this->identityAuthorization->permissionSnapshot($context), 300);
            $states = array_intersect_key($snapshot, array_fill_keys($permissions, true));
        } else {
            $states = $this->identityAuthorization->permissionGrants($context, $permissions);
        }
        $owned = array_keys($states);
        return [
            'registered' => $this->registeredLicensedSubset($owned, null, $states),
            'owned' => $owned,
        ];
    }

    /** @param list<string> $permissions @return list<string> */
    public function registeredPermissionSubset(int $tenantId, array $permissions): array
    {
        if ($tenantId < 1 || $permissions === []) {
            return [];
        }
        $entitlements = $this->entitlements->permissionProjection($tenantId);
        return $this->registeredLicensedSubset(
            array_values(array_intersect($permissions, $entitlements['permissions'])),
            $entitlements['modules'],
        );
    }

    /** Null module selection is used only with this call's already licensed member grants.
     * @param list<string> $permissions @param list<string>|null $licensedModules
     * @param array<string,array{key:string,module_key:string|null,status:string}>|null $states
     * @param array<string,true>|null $installed Qualified by installedModules in this same projection.
     * @return list<string>
     */
    private function registeredLicensedSubset(array $permissions, ?array $licensedModules = null, ?array $states = null, ?array $installed = null): array
    {
        if ($permissions === []) {
            return [];
        }
        $states ??= $this->cachedPermissionStates(array_values(array_unique($permissions)));
        $owners = [];
        $registered = [];
        foreach ($permissions as $permission) {
            $state = $states[$permission] ?? null;
            if (($state['key'] ?? null) !== $permission || ($state['status'] ?? null) !== 'active') {
                continue;
            }
            $owner = $state['module_key'];
            if (($owner === 'core' && in_array($permission, CorePermissionCatalog::TENANT, true))
                || in_array($owner, [self::APPLICATION_PERMISSION_OWNER, 'instance.tenant'], true)) {
                $registered[] = $permission;
                continue;
            }
            if ($owner === null || $owner === ''
                || ($licensedModules !== null && !in_array($owner, $licensedModules, true))) {
                continue;
            }
            if ($installed !== null) {
                if (isset($installed[$owner])) {
                    $registered[] = $permission;
                }
                continue;
            }
            if (!array_key_exists($owner, $owners)) {
                try {
                    $this->moduleGovernance->registry()->requireInstalled($owner, false);
                    $owners[$owner] = true;
                } catch (\PeanutAdmin\Kernel\Module\ModuleException $exception) {
                    if (!in_array($exception->errorCode, ['MODULE_NOT_INSTALLED', 'MODULE_INSTALLATION_FAILED'], true)) {
                        throw $exception;
                    }
                    $owners[$owner] = false;
                }
            }
            if ($owners[$owner]) {
                $registered[] = $permission;
            }
        }
        return array_values(array_unique($registered));
    }

    /** @return list<string> */
    public function registeredSystemMenuPermissions(int $tenantId): array
    {
        if ($tenantId < 1) {
            return [];
        }
        $qualification = $this->moduleGovernance->qualification();
        $installed = array_fill_keys(array_map(
            static fn($module): string => $module->moduleKey,
            $qualification->installedModules(),
        ), true);
        $active = array_fill_keys(array_values(array_unique([
            self::APPLICATION_PERMISSION_OWNER,
            'instance.tenant',
            ...array_filter(
                $this->entitlements->moduleKeys($tenantId),
                static fn(string $moduleKey): bool => isset($installed[$moduleKey]),
            ),
        ])), true);
        return $this->registeredPermissionSubset($tenantId, $this->registeredSystemMenuPermissionsForOwners(array_keys($active), $this->registeredCoreTenantPermissions()));
    }

    /** Read current grants without retaining them beyond this projection. @return list<string> */
    public function basePermissions(TenantContext $tenantContext): array
    {
        // Identity's effective set already includes application grants and the license ceiling.
        return $this->authorization->permissions(
            $tenantContext->tenantId,
            $tenantContext->memberId,
        )->keys();
    }

    /** @param list<string> $active @param list<string> $corePermissions @return list<string> */
    private function registeredPermissionsForOwners(array $active, array $corePermissions): array
    {
        return array_values(array_unique([
            ...$this->identityAuthorization->registeredPermissionKeys($active),
            ...$corePermissions,
        ]));
    }

    /** @param list<string> $activeOwners @param list<string> $corePermissions @param list<array<string,mixed>>|null $menuRecords @return list<string> */
    private function registeredSystemMenuPermissionsForOwners(array $activeOwners, array $corePermissions, ?array $menuRecords = null): array
    {
        $active = array_fill_keys($activeOwners, true);
        $permissions = [];
        if ($menuRecords !== null) {
            $menuPermissions = [];
            foreach ($menuRecords as $row) {
                if (($row['scope'] ?? null) !== 'tenant'
                    || ($row['status'] ?? null) !== 'active'
                    || (int) ($row['is_disable'] ?? 0) !== 0) {
                    continue;
                }
                $key = $row['instance_permission'] ?? $row['required_permission'] ?? null;
                if (is_string($key) && $key !== '') {
                    $menuPermissions[] = $key;
                }
            }
            $menuPermissions = array_values(array_unique($menuPermissions));
        } else {
            $menuPermissions = array_map('strval', Db::table('pa_system_menu')
                ->alias('menu')
                ->join('pa_permission permission', 'permission.id = COALESCE(menu.instance_permission_id, menu.required_permission_id)')
                ->where('menu.scope', 'tenant')
                ->where('menu.status', 'active')
                ->where('menu.is_disable', 0)
                ->distinct(true)
                ->column('permission.key'));
        }
        $states = $this->cachedPermissionStates($menuPermissions);
        $registeredCorePermissions = array_fill_keys(
            $corePermissions,
            true,
        );
        foreach ($menuPermissions as $permission) {
            $state = $states[$permission] ?? null;
            $moduleKey = $state['module_key'] ?? null;
            if (str_starts_with($permission, 'core.')) {
                if (!isset($registeredCorePermissions[$permission])
                    || $moduleKey !== 'core'
                    || ($state['status'] ?? null) !== 'active') {
                    continue;
                }
                $permissions[] = $permission;
                continue;
            }
            if ($moduleKey !== null && $moduleKey !== '') {
                if (($state['status'] ?? null) !== 'active' || !isset($active[$moduleKey])) {
                    continue;
                }
            }
            $permissions[] = $permission;
        }

        return array_values(array_unique($permissions));
    }

    /** @return list<string> */
    private function registeredCoreTenantPermissions(): array
    {
        $permissionKeys = CorePermissionCatalog::TENANT;
        $states = $this->cachedPermissionStates($permissionKeys);
        $registered = [];
        foreach ($permissionKeys as $permission) {
            $state = $states[$permission] ?? null;
            if (($state['key'] ?? null) === $permission
                && ($state['module_key'] ?? null) === 'core'
                && ($state['status'] ?? null) === 'active') {
                $registered[] = $permission;
            }
        }
        return $registered;
    }

    /**
     * @param list<string> $keys
     * @return array<string,array{key:string,module_key:string|null,status:string}>
     */
    private function cachedPermissionStates(array $keys): array
    {
        $missing = [];
        foreach ($keys as $key) {
            if (!array_key_exists($key, $this->permissionStateCache)) {
                $missing[] = $key;
            }
        }
        if ($missing !== []) {
            $fetched = $this->identityAuthorization->permissionStates(array_values(array_unique($missing)));
            foreach ($missing as $key) {
                $this->permissionStateCache[$key] = $fetched[$key] ?? null;
            }
        }
        $result = [];
        foreach ($keys as $key) {
            if (($this->permissionStateCache[$key] ?? null) !== null) {
                $result[$key] = $this->permissionStateCache[$key];
            }
        }
        return $result;
    }

    /** @return array{modules:list<string>,permissions:list<string>} */
    private function cachedEntitlementProjection(int $tenantId): array
    {
        if (!isset($this->entitlementProjectionCache[$tenantId])) {
            $projection = $this->entitlements->permissionProjection($tenantId);
            $this->entitlementProjectionCache[$tenantId] = $projection;
            $this->tenantModuleKeysCache[$tenantId] = $projection['modules'];
        }
        return $this->entitlementProjectionCache[$tenantId];
    }

    /** @return list<string> */
    private function cachedTenantModuleKeys(int $tenantId): array
    {
        if (!isset($this->tenantModuleKeysCache[$tenantId])) {
            $this->tenantModuleKeysCache[$tenantId] = $this->entitlements->moduleKeys($tenantId);
        }
        return $this->tenantModuleKeysCache[$tenantId];
    }

    /** @return list<string> */
    private function cachedDeploymentModules(): array
    {
        if ($this->deploymentModulesCache === null) {
            $qualification = $this->moduleGovernance->qualification();
            $this->deploymentModulesCache = array_map(
                static fn($module): string => $module->moduleKey,
                $qualification->installedModules(),
            );
        }
        return $this->deploymentModulesCache;
    }

    /** @return list<array<string,mixed>> */
    private function cachedMenuRecords(): array
    {
        return $this->menuRecordsCache ??= $this->menuAdministration->records();
    }

    /**
     * @param list<array<string,mixed>> $records
     * @return list<MenuDefinition>
     */
    private function activeDefinitionsFromRecords(array $records): array
    {
        $definitions = [];
        foreach ($records as $row) {
            if (($row['scope'] ?? null) !== 'tenant' || ($row['status'] ?? null) !== 'active') {
                continue;
            }
            $rawClientKeys = $row['client_keys_json'] ?? ($row['client_keys'] ?? []);
            $clientKeys = is_array($rawClientKeys) ? $rawClientKeys : json_decode((string) $rawClientKeys, true);
            $definitions[] = new MenuDefinition(
                key: (string) $row['key'],
                moduleKey: (string) $row['module_key'],
                scope: (string) $row['scope'],
                parentKey: $row['parent_key'] === null ? null : (string) $row['parent_key'],
                type: (string) $row['type'],
                name: (string) $row['name'],
                routeName: $row['route_name'] === null ? null : (string) $row['route_name'],
                routePath: $row['route_path'] === null ? null : (string) $row['route_path'],
                componentKey: $row['component_key'] === null ? null : (string) $row['component_key'],
                requiredPermission: ($row['instance_permission'] ?? null) !== null
                    ? (string) $row['instance_permission']
                    : ($row['required_permission'] === null ? null : (string) $row['required_permission']),
                clientKeys: is_array($clientKeys) ? array_values(array_map('strval', $clientKeys)) : [],
                sortOrder: (int) ($row['sort_order'] ?? 0),
                icon: $row['icon'] === null ? null : (string) $row['icon'],
            );
        }
        return $definitions;
    }

    /**
     * @param list<MenuDefinition> $definitions
     * @return list<array<string,mixed>>
     */
    private function serverMenuRecords(array $definitions, ?array $permissions = null): array
    {
        if ($definitions === []) {
            return [];
        }
        return $this->renderServerMenuRecords(
            $definitions,
            [],
            $this->menuAdministration->records(),
            $permissions,
        );
    }

    /** @param list<MenuDefinition> $definitions @param list<array<string,mixed>> $legacy @param list<array<string,mixed>> $nativeRecords */
    private function renderServerMenuRecords(array $definitions, array $legacy, array $nativeRecords, ?array $permissions): array
    {
        if ($definitions === []) {
            return [];
        }
        $nativeRows = array_column($nativeRecords, null, 'key');
        $legacyById = array_column($legacy, null, 'id');
        $legacyCounts = array_count_values(array_column($legacy, 'menu_key'));
        $presentations = [];
        $ids = [];
        foreach ($definitions as $definition) {
            if (!isset($nativeRows[$definition->key]) || $nativeRows[$definition->key]['status'] !== 'active') {
                continue;
            }
            $ids[$definition->key] = (int) $nativeRows[$definition->key]['id'];
            $matches = array_values(array_filter($legacy, static fn(array $row): bool => $row['menu_key'] === $definition->key));
            // Ambiguous legacy identities are preserved, never guessed or merged.
            if (count($matches) === 1) {
                $presentations[$definition->key] = $this->menuAdministration->legacyPresentationOverrides($matches[0], $legacyById);
            }
        }

        $records = [];
        foreach ($definitions as $definition) {
            if (!isset($ids[$definition->key])) {
                continue;
            }
            $presentation = $presentations[$definition->key] ?? [];
            $instancePermission = $nativeRows[$definition->key]['instance_permission'] ?? null;
            $records[$definition->key] = [
                'id' => $ids[$definition->key],
                'pid' => array_key_exists('parent_key', $presentation)
                    ? (int) $presentation['pid'] : ($ids[$definition->parentKey] ?? 0),
                'parent_key' => array_key_exists('parent_key', $presentation) ? $presentation['parent_key'] : $definition->parentKey,
                'type' => $definition->type === 'group' ? 'M' : 'C',
                'name' => $presentation['name'] ?? $definition->name,
                'icon' => $presentation['icon'] ?? $definition->icon ?? '',
                'sort' => $presentation['sort'] ?? -$definition->sortOrder,
                'perms' => $instancePermission ?? $presentation['perms'] ?? $definition->requiredPermission ?? '',
                'paths' => $presentation['paths'] ?? $definition->routePath ?? '',
                'component' => $definition->componentKey ?? '',
                'is_cache' => $presentation['is_cache'] ?? (int) $nativeRows[$definition->key]['is_cache'],
                'is_show' => $presentation['is_show'] ?? (int) $nativeRows[$definition->key]['is_show'],
                'is_disable' => $presentation['is_disable'] ?? (int) $nativeRows[$definition->key]['is_disable'],
                'module_key' => $definition->moduleKey,
                'required_permission' => $instancePermission ?? $presentation['perms'] ?? $definition->requiredPermission,
                'menu_key' => $definition->key,
                'children' => [],
            ];
        }

        $effective = $records;
        foreach ($legacy as $row) {
            $key = $row['menu_key'];
            // A denied or retired native node must never re-enter through its legacy row.
            if (isset($nativeRows[$key]) || ($legacyCounts[$key] ?? 0) !== 1 || !in_array($row['type'], ['M', 'C'], true)) {
                continue;
            }
            $effective[$key] = [
                'parent_key' => $row['parent_key'] ?? ($legacyById[(int) $row['pid']]['menu_key'] ?? null),
                'perms' => (string) $row['perms'],
                'is_disable' => (int) $row['is_disable'],
            ];
        }
        return array_values(array_filter($records, fn(array $row): bool =>
            $this->effectivePresentationVisible($row['menu_key'], $effective, $permissions)));
    }

    /** The rendered parent chain, disable flags and instance references are also the visibility input. */
    private function effectivePresentationVisible(string $key, array $records, ?array $permissions): bool
    {
        $seen = [];
        while (true) {
            if (isset($seen[$key]) || !isset($records[$key])) {
                return false;
            }
            $seen[$key] = true;
            $row = $records[$key];
            if ((int) $row['is_disable'] !== 0) {
                return false;
            }
            $permission = (string) $row['perms'];
            if ($permissions !== null && $permission !== '' && !in_array($permission, $permissions, true)) {
                return false;
            }
            if ($row['parent_key'] === null) {
                return true;
            }
            $key = $row['parent_key'];
        }
    }

    private function isTenantOwner(TenantContext $context): bool
    {
        return $this->identityAuthorization->isTenantOwner($context);
    }
}
