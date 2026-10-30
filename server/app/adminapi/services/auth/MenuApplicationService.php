<?php

declare(strict_types=1);

namespace app\adminapi\services\auth;

use app\common\services\authorization\AdminAuthorizationService;
use app\common\services\authorization\MenuPermissionUsageQuery;
use app\common\exception\BusinessException;
use app\common\model\auth\SystemMenu;
use think\facade\Db;
use PeanutAdmin\Modules\Identity\Platform\InstanceControlPlanePolicy;
use PeanutAdmin\Modules\Identity\Menu\MenuAdministrationService;
use PeanutAdmin\Kernel\Auth\TenantContext;

class MenuApplicationService
{
    public const CUSTOM_ID_START = 1_000_000_000;

    public function __construct(
        private readonly AdminAuthorizationService $authorization,
        private readonly MenuPermissionUsageQuery $permissionUsage,
        private readonly MenuAdministrationService $catalog,
    ) {}

    public function getMenuByAdminId(TenantContext $context, int $adminId): array
    {
        return $this->authorization->menusForAdminId($context, $adminId);
    }

    /** @return list<array<string,mixed>> */
    public function records(): array
    {
        $rows = SystemMenu::whereNotIn('perms', InstanceControlPlanePolicy::tenantAdminPermissions())
            ->whereNotIn('paths', InstanceControlPlanePolicy::tenantAdminPaths())->select()->toArray();
        $byKey = array_column($rows, null, 'menu_key');
        foreach ($byKey as &$row) {
            $row['managed'] = !str_starts_with($row['menu_key'], 'custom.');
            $row['source'] = $row['module_key'] === 'application' ? 'custom' : 'system';
            $row['status'] = 'active';
        }
        unset($row);
        foreach ($this->catalog->records() as $menu) {
            $legacy = $byKey[$menu['key']] ?? null;
            $customized = false;
            if ($legacy !== null) {
                $defaults = $legacy['upstream_defaults_json'] ?? null;
                if (is_string($defaults)) {
                    $defaults = json_decode($defaults, true, 512, JSON_THROW_ON_ERROR);
                }
                $customized = !is_array($defaults) || $legacy['menu_conflict_json'] !== null;
                foreach ($defaults ?? [] as $field => $expected) {
                    if ((is_int($expected) ? (int) $legacy[$field] : $legacy[$field]) !== $expected) {
                        $customized = true;
                    }
                }
            }
            $byKey[$menu['key']] = [
                'id' => (int) $menu['id'], 'menu_key' => $menu['key'],
                'parent_key' => $customized ? $legacy['parent_key'] : $menu['parent_key'],
                'module_key' => $menu['module_key'], 'source' => 'module', 'managed' => true,
                'type' => $menu['type'] === 'group' ? 'M' : 'C',
                'name' => $customized ? $legacy['name'] : $menu['name'],
                'icon' => $customized ? $legacy['icon'] : ($menu['icon'] ?? ''),
                'sort' => $customized ? (int) $legacy['sort'] : -(int) $menu['sort_order'],
                'perms' => $menu['instance_permission'] ?? ($customized ? $legacy['perms'] : ($menu['required_permission'] ?? '')),
                'paths' => $customized ? $legacy['paths'] : ($menu['route_path'] ?? ''),
                'component' => $menu['component_key'] ?? '',
                'is_show' => $customized ? (int) $legacy['is_show'] : (int) $menu['is_show'],
                'is_cache' => $customized ? (int) $legacy['is_cache'] : (int) $menu['is_cache'],
                'is_disable' => $customized ? (int) $legacy['is_disable'] : (int) $menu['is_disable'],
                'status' => $menu['status'],
                'conflict' => $legacy['menu_conflict_json'] ?? $menu['menu_conflict_json'],
            ];
        }
        $rows = array_values($byKey);
        usort($rows, static fn(array $a, array $b): int => [-$a['sort'], $a['menu_key']] <=> [-$b['sort'], $b['menu_key']]);
        return $rows;
    }

    public function getAll(): array
    {
        return linear_to_tree($this->records(), 'children', 'menu_key', 'parent_key');
    }

    public function getAllSimple(TenantContext $context): array
    {
        $available = array_column($this->authorization->assignableMenuRecords($context), null, 'menu_key');
        $rows = array_values(array_filter($this->records(), static fn(array $row): bool =>
            (int) $row['is_disable'] === 0 && $row['status'] === 'active'
            && ($row['source'] !== 'module' || isset($available[$row['menu_key']]))));
        do {
            $keys = array_fill_keys(array_column($rows, 'menu_key'), true);
            $before = count($rows);
            $rows = array_values(array_filter($rows, static fn(array $row): bool =>
                $row['parent_key'] === null || isset($keys[$row['parent_key']])));
        } while (count($rows) !== $before);
        return linear_to_tree($rows, 'children', 'menu_key', 'parent_key');
    }

    public function detail(string $key): array
    {
        return array_column($this->records(), null, 'menu_key')[$key] ?? [];
    }

    public function add(array $params): bool
    {
        return (bool) Db::transaction(function () use ($params): bool {
            $parent = $this->assertParent($params['parent_key'] ?? null);
            $this->validateBinding($params);
            $this->validateActionIdentities($params);
            $menu = SystemMenu::create([
                'menu_key' => 'custom.' . bin2hex(random_bytes(16)), 'module_key' => 'application',
                'parent_key' => $params['parent_key'] ?? null, 'pid' => $parent,
                ...$this->presentation($params), 'type' => $params['type'],
                'perms' => $params['perms'] ?? '', 'paths' => $params['paths'] ?? '',
                'component' => $params['component'] ?? '',
            ]);
            if ((int) $menu->getAttr('id') < self::CUSTOM_ID_START) {
                throw BusinessException::conflict('MENU_ID_RANGE_NOT_PREPARED', '请先完成菜单身份升级迁移');
            }
            return true;
        });
    }

    public function edit(array $params): bool
    {
        return (bool) Db::transaction(function () use ($params): bool {
            $key = $params['menu_key'];
            $record = $this->detail($key);
            if ($record === []) {
                throw BusinessException::notFound('ADMIN_MENU_NOT_FOUND', '菜单不存在');
            }
            $parent = $this->assertParent($params['parent_key'] ?? null, $key);
            $permissionId = $this->validateBinding($params, $key);
            $this->validateActionIdentities($record['managed'] ? [...$params, 'type' => $record['type'], 'paths' => $record['paths']] : $params, $key);
            $legacy = SystemMenu::where('menu_key', $key)->lock(true)->find();
            $native = $record['managed'] ? (array_column($this->catalog->records(), null, 'key')[$key] ?? null) : null;
            if ($native !== null && ($params['perms'] ?? '') === ($native['required_permission'] ?? null)) {
                $permissionId = null;
            }
            if ($legacy !== null) {
                $changes = ['parent_key' => $params['parent_key'] ?? null, 'pid' => $parent, ...$this->presentation($params)];
                if (!$record['managed']) {
                    $changes += ['type' => $params['type'], 'perms' => $params['perms'] ?? '',
                        'paths' => $params['paths'] ?? '', 'component' => $params['component'] ?? ''];
                }
                if ($record['managed']) {
                    $permission = $params['perms'] ?? '';
                    if ($permission === '') {
                        $defaults = $legacy->getAttr('upstream_defaults_json');
                        if (is_string($defaults)) {
                            $defaults = json_decode($defaults, true, 512, JSON_THROW_ON_ERROR);
                        }
                        $permission = $native['required_permission'] ?? ($defaults['perms'] ?? null);
                        if (!is_string($permission)) {
                            throw BusinessException::conflict('MENU_DEFAULT_PERMISSION_UNKNOWN', '无法确定此菜单的默认权限');
                        }
                    }
                    $changes['perms'] = $permission;
                    $this->catalog->updateInstancePermission($key, $permissionId);
                }
                $legacy->save($changes);
            } else {
                $this->catalog->updatePresentation($key, [...$params, 'instance_permission_id' => $permissionId]);
            }
            return true;
        });
    }

    private function validateBinding(array $params, ?string $key = null): ?int
    {
        $permission = (string) ($params['perms'] ?? '');
        $permissionId = null;
        if ($permission !== '') {
            $definition = Db::name('permission')->where('key', $permission)->where('status', 'active')->find();
            if ($definition === null || $definition['key'] !== $permission
                || str_starts_with($permission, 'platform.')
                || in_array($definition['module_key'], ['platform', 'instance.platform'], true)
                || in_array($permission, InstanceControlPlanePolicy::tenantAdminPermissions(), true)) {
                throw BusinessException::conflict('MENU_PERMISSION_INVALID', '请选择启用的租户权限包');
            }
            $permissionId = (int) $definition['id'];
        }
        return $permissionId;
    }

    private function validateActionIdentities(array $params, ?string $key = null): void
    {
        $records = array_column($this->records(), null, 'menu_key');
        $legacy = SystemMenu::field('id,pid,menu_key,parent_key')->select()->toArray();
        $byId = array_column($legacy, null, 'id');
        foreach ($legacy as $row) {
            if (isset($records[$row['menu_key']]) && ($records[$row['menu_key']]['parent_key'] ?? null) === null && (int) $row['pid'] !== 0) {
                $records[$row['menu_key']]['parent_key'] = $byId[(int) $row['pid']]['menu_key'] ?? null;
            }
        }
        $key ??= '__new_menu__';
        $records[$key] = [...($records[$key] ?? []), ...$params, 'menu_key' => $key];
        $affected = [];
        foreach ($records as $record) {
            if (($record['type'] ?? '') !== 'A' || ($record['paths'] ?? '') === '' || ($record['perms'] ?? '') === '') {
                continue;
            }
            $page = $records[$record['parent_key'] ?? ''] ?? null;
            if (($page['type'] ?? '') !== 'C' || ($page['paths'] ?? '') === '') {
                continue;
            }
            $identity = $page['paths'] . "\0" . $record['paths'];
            if ($record['menu_key'] === $key || $record['parent_key'] === $key) {
                $affected[$identity] = true;
            }
        }
        $seen = [];
        foreach ($records as $record) {
            if (($record['type'] ?? '') !== 'A' || ($record['paths'] ?? '') === '' || ($record['perms'] ?? '') === '') {
                continue;
            }
            $page = $records[$record['parent_key'] ?? ''] ?? null;
            if (($page['type'] ?? '') !== 'C' || ($page['paths'] ?? '') === '') {
                continue;
            }
            $identity = $page['paths'] . "\0" . $record['paths'];
            if (isset($affected[$identity], $seen[$identity]) && $seen[$identity] !== $record['perms']) {
                throw BusinessException::conflict('ACTION_PERMISSION_BINDING_CONFLICT', '同一页面动作只能关联一个权限包');
            }
            $seen[$identity] = $record['perms'];
        }
    }

    public function delete(string $key): bool
    {
        return (bool) Db::transaction(function () use ($key): bool {
            if (!str_starts_with($key, 'custom.')) {
                throw BusinessException::conflict('MANAGED_MENU_DELETE_FORBIDDEN', '系统及模块贡献菜单请通过所属模块生命周期移除');
            }
            $menu = SystemMenu::where('menu_key', $key)->lock(true)->find();
            if ($menu === null) {
                throw BusinessException::notFound('ADMIN_MENU_NOT_FOUND', '菜单不存在');
            }
            foreach ($this->records() as $row) {
                if ($row['parent_key'] === $key) {
                    throw BusinessException::conflict('ADMIN_MENU_HAS_CHILDREN', '请先处理下级菜单');
                }
            }
            if ($menu->getAttr('perms') !== '' && $this->permissionUsage->assigned($menu->getAttr('perms'))) {
                throw BusinessException::conflict('ADMIN_MENU_IN_USE', '菜单权限已被角色使用');
            }
            $menu->delete();
            return true;
        });
    }

    public function updateStatus(string $key, int $disabled): bool
    {
        $row = $this->detail($key);
        if ($row === []) {
            throw BusinessException::notFound('ADMIN_MENU_NOT_FOUND', '菜单不存在');
        }
        $legacy = SystemMenu::where('menu_key', $key)->find();
        if ($legacy !== null) {
            $legacy->save(['is_disable' => $disabled]);
        } else {
            $this->catalog->updateStatus($key, $disabled);
        }
        return true;
    }

    private function presentation(array $params): array
    {
        return ['name' => $params['name'], 'icon' => $params['icon'] ?? '', 'sort' => (int) ($params['sort'] ?? 0),
            'is_cache' => (int) ($params['is_cache'] ?? 0), 'is_show' => (int) ($params['is_show'] ?? 1),
            'is_disable' => (int) ($params['is_disable'] ?? 0)];
    }

    private function assertParent(?string $key, ?string $menuKey = null): int
    {
        $rows = array_column($this->records(), null, 'menu_key');
        $seen = $menuKey === null ? [] : [$menuKey => true];
        $first = $key;
        while ($key !== null) {
            if (isset($seen[$key])) {
                throw BusinessException::invalid('ADMIN_MENU_PARENT_INVALID', '菜单不可形成循环');
            }
            $seen[$key] = true;
            $parent = $rows[$key] ?? null;
            if ($parent === null || $parent['type'] === 'A') {
                throw BusinessException::invalid('ADMIN_MENU_PARENT_INVALID', '上级菜单不存在或为按钮');
            }
            $key = $parent['parent_key'];
        }
        return $first === null ? 0 : (int) (SystemMenu::where('menu_key', $first)->value('id') ?? 0);
    }
}
