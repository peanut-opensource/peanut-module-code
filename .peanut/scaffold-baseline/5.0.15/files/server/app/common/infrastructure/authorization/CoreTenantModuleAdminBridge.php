<?php

declare(strict_types=1);

namespace app\common\infrastructure\authorization;

use app\common\model\auth\SystemMenu;
use app\platform\infrastructure\module\ThinkPhpModuleGovernanceProvider;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Authorization\TenantAuthorizationRepository;
use PeanutAdmin\Kernel\Menu\MenuDefinition;
use PeanutAdmin\Kernel\Menu\MenuCatalogRepository;
use PeanutAdmin\Kernel\Menu\MenuRegistry;
use PeanutAdmin\Modules\Identity\Authorization\CorePermissionCatalog;
use PeanutAdmin\Modules\Identity\Contract\TenantAuthorizationQuery;
use PeanutAdmin\Modules\Identity\Menu\MenuAdministrationService;

/**
 * Adapts the Core Module/TenantModule catalog to the Admin Shell menu payload.
 *
 * The bridge is read-only: Plugin installation owns deployment state, the platform
 * owns TenantModule enablement, and Core RBAC owns member Permission grants.
 */
final readonly class CoreTenantModuleAdminBridge
{
    private const APPLICATION_PERMISSION_OWNER = 'peanut.admin';

    public function __construct(
        private ThinkPhpModuleGovernanceProvider $moduleGovernance,
        private TenantAuthorizationRepository $authorization,
        private MenuCatalogRepository $menuCatalog,
        private TenantAuthorizationQuery $identityAuthorization,
        private MenuAdministrationService $menuAdministration,
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
        if ($this->isTenantOwner($tenantContext)) {
            $permissions = array_values(array_unique([
                ...$permissions,
                ...$this->registeredPermissions($tenantContext->tenantId),
            ]));
        }
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
     * @return array{menu:list<array<string,mixed>>,permissions:list<string>,registered_permissions:list<string>,registered_system_menu_permissions:list<string>,managed_menu_keys:list<string>}
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
                'registered_system_menu_permissions' => [], 'managed_menu_keys' => []];
        }

        $permissions = $this->basePermissions($tenantContext);
        $tenantOwner = $this->isTenantOwner($tenantContext);
        $qualification = $this->moduleGovernance->qualification();
        $deploymentModules = array_map(
            static fn($module): string => $module->moduleKey,
            $qualification->installedModules(),
        );
        $tenantModules = $qualification->activeTenantModuleKeys($tenantContext->tenantId);
        $installed = array_fill_keys($deploymentModules, true);
        $activeOwners = array_values(array_unique([
            self::APPLICATION_PERMISSION_OWNER,
            ...array_filter($tenantModules, static fn(string $key): bool => isset($installed[$key])),
        ]));
        $corePermissions = ($tenantOwner || $includeShell) ? $this->registeredCoreTenantPermissions() : [];
        $registeredPermissions = ($tenantOwner || $includeShell)
            ? $this->registeredPermissionsForOwners($activeOwners, $corePermissions) : [];
        if ($tenantOwner) {
            $permissions = array_values(array_unique([...$permissions, ...$registeredPermissions]));
        }
        $definitions = $this->menuCatalog->activeDefinitions('tenant');
        $visible = (new MenuRegistry($definitions))->visible(
            'admin-web',
            static fn(string $moduleKey): bool => in_array($moduleKey, $deploymentModules, true),
            static fn(string $moduleKey): bool => in_array($moduleKey, $tenantModules, true),
            static fn(string $permission): bool => in_array($permission, $permissions, true),
        );
        $nativeRecords = ($includeShell || $visible !== []) ? $this->menuAdministration->records() : [];
        $data = [
            'menu' => $visible === [] ? [] : $this->renderServerMenuRecords(
                $visible,
                SystemMenu::order('id')->select()->toArray(),
                $nativeRecords,
                $permissions,
            ),
            'permissions' => array_values(array_unique($permissions)),
        ];
        if ($includeShell) {
            $data['registered_permissions'] = $registeredPermissions;
            $data['registered_system_menu_permissions'] = $this->registeredSystemMenuPermissionsForOwners($activeOwners, $corePermissions);
            $data['managed_menu_keys'] = array_column($nativeRecords, 'key');
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
        $tenantModules = $qualification->activeTenantModuleKeys($tenantId);
        $visible = (new MenuRegistry($this->menuCatalog->activeDefinitions('tenant')))->visible(
            'admin-web',
            static fn(string $moduleKey): bool => in_array($moduleKey, $deploymentModules, true),
            static fn(string $moduleKey): bool => in_array($moduleKey, $tenantModules, true),
            static fn(string $_permission): bool => true,
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
            ...array_filter(
                $qualification->activeTenantModuleKeys($tenantId),
                static fn(string $moduleKey): bool => isset($installed[$moduleKey]),
            ),
        ]));
        return $this->registeredPermissionsForOwners($active, $this->registeredCoreTenantPermissions());
    }

    /** A decision checks only its permission owner; menu projections still enumerate the catalog. */
    public function registeredPermission(int $tenantId, string $permission): bool
    {
        if ($tenantId < 1 || $permission === '') {
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
        if ($owner === self::APPLICATION_PERMISSION_OWNER) {
            return true;
        }
        if (!is_string($owner) || $owner === '') {
            return false;
        }
        $qualification = $this->moduleGovernance->qualification();
        if (!in_array($owner, $qualification->activeTenantModuleKeys($tenantId), true)) {
            return false;
        }
        $qualification->installedModule($owner);
        return true;
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
            ...array_filter(
                $qualification->activeTenantModuleKeys($tenantId),
                static fn(string $moduleKey): bool => isset($installed[$moduleKey]),
            ),
        ])), true);
        return $this->registeredSystemMenuPermissionsForOwners(array_keys($active), $this->registeredCoreTenantPermissions());
    }

    /** Read current grants without retaining them beyond this projection. @return list<string> */
    private function basePermissions(TenantContext $tenantContext): array
    {
        $permissions = array_values(array_unique([
            ...$this->authorization->permissions(
                $tenantContext->tenantId,
                $tenantContext->memberId,
            )->keys(),
            ...$this->applicationPermissions($tenantContext),
        ]));
        return $permissions;
    }

    /** @param list<string> $active @param list<string> $corePermissions @return list<string> */
    private function registeredPermissionsForOwners(array $active, array $corePermissions): array
    {
        return array_values(array_unique([
            ...$this->identityAuthorization->registeredPermissionKeys($active),
            ...$corePermissions,
        ]));
    }

    /** @param list<string> $activeOwners @param list<string> $corePermissions @return list<string> */
    private function registeredSystemMenuPermissionsForOwners(array $activeOwners, array $corePermissions): array
    {
        $active = array_fill_keys($activeOwners, true);
        $permissions = [];
        $menuPermissions = array_map('strval', SystemMenu::where('is_disable', 0)
            ->where('perms', '<>', '')->distinct(true)->column('perms'));
        $states = $this->identityAuthorization->permissionStates($menuPermissions);
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
    private function applicationPermissions(TenantContext $context): array
    {
        return $this->identityAuthorization->applicationPermissionKeys($context);
    }

    /** @return list<string> */
    private function registeredCoreTenantPermissions(): array
    {
        $permissionKeys = CorePermissionCatalog::TENANT;
        $states = $this->identityAuthorization->permissionStates($permissionKeys);
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
            SystemMenu::order('id')->select()->toArray(),
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
        $presentations = [];
        $ids = [];
        foreach ($definitions as $definition) {
            $ids[$definition->key] = (int) $nativeRows[$definition->key]['id'];
            $matches = array_values(array_filter($legacy, static fn(array $row): bool => $row['menu_key'] === $definition->key));
            // Ambiguous legacy identities are preserved, never guessed or merged.
            if (count($matches) === 1) {
                $presentations[$definition->key] = $matches[0];
            }
        }

        $records = [];
        $byKey = [];
        foreach ($definitions as $definition) {
            $byKey[$definition->key] = $definition;
        }
        foreach ($definitions as $definition) {
            // A denied legacy directory must also suppress newly contributed
            // pages which have no legacy row of their own.
            $ancestor = $definition;
            $seen = [];
            while ($ancestor !== null) {
                if (isset($seen[$ancestor->key])) {
                    continue 2;
                }
                $seen[$ancestor->key] = true;
                if ((int) $nativeRows[$ancestor->key]['is_disable'] !== 0) {
                    continue 2;
                }
                $directory = $presentations[$ancestor->key] ?? null;
                if ($directory !== null && !$this->legacyPresentationVisible($directory, $legacy, $permissions)) {
                    continue 2;
                }
                $ancestor = $byKey[$ancestor->parentKey] ?? null;
            }
            $presentation = $presentations[$definition->key] ?? null;
            if ($presentation !== null && !$this->legacyPresentationVisible($presentation, $legacy, $permissions)) {
                continue;
            }
            if ($presentation !== null && !$this->legacyCustomized($presentation, $legacy)) {
                $presentation = null;
            }
            $records[] = [
                'id' => $ids[$definition->key],
                'pid' => $presentation === null
                    ? ($ids[$definition->parentKey] ?? 0) : (int) $presentation['pid'],
                'parent_key' => $presentation === null ? $definition->parentKey : $presentation['parent_key'],
                'type' => $definition->type === 'group' ? 'M' : 'C',
                'name' => $presentation['name'] ?? $definition->name,
                'icon' => $presentation['icon'] ?? $definition->icon ?? '',
                'sort' => $presentation['sort'] ?? -$definition->sortOrder,
                'perms' => $definition->requiredPermission ?? '',
                'paths' => $presentation['paths'] ?? $definition->routePath ?? '',
                'component' => $definition->componentKey ?? '',
                'is_cache' => $presentation['is_cache'] ?? (int) $nativeRows[$definition->key]['is_cache'],
                'is_show' => $presentation['is_show'] ?? (int) $nativeRows[$definition->key]['is_show'],
                'is_disable' => 0,
                'module_key' => $definition->moduleKey,
                'required_permission' => $definition->requiredPermission,
                'menu_key' => $definition->key,
                'children' => [],
            ];
        }

        return $records;
    }

    private function legacyCustomized(array $row, array $rows): bool
    {
        $byId = array_column($rows, null, 'id');
        $seen = [];
        while (true) {
            $id = (int) $row['id'];
            if (isset($seen[$id]) || ($row['menu_conflict_json'] ?? null) !== null) {
                return true;
            }
            $seen[$id] = true;
            $baseline = $row['upstream_defaults_json'] ?? null;
            if (is_string($baseline)) {
                $baseline = json_decode($baseline, true, 512, JSON_THROW_ON_ERROR);
            }
            if (!is_array($baseline)) {
                return true;
            }
            foreach ($baseline as $field => $expected) {
                $current = $row[$field] ?? null;
                if (is_int($expected)) {
                    $current = (int) $current;
                }
                if ($current !== $expected) {
                    return true;
                }
            }
            $expectedParent = $byId[(int) ($baseline['pid'] ?? 0)]['menu_key'] ?? null;
            if ($row['parent_key'] !== $expectedParent) {
                return true;
            }
            $parent = (int) $row['pid'];
            if ($parent === 0 || !isset($byId[$parent])) {
                return false;
            }
            $row = $byId[$parent];
        }
    }

    /** Legacy presentation never grants a permission; changed restrictions remain effective. */
    private function legacyPresentationVisible(array $row, array $rows, ?array $permissions): bool
    {
        $byKey = array_column($rows, null, 'menu_key');
        $seen = [];
        while (true) {
            $id = (int) $row['id'];
            if (isset($seen[$id]) || (int) $row['is_disable'] !== 0) {
                return false;
            }
            $seen[$id] = true;
            $baseline = $row['upstream_defaults_json'] ?? null;
            if (is_string($baseline)) {
                $baseline = json_decode($baseline, true, 512, JSON_THROW_ON_ERROR);
            }
            $customPermission = (string) $row['perms'];
            if ($permissions !== null && $customPermission !== ''
                && ($baseline['perms'] ?? null) !== $customPermission
                && !in_array($customPermission, $permissions, true)) {
                return false;
            }
            $parent = $row['parent_key'];
            if ($parent === null || !isset($byKey[$parent])) {
                return true;
            }
            $row = $byKey[$parent];
        }
    }

    private function isTenantOwner(TenantContext $context): bool
    {
        return $this->identityAuthorization->isTenantOwner($context);
    }
}
