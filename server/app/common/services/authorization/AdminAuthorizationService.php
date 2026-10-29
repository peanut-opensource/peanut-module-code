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
use PeanutAdmin\Modules\Identity\Contract\TenantMemberDirectory;
use PeanutAdmin\Modules\Identity\Platform\InstanceControlPlanePolicy;
use app\common\validation\instance\InstanceToolAccessGuard;

/** Tenant Admin identity, RBAC and access projection service. */
final class AdminAuthorizationService implements AdminAuthorizationQuery, AuthorizedOperationFactory
{
    public function __construct(
        private readonly CoreTenantModuleAdminBridge $moduleAdmin,
        private readonly AdminPermissionPolicy $permissionPolicy,
        private readonly TenantMemberDirectory $members,
        private readonly AdminDirectoryQuery $directory,
        private readonly TenantAuthorizationQuery $identityAuthorization,
    ) {}

    public function principal(TenantContext $tenantContext): AdminPrincipal
    {
        $member = $this->members->activeMembership($tenantContext->tenantId, $tenantContext->memberId);
        if ($member === null
            || $member->accountId !== $tenantContext->accountId) {
            throw new \DomainException('TENANT_ADMIN_PRINCIPAL_UNAVAILABLE');
        }

        $profile = $this->directory->activePrincipalProfile($member->tenantId, $member->accountId);
        if ($profile === null) {
            throw new \DomainException('TENANT_ADMIN_PRINCIPAL_UNAVAILABLE');
        }

        $roles = $this->identityAuthorization->roles($tenantContext->tenantId, $tenantContext->memberId);
        $switchableTenantCount = $this->members->activeMembershipCount($member->accountId);
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

    public function accessData(TenantContext $tenantContext, AdminPrincipal $admin): AdminAccessData
    {
        $admin = $this->currentPrincipal($tenantContext, $admin);
        if (!$admin instanceof AdminPrincipal) {
            return new AdminAccessData([], []);
        }
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
            permissions: $admin->root
                ? $native['registered_permissions']
                : array_values(array_unique($permissions)),
        );
    }

    public function menusForAdminId(TenantContext $tenantContext, int $memberId): array
    {
        if ($tenantContext->memberId !== $memberId) {
            return [];
        }

        try {
            return $this->accessData($tenantContext, $this->principal($tenantContext))->menu;
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

    /** Authorizes only active permissions owned by the application or an installed and enabled Module. */
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
        return $this->permissionDecision($tenantContext, $admin, $accessUri);
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
        $owned = $admin->root ? [] : $bridge->permissionsOnly($tenantContext);
        $allowed = $this->permissionPolicy->canAccess(
            $admin->root,
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

        $visiblePermissions = $admin->root
            ? $registered
            : array_values(array_intersect($permissions, $registered));
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
