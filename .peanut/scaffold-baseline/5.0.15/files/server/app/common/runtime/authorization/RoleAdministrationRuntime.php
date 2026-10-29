<?php

declare(strict_types=1);

namespace app\common\runtime\authorization;

use app\common\services\authorization\AdminAuthorizationService;
use app\common\model\auth\SystemMenu;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Modules\Identity\Authorization\Application\RoleAdminService;
use PeanutAdmin\Modules\Identity\Contract\TenantAuthorizationQuery;
use PeanutAdmin\Modules\Identity\Menu\MenuAdministrationService;

/** Container-owned assembly and read projections for native Tenant roles. */
final readonly class RoleAdministrationRuntime
{
    public function __construct(
        private RoleAdminService $roles,
        private AdminAuthorizationService $authorization,
        private TenantAuthorizationQuery $identityAuthorization,
        private MenuAdministrationService $menuAdministration,
    ) {}

    public function service(): RoleAdminService
    {
        return $this->roles;
    }

    /** @param list<string> $permissionKeys @return list<string> */
    public function menuKeys(TenantContext $context, array $permissionKeys): array
    {
        if ($permissionKeys === []) {
            return [];
        }
        $ids = array_map('strval', SystemMenu::where('is_disable', 0)
            ->whereIn('perms', $permissionKeys)->order('menu_key')->column('menu_key'));
        foreach ($this->authorization->assignableMenuRecords($context) as $menu) {
            if (in_array((string) $menu['required_permission'], $permissionKeys, true)) {
                $ids[] = $menu['menu_key'];
            }
        }
        return array_values(array_unique($ids));
    }

    public function memberCount(int $tenantId, int $roleId): int
    {
        return $this->identityAuthorization->roleMemberCount($tenantId, $roleId);
    }

    /** @param list<int> $roleIds @return array<int,int> */
    public function batchMemberCounts(int $tenantId, array $roleIds): array
    {
        return $this->identityAuthorization->batchRoleMemberCounts($tenantId, $roleIds);
    }

    /** @param list<string> $menuKeys @return list<string> */
    public function permissionKeys(int $tenantId, array $menuKeys): array
    {
        if ($menuKeys === []) {
            return [];
        }
        $nativeKeys = array_column($this->menuAdministration->records(), 'key');
        $available = $this->authorization->assignableMenuRecordsForTenant($tenantId);
        $known = array_merge(
            array_column($available, 'menu_key'),
            SystemMenu::where('is_disable', 0)->whereNotIn('menu_key', $nativeKeys)->column('menu_key'),
        );
        if (array_diff($menuKeys, $known) !== []) {
            throw new \DomainException('ADMIN_MENU_NOT_ASSIGNABLE');
        }
        $menuPermissions = array_map('strval', SystemMenu::where('is_disable', 0)
            ->whereNotIn('menu_key', $nativeKeys)
            ->whereIn('menu_key', $menuKeys)->where('perms', '<>', '')->distinct(true)->column('perms'));
        $states = $this->identityAuthorization->permissionStates($menuPermissions);
        $keys = array_values(array_unique(array_column(array_filter($states, static fn(array $state): bool => $state['status'] === 'active'), 'key')));
        sort($keys, SORT_STRING);
        $selected = array_fill_keys($menuKeys, true);
        foreach ($available as $menu) {
            if (isset($selected[$menu['menu_key']]) && trim((string) $menu['required_permission']) !== '') {
                $keys[] = (string) $menu['required_permission'];
            }
        }
        return array_values(array_unique($keys));
    }
}
