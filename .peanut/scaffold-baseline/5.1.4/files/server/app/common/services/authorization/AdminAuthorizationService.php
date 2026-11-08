<?php

declare(strict_types=1);

namespace app\common\services\authorization;

use app\common\infrastructure\authorization\CoreTenantModuleAdminBridge;
use app\common\contract\authorization\AdminAuthorizationQuery;
use app\common\contract\authorization\AuthorizedOperationFactory;
use app\common\contract\AdminPermissionPolicy;
use app\common\dto\authorization\AdminAccessData;
use app\common\dto\authorization\AdminPrincipal;
use app\common\dto\authorization\PermissionDecision;
use app\common\model\auth\SystemMenu;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Context\AuthorizationDecision;
use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;
use PeanutAdmin\Kernel\Context\RequestedTargetSet;
use PeanutAdmin\Modules\Identity\Contract\AdminDirectoryQuery;
use PeanutAdmin\Modules\Identity\Contract\TenantAuthorizationQuery;
use PeanutAdmin\Modules\Identity\Platform\InstanceControlPlanePolicy;
use app\common\validation\instance\InstanceToolAccessGuard;

/** Tenant Admin identity, RBAC and access projection service. */
final class AdminAuthorizationService implements AdminAuthorizationQuery, AuthorizedOperationFactory
{
    public function __construct(
        private readonly CoreTenantModuleAdminBridge $moduleAdmin,
        private readonly AdminPermissionPolicy $permissionPolicy,
        private readonly AdminDirectoryQuery $directory,
        private readonly TenantAuthorizationQuery $identityAuthorization,
        private readonly EndpointPermissionGrant $endpointGrant,
    ) {}

    public function principal(TenantContext $tenantContext): AdminPrincipal
    {
        $snapshot = $this->directory->activePrincipalProfileWithRoles($tenantContext->tenantId, $tenantContext->accountId, true);
        if ($snapshot === null || $snapshot['profile']['member']->memberId !== $tenantContext->memberId) {
            throw new \DomainException('TENANT_ADMIN_PRINCIPAL_UNAVAILABLE');
        }
        $profile = $snapshot['profile'];
        $member = $profile['member'];
        $roles = $snapshot['roles'];
        $switchableTenantCount = $profile['switchable_tenant_count'];
        $root = false;
        foreach ($roles as $role) {
            $root = $root || ($role['key'] === 'core.tenant-owner' && $role['is_builtin']);
        }

        return new AdminPrincipal(
            id: $member->memberId,
            tenantId: $member->tenantId,
            accountId: $member->accountId,
            tenantName: $profile['tenant_name'],
            username: $profile['username'],
            nickname: $member->displayName,
            name: $member->displayName,
            avatar: $profile['avatar'],
            root: $root,
            switchableTenantCount: (int) $switchableTenantCount,
            roles: $roles,
            roleName: implode('/', array_column($roles, 'name')),
            authorizationRevision: $member->authorizationRevision,
            primaryDepartmentId: $member->primaryDepartmentId,
            lastLoginAt: $profile['last_login_at'],
        );
    }

    /** Native Request storage is confined to the Login -> Auth middleware handoff. */
    public function principalForRequest(\think\Request $request, TenantContext $context): AdminPrincipal
    {
        $principal = $this->principal($context);
        $request->withMiddleware([self::class => ['context' => $context, 'principal' => $principal]]);
        return $principal;
    }

    /** Consume only the server-loaded identity for this exact Request and context. */
    public function decideForRequest(\think\Request $request, TenantContext $context, string $accessUri): PermissionDecision
    {
        $handoff = $request->middleware(self::class);
        $request->withMiddleware([self::class => null]);
        $principal = is_array($handoff) ? ($handoff['principal'] ?? null) : null;
        if (!is_array($handoff) || ($handoff['context'] ?? null) !== $context || !$principal instanceof AdminPrincipal
            || !$this->validContext($context, $principal)) {
            return PermissionDecision::deny($accessUri, 'INVALID_TENANT_ADMIN_CONTEXT');
        }
        return $this->permissionDecision($context, $principal, $accessUri);
    }

    /** ALL prerequisites, then ANY granted bundle, each within the current license ceiling. */
    public function decideApiForRequest(\think\Request $request, TenantContext $context, array $endpoint, array $keys): PermissionDecision
    {
        $handoff = $request->middleware(self::class);
        $request->withMiddleware([self::class => null]);
        $principal = is_array($handoff) ? ($handoff['principal'] ?? null) : null;
        if (!is_array($handoff) || ($handoff['context'] ?? null) !== $context || !$principal instanceof AdminPrincipal
            || !$this->validContext($context, $principal)) {
            return PermissionDecision::deny($endpoint['path'], 'INVALID_TENANT_ADMIN_CONTEXT');
        }
        $prerequisites = json_decode($endpoint['prerequisite_permission_keys'], true, 32, JSON_THROW_ON_ERROR);
        $inputs = $this->moduleAdmin->permissionInputs($context, [...$keys, ...$prerequisites]);
        $registered = $inputs['registered'];
        $owned = $inputs['owned'];
        foreach ($prerequisites as $required) {
            if (in_array($required, InstanceControlPlanePolicy::tenantAdminPermissions(), true)
                || !$this->permissionPolicy->canAccess(false, $required, $registered, $owned)) {
                return PermissionDecision::deny($endpoint['path'], 'API_PREREQUISITE_NOT_GRANTED');
            }
        }
        foreach ($keys as $key) {
            if (!in_array($key, InstanceControlPlanePolicy::tenantAdminPermissions(), true)
                && $this->permissionPolicy->canAccess(false, $key, $registered, $owned)) {
                $this->endpointGrant->record($context, $endpoint);
                return PermissionDecision::allow($key);
            }
        }
        return PermissionDecision::deny($endpoint['path'], 'API_PERMISSION_NOT_GRANTED');
    }

    public function accessData(TenantContext $tenantContext, AdminPrincipal $admin): AdminAccessData
    {
        $admin = $this->currentPrincipal($tenantContext, $admin);
        if (!$admin instanceof AdminPrincipal) {
            return new AdminAccessData([], []);
        }
        return $this->accessDataForPrincipal($tenantContext, $admin);
    }

    /** The caller has loaded and validated this principal in the current invocation. */
    private function accessDataForPrincipal(TenantContext $tenantContext, AdminPrincipal $admin): AdminAccessData
    {
        $bridge = $this->moduleAdmin;
        $native = $bridge->adminShellAccessData($tenantContext);
        $permissions = $native['permissions'];
        $menus = array_values(array_replace(
            array_column($this->compatibilityMenus(
                $tenantContext,
                $admin,
                $permissions,
                $native['registered_system_menu_permissions'],
                $native['managed_menu_keys'],
            ), null, 'menu_key'),
            array_column($native['menu'], null, 'menu_key'),
        ));
        if (!InstanceToolAccessGuard::fromConfiguredValue(config('deployment.mode'))->allows()) {
            $menus = array_values(array_filter(
                $menus,
                static fn(array $row): bool => strtolower(trim((string) ($row['paths'] ?? ''))) !== '/system/menu',
            ));
        }
        do {
            $before = count($menus);
            $ids = array_column($menus, 'menu_key');
            $menus = array_values(array_filter($menus, static fn(array $row): bool =>
                $row['parent_key'] === null || in_array($row['parent_key'], $ids, true)));
        } while (count($menus) !== $before);
        usort($menus, static fn(array $a, array $b): int => [-$a['sort'], $a['id']] <=> [-$b['sort'], $b['id']]);

        return new AdminAccessData(
            menu: linear_to_tree($menus, 'children', 'menu_key', 'parent_key'),
            permissions: array_values(array_unique($permissions)),
            actionBindings: $this->actionBindings(
                $native['system_menu_rows'],
                $native['managed_menu_keys'],
                $native['assignable_menu_records'],
            ),
        );
    }

    private function actionBindings(array $rows, array $managedMenuKeys, array $assignableMenuRecords): array
    {
        $managed = array_fill_keys($managedMenuKeys, true);
        $pages = array_column($assignableMenuRecords, null, 'menu_key');
        foreach ($rows as $row) {
            if ($row['type'] === 'C' && !isset($managed[$row['menu_key']]) && (int) $row['is_disable'] === 0) {
                $pages[$row['menu_key']] = $row;
            }
        }
        $byId = array_column($rows, null, 'id');
        $bindings = [];
        foreach ($rows as $row) {
            if ($row['type'] !== 'A' || $row['perms'] === '' || $row['paths'] === '') {
                continue;
            }
            $parentKey = $row['parent_key'] ?: ($byId[(int) $row['pid']]['menu_key'] ?? null);
            $page = $pages[$parentKey] ?? null;
            if ($page === null || $page['type'] !== 'C' || $page['paths'] === '') {
                continue;
            }
            $identity = $page['paths'] . "\0" . $row['paths'];
            $bindings[$identity . "\0" . $row['perms']] = ['page_path' => $page['paths'], 'action_key' => $row['paths'], 'permission_key' => $row['perms']];
        }
        return array_values($bindings);
    }

    public function menusForAdminId(TenantContext $tenantContext, int $memberId): array
    {
        if ($tenantContext->memberId !== $memberId) {
            return [];
        }

        try {
            $admin = $this->principal($tenantContext);
            return $this->validContext($tenantContext, $admin)
                ? $this->accessDataForPrincipal($tenantContext, $admin)->menu
                : [];
        } catch (\Throwable) {
            return [];
        }
    }

    public function menusForAdmin(TenantContext $tenantContext, AdminPrincipal $admin): array
    {
        return $this->accessData($tenantContext, $admin)->menu;
    }

    public function buttonPermissionsForAdmin(TenantContext $tenantContext, AdminPrincipal $admin): array
    {
        return $this->accessData($tenantContext, $admin)->permissions;
    }

    /** Core owner grants are supplied by Identity; business grants remain explicit. */
    public function decide(
        ?TenantContext $tenantContext,
        AdminPrincipal $admin,
        string $accessUri,
    ): PermissionDecision {
        if (!$tenantContext instanceof TenantContext || !$this->validContext($tenantContext, $admin)) {
            return PermissionDecision::deny($accessUri, 'INVALID_TENANT_ADMIN_CONTEXT');
        }
        $admin = $this->currentPrincipal($tenantContext, $admin);
        if (!$admin instanceof AdminPrincipal) {
            return PermissionDecision::deny($accessUri, 'STALE_TENANT_ADMIN_PRINCIPAL');
        }
        if ($this->endpointGrant->allows($tenantContext, $accessUri)) {
            return PermissionDecision::allow($accessUri);
        }
        return $this->permissionDecision($tenantContext, $admin, $accessUri);
    }

    /** Reuse this request's exact endpoint admission; pending keys use a fresh projection. */
    public function decisions(TenantContext $tenantContext, array $accessUris): array
    {
        if ($accessUris === []) {
            return [];
        }
        $decisions = [];
        $pending = [];
        foreach ($accessUris as $accessUri) {
            if (InstanceControlPlanePolicy::isTenantAdminRoute($accessUri)) {
                $decisions[$accessUri] = PermissionDecision::deny($accessUri, 'PLATFORM_ROUTE_FORBIDDEN');
            } elseif ($this->endpointGrant->allows($tenantContext, $accessUri)) {
                $decisions[$accessUri] = PermissionDecision::allow($accessUri);
            } else {
                $pending[] = $accessUri;
            }
        }
        if ($pending === []) {
            return $decisions;
        }
        // Unadmitted permissions still read current membership and credential together.
        $profile = $this->directory->activePrincipalProfile($tenantContext->tenantId, $tenantContext->accountId);
        if ($profile === null || $profile['member']->memberId !== $tenantContext->memberId) {
            throw new \DomainException('TENANT_ADMIN_PRINCIPAL_UNAVAILABLE');
        }
        $member = $profile['member'];
        if ($tenantContext->authorizationRevision < 1
            || $member->authorizationRevision !== $tenantContext->authorizationRevision
            || $tenantContext->sessionKey === '' || $tenantContext->clientKey === '' || $tenantContext->requestId === '') {
            foreach ($pending as $accessUri) {
                $decisions[$accessUri] = PermissionDecision::deny($accessUri, 'INVALID_TENANT_ADMIN_CONTEXT');
            }
            return $decisions;
        }
        $keys = array_map(static fn(string $uri): string => strtolower(trim($uri, '/')), $pending);
        $inputs = $this->moduleAdmin->permissionInputs(
            $tenantContext,
            array_values(array_unique(array_filter($keys, static fn(string $key): bool => $key !== ''))),
        );
        $registered = $inputs['registered'];
        $owned = $inputs['owned'];
        foreach ($pending as $accessUri) {
            $key = strtolower(trim($accessUri, '/'));
            $allowed = !in_array($key, InstanceControlPlanePolicy::tenantAdminPermissions(), true)
                && $this->permissionPolicy->canAccess(false, $accessUri, $registered, $owned);
            $decisions[$accessUri] = $allowed
                ? PermissionDecision::allow($accessUri)
                : PermissionDecision::deny($accessUri, 'PERMISSION_NOT_GRANTED');
        }
        return $decisions;
    }

    private function permissionDecision(TenantContext $tenantContext, AdminPrincipal $admin, string $accessUri): PermissionDecision
    {
        if (InstanceControlPlanePolicy::isTenantAdminRoute($accessUri)) {
            return PermissionDecision::deny($accessUri, 'PLATFORM_ROUTE_FORBIDDEN');
        }

        $bridge = $this->moduleAdmin;
        $permissionKey = strtolower(trim($accessUri, '/'));
        $registered = $permissionKey !== '' && $bridge->registeredPermission($tenantContext->tenantId, $permissionKey)
            && !in_array($permissionKey, InstanceControlPlanePolicy::tenantAdminPermissions(), true)
            ? [$permissionKey] : [];
        $owned = $bridge->permissionsOnly($tenantContext);
        $allowed = $this->permissionPolicy->canAccess(
            false,
            $accessUri,
            $registered,
            $owned,
        );

        return $allowed
            ? PermissionDecision::allow($accessUri)
            : PermissionDecision::deny($accessUri, 'PERMISSION_NOT_GRANTED');
    }

    public function authorizedAsyncExport(
        TenantContext $tenantContext,
        AdminPrincipal $admin,
        string $operationId = '',
    ): AuthorizedOperationContext {
        return $this->authorizedOperation(
            $tenantContext,
            $admin,
            'peanut.import-export',
            'create',
            [],
            $operationId,
        );
    }

    /**
     * Creates trusted context for the export operation only; callers cannot widen its targets.
     *
     * @param list<RequestedTargetSet> $requestedTargets
     */
    public function authorizedOperation(
        TenantContext $tenantContext,
        AdminPrincipal $admin,
        string $resourceKey,
        string $operation,
        array $requestedTargets,
        string $operationId = '',
    ): AuthorizedOperationContext {
        if ($resourceKey !== 'peanut.import-export'
            || $operation !== 'create'
            || $requestedTargets !== []
        ) {
            throw new \DomainException('ASYNC_OPERATION_CONTEXT_INVALID');
        }
        if (!$this->decide($tenantContext, $admin, 'peanut.import-export.operation-log.export')->allowed) {
            throw new \DomainException('ASYNC_EXPORT_PERMISSION_DENIED');
        }

        return AuthorizedOperationContext::fromDecision(AuthorizationDecision::allow(
            $tenantContext,
            $resourceKey,
            $operation,
            $requestedTargets,
            hash('sha256', implode("\0", array_filter([
                (string) $tenantContext->tenantId,
                (string) $tenantContext->memberId,
                (string) $tenantContext->authorizationRevision,
                'peanut.import-export.operation-log.export',
                $operationId,
            ], static fn(string $value): bool => $value !== ''))),
        ));
    }

    /** @return list<array<string,mixed>> */
    public function assignableMenuRecords(TenantContext $tenantContext): array
    {
        return $this->assignableMenuRecordsForTenant($tenantContext->tenantId);
    }

    /** @return list<array<string,mixed>> */
    public function assignableMenuRecordsForTenant(int $tenantId): array
    {
        return $this->moduleAdmin->assignableMenuRecords($tenantId);
    }

    /** @return list<array<string,mixed>> */
    public function moduleMenuRecords(TenantContext $tenantContext): array
    {
        return $this->moduleAdmin->accessData($tenantContext)['menu'];
    }

    /** @param list<string> $permissions @param list<string> $registered @param list<string> $managedMenuKeys */
    private function compatibilityMenus(
        TenantContext $tenantContext,
        AdminPrincipal $admin,
        array $permissions,
        array $registered,
        array $managedMenuKeys,
    ): array {
        if (!$this->validContext($tenantContext, $admin)) {
            return [];
        }

        $visiblePermissions = array_values(array_intersect($permissions, $registered));
        $query = SystemMenu::where('type', 'in', ['M', 'C'])
            ->where('is_disable', 0)
            ->whereNotIn('perms', InstanceControlPlanePolicy::tenantAdminPermissions())
            ->whereNotIn('paths', InstanceControlPlanePolicy::tenantAdminPaths())
            ->where(static function ($query) use ($visiblePermissions): void {
                $query->where('perms', '')->whereOr('perms', 'in', $visiblePermissions ?: ['__none__']);
            });

        $nativeKeys = array_fill_keys($managedMenuKeys, true);
        return array_values(array_filter(
            $query->order(['sort' => 'desc', 'id' => 'asc'])->select()->toArray(),
            static fn(array $row): bool => !isset($nativeKeys[$row['menu_key']]),
        ));
    }

    private function validContext(?TenantContext $context, AdminPrincipal $admin): bool
    {
        return $context instanceof TenantContext
            && $context->tenantId > 0
            && $context->accountId > 0
            && $context->memberId > 0
            && $context->authorizationRevision > 0
            && $context->sessionKey !== ''
            && $context->clientKey !== ''
            && $context->requestId !== ''
            && $admin->authorizationRevision === $context->authorizationRevision
            && $admin->tenantId === $context->tenantId
            && $admin->accountId === $context->accountId
            && $admin->id === $context->memberId;
    }

    private function currentPrincipal(TenantContext $context, AdminPrincipal $supplied): ?AdminPrincipal
    {
        if (!$this->validContext($context, $supplied)) {
            return null;
        }
        try {
            $current = $this->principal($context);
        } catch (\Throwable) {
            return null;
        }
        return $current->tenantId === $supplied->tenantId
            && $current->accountId === $supplied->accountId
            && $current->id === $supplied->id
            && $current->authorizationRevision === $context->authorizationRevision
            ? $current
            : null;
    }

}
