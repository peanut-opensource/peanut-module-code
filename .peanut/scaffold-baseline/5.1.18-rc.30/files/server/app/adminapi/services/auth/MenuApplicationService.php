<?php

declare(strict_types=1);

namespace app\adminapi\services\auth;

use app\common\services\authorization\AdminAuthorizationService;
use app\common\services\authorization\MenuPermissionUsageQuery;
use app\common\exception\BusinessException;
use think\facade\Db;
use PeanutAdmin\Modules\Identity\Platform\InstanceControlPlanePolicy;
use PeanutAdmin\Modules\Identity\Menu\MenuAdministrationService;
use PeanutAdmin\Kernel\Auth\TenantContext;

class MenuApplicationService
{
    /** @var list<array<string,mixed>>|null */
    private ?array $recordsCache = null;

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
        if ($this->recordsCache !== null) {
            return $this->recordsCache;
        }
        $rows = [];
        foreach ($this->catalog->records() as $menu) {
            $perm = (string) ($menu['instance_permission'] ?? $menu['required_permission'] ?? '');
            $path = (string) ($menu['route_path'] ?? '');
            if (in_array($perm, InstanceControlPlanePolicy::tenantAdminPermissions(), true)
                || in_array($path, InstanceControlPlanePolicy::tenantAdminPaths(), true)) {
                continue;
            }
            $source = (string) ($menu['source'] ?? 'module');
            $rows[] = [
                'id' => (int) $menu['id'],
                'menu_key' => $menu['key'],
                'parent_key' => $menu['parent_key'],
                'module_key' => $menu['module_key'],
                'source' => $source,
                'managed' => $source !== 'custom',
                'type' => match ($menu['type']) {
                    'group' => 'M',
                    'action' => 'A',
                    default => 'C',
                },
                'name' => $menu['name'],
                'icon' => $menu['icon'] ?? '',
                'sort' => -(int) $menu['sort_order'],
                'perms' => $perm,
                'paths' => $path,
                'component' => $menu['component_key'] ?? '',
                'is_show' => (int) $menu['is_show'],
                'is_cache' => (int) $menu['is_cache'],
                'is_disable' => (int) $menu['is_disable'],
                'status' => $menu['status'],
                'conflict' => $menu['menu_conflict_json'] ?? null,
            ];
        }
        usort($rows, static fn(array $a, array $b): int => [-$a['sort'], $a['menu_key']] <=> [-$b['sort'], $b['menu_key']]);
        return $this->recordsCache = $rows;
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
            $this->recordsCache = null;
            $this->assertParent($params['parent_key'] ?? null);
            $permissionId = $this->validateBinding($params);
            $this->validateActionIdentities($params);
            $this->catalog->createCustom([
                'parent_key' => $params['parent_key'] ?? null,
                ...$this->presentation($params),
                'type' => $params['type'],
                'paths' => $params['paths'] ?? '',
                'component' => $params['component'] ?? '',
                'instance_permission_id' => $permissionId,
            ]);
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
            $this->assertParent($params['parent_key'] ?? null, $key);
            $permissionId = $this->validateBinding($params);
            $this->validateActionIdentities($record['managed'] ? [...$params, 'type' => $record['type'], 'paths' => $record['paths']] : $params, $key);
            $native = array_column($this->catalog->records('tenant', true), null, 'key')[$key] ?? null;
            if ($native !== null && ($params['perms'] ?? '') === ($native['required_permission'] ?? null)) {
                $permissionId = null;
            }
            $presentation = [...$this->presentation($params), 'parent_key' => $params['parent_key'] ?? null, 'instance_permission_id' => $permissionId];
            if (!$record['managed']) {
                $presentation += [
                    'type' => $params['type'],
                    'paths' => $params['paths'] ?? '',
                    'component' => $params['component'] ?? '',
                ];
            }
            $this->catalog->updatePresentation($key, $presentation);
            $this->recordsCache = null;
            return true;
        });
    }

    private function validateBinding(array $params): ?int
    {
        $permission = (string) ($params['perms'] ?? '');
        if ($permission === '') {
            return null;
        }
        $definition = Db::name('permission')->where('key', $permission)->where('status', 'active')->find();
        if ($definition === null || $definition['key'] !== $permission
            || str_starts_with($permission, 'platform.')
            || in_array($definition['module_key'], ['platform', 'instance.platform'], true)
            || in_array($permission, InstanceControlPlanePolicy::tenantAdminPermissions(), true)) {
            throw BusinessException::conflict('MENU_PERMISSION_INVALID', '请选择启用的租户权限包');
        }
        return (int) $definition['id'];
    }

    private function validateActionIdentities(array $params, ?string $key = null): void
    {
        $records = array_column($this->records(), null, 'menu_key');
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
            $record = $this->detail($key);
            if ($record === []) {
                throw BusinessException::notFound('ADMIN_MENU_NOT_FOUND', '菜单不存在');
            }
            if ($record['managed']) {
                throw BusinessException::conflict('MANAGED_MENU_DELETE_FORBIDDEN', '系统及模块贡献菜单请通过所属模块生命周期移除');
            }
            foreach ($this->records() as $row) {
                if ($row['parent_key'] === $key) {
                    throw BusinessException::conflict('ADMIN_MENU_HAS_CHILDREN', '请先处理下级菜单');
                }
            }
            if ($record['perms'] !== '' && $this->permissionUsage->assigned($record['perms'])) {
                throw BusinessException::conflict('ADMIN_MENU_IN_USE', '菜单权限已被角色使用');
            }
            $this->catalog->deleteCustom($key);
            $this->recordsCache = null;
            return true;
        });
    }

    public function updateStatus(string $key, int $disabled): bool
    {
        return (bool) Db::transaction(function () use ($key, $disabled): bool {
            $row = $this->detail($key);
            if ($row === []) {
                throw BusinessException::notFound('ADMIN_MENU_NOT_FOUND', '菜单不存在');
            }
            $this->catalog->updateStatus($key, $disabled);
            $this->recordsCache = null;
            return true;
        });
    }

    private function presentation(array $params): array
    {
        return [
            'name' => $params['name'],
            'icon' => $params['icon'] ?? '',
            'sort' => (int) ($params['sort'] ?? 0),
            'is_cache' => (int) ($params['is_cache'] ?? 0),
            'is_show' => (int) ($params['is_show'] ?? 1),
            'is_disable' => (int) ($params['is_disable'] ?? 0),
        ];
    }

    private function assertParent(?string $key, ?string $menuKey = null): void
    {
        $rows = array_column($this->records(), null, 'menu_key');
        $seen = $menuKey === null ? [] : [$menuKey => true];
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
    }
}
