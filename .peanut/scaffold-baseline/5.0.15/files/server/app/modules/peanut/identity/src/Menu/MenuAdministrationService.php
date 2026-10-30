<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Menu;

use PeanutAdmin\Kernel\Authorization\Application\AdminAccessException;
use PeanutAdmin\Modules\Identity\Authorization\CorePermissionCatalog;
use PeanutAdmin\Modules\Identity\Platform\InstanceControlPlanePolicy;
use PeanutAdmin\Modules\Identity\Persistence\Model\MenuDefinition;
use think\facade\Db;

/** Instance-owned presentation management; module identity and permissions stay immutable. */
final class MenuAdministrationService
{
    /** @return list<array<string,mixed>> */
    public function records(string $scope = 'tenant', bool $lock = false): array
    {
        $query = MenuDefinition::alias('menu')->leftJoin('permission p', 'p.id=menu.required_permission_id')
            ->leftJoin('permission custom', 'custom.id=menu.instance_permission_id')
            ->where('menu.scope', $scope)->field('menu.*,p.key AS required_permission,custom.key AS instance_permission')
            ->order('menu.sort_order')->order('menu.key');
        if ($lock) {
            $query->lock(true);
        }
        return $query->select()->toArray();
    }

    /** @param array<string,mixed> $presentation */
    public function updatePresentation(string $key, array $presentation): void
    {
        Db::transaction(function () use ($key, $presentation): void {
            $menu = MenuDefinition::where('key', $key)->where('scope', 'tenant')->lock(true)->find();
            if ($menu === null) {
                throw new \DomainException('ADMIN_MENU_NOT_FOUND');
            }
            $parentKey = $presentation['parent_key'] ?? null;
            $seen = [$key => true];
            while ($parentKey !== null) {
                if (isset($seen[$parentKey])) {
                    throw new \DomainException('ADMIN_MENU_PARENT_CYCLE');
                }
                $seen[$parentKey] = true;
                $parent = MenuDefinition::where('key', $parentKey)->where('scope', 'tenant')->lock(true)->find();
                if ($parent === null || $parent->getAttr('type') !== 'group') {
                    throw new \DomainException('ADMIN_MODULE_MENU_PARENT_INVALID');
                }
                $parentKey = $parent->getAttr('parent_key');
            }
            $menu->save([
                'name' => $presentation['name'], 'icon' => $presentation['icon'] ?? '',
                'sort_order' => -(int) ($presentation['sort'] ?? 0),
                'parent_key' => $presentation['parent_key'] ?? null,
                'is_show' => (int) ($presentation['is_show'] ?? 1),
                'is_cache' => (int) ($presentation['is_cache'] ?? 0),
                'is_disable' => (int) ($presentation['is_disable'] ?? 0),
                'instance_permission_id' => $presentation['instance_permission_id'] ?? null,
            ]);
        });
    }

    public function updateInstancePermission(string $key, ?int $permissionId): void
    {
        MenuDefinition::where('key', $key)->where('scope', 'tenant')->update(['instance_permission_id' => $permissionId]);
    }

    public function updateStatus(string $key, int $disabled): void
    {
        MenuDefinition::where('key', $key)->where('scope', 'tenant')->update(['is_disable' => $disabled]);
    }

    /** Existing instance menu records; this does not grant a permission to a role. */
    public function permissionBindings(bool $lock = false): array
    {
        $query = Db::name('system_menu')->order('id');
        if ($lock) {
            $query->lock(true);
        }
        $legacy = $query->select()->toArray();
        $byId = array_column($legacy, null, 'id');
        $legacyByKey = array_column($legacy, null, 'menu_key');
        $native = array_column($this->records('tenant', $lock), null, 'key');
        $rows = [];
        foreach ($legacy as $row) {
            $parent = $row['parent_key'] ?: ($byId[(int) $row['pid']]['menu_key'] ?? null);
            $defaults = $row['upstream_defaults_json'];
            if (is_string($defaults)) {
                $defaults = json_decode($defaults, true, 512, JSON_THROW_ON_ERROR);
            }
            $rows[$row['menu_key']] = ['menu_key' => $row['menu_key'], 'parent_key' => $parent,
                'type' => $row['type'], 'name' => $row['name'], 'path' => $row['paths'],
                'permission_key' => $row['perms'],
                'default_permission_key' => $defaults['perms'] ?? (str_starts_with($row['menu_key'], 'custom.') ? '' : null)];
        }
        foreach ($native as $row) {
            $legacyRow = $rows[$row['key']] ?? null;
            $customized = isset($legacyByKey[$row['key']]) && $this->legacyCustomized($legacyByKey[$row['key']], $byId);
            $rows[$row['key']] = ['menu_key' => $row['key'], 'parent_key' => $customized ? $legacyRow['parent_key'] : $row['parent_key'],
                'type' => $row['type'] === 'group' ? 'M' : 'C', 'name' => $customized ? $legacyRow['name'] : $row['name'],
                'path' => $customized ? $legacyRow['path'] : ($row['route_path'] ?? ''),
                'permission_key' => $row['instance_permission'] ?? ($customized ? $legacyRow['permission_key'] : ($row['required_permission'] ?? '')),
                'default_permission_key' => $row['required_permission'] ?? ''];
        }
        $items = [];
        foreach ($rows as $row) {
            if (!in_array($row['type'], ['C', 'A'], true)
                || in_array($row['permission_key'], InstanceControlPlanePolicy::tenantAdminPermissions(), true)
                || in_array($row['path'], InstanceControlPlanePolicy::tenantAdminPaths(), true)) {
                continue;
            }
            $parent = $rows[$row['parent_key'] ?? ''] ?? null;
            $pagePath = $row['type'] === 'C' ? $row['path'] : (($parent['type'] ?? '') === 'C' ? $parent['path'] : '');
            $item = ['menu_key' => $row['menu_key'], 'parent_key' => $row['parent_key'], 'type' => $row['type'],
                'name' => $row['name'], 'page_path' => $pagePath, 'action_key' => $row['type'] === 'A' ? $row['path'] : '',
                'permission_key' => $row['permission_key'], 'default_permission_key' => $row['default_permission_key']];
            $item['revision'] = hash('sha256', json_encode($item, JSON_THROW_ON_ERROR));
            $items[] = $item;
        }
        return $items;
    }

    private function legacyCustomized(array $row, array $byId): bool
    {
        $seen = [];
        while (true) {
            $id = (int) $row['id'];
            if (isset($seen[$id]) || $row['menu_conflict_json'] !== null) {
                return true;
            }
            $seen[$id] = true;
            $defaults = $row['upstream_defaults_json'];
            if (is_string($defaults)) {
                $defaults = json_decode($defaults, true, 512, JSON_THROW_ON_ERROR);
            }
            if (!is_array($defaults)) {
                return true;
            }
            foreach ($defaults as $field => $expected) {
                $current = $row[$field] ?? null;
                if ((is_int($expected) ? (int) $current : $current) !== $expected) {
                    return true;
                }
            }
            if ($row['parent_key'] !== ($byId[(int) ($defaults['pid'] ?? 0)]['menu_key'] ?? null)) {
                return true;
            }
            $parent = (int) $row['pid'];
            if ($parent === 0 || !isset($byId[$parent])) {
                return false;
            }
            $row = $byId[$parent];
        }
    }

    public function replacePermissionBinding(string $key, ?string $permission, ?string $action, string $revision, \app\common\contract\module\ModuleQualificationQuery $modules): array
    {
        return Db::transaction(function () use ($key, $permission, $action, $revision, $modules): array {
            // Lock before reading the revision, including competing page/action identities.
            $items = $this->permissionBindings(true);
            $before = array_column($items, null, 'menu_key')[$key] ?? null;
            if ($before === null) {
                throw AdminAccessException::notFound();
            }
            if (!hash_equals($before['revision'], $revision)) {
                throw AdminAccessException::revisionMismatch();
            }
            $legacy = Db::name('system_menu')->where('menu_key', $key)->lock(true)->find();
            $native = MenuDefinition::where('key', $key)->where('scope', 'tenant')->lock(true)->find();
            if (($before['type'] === 'A' && ($action === null || trim($action) === '' || $before['page_path'] === ''))
                || ($before['type'] === 'C' && $action !== null)) {
                throw AdminAccessException::invalid('MENU_BINDING_INVALID', 'An action needs a page and an action key.');
            }
            if ($before['type'] === 'A' && $before['action_key'] !== '' && $before['action_key'] !== $action) {
                throw AdminAccessException::invalid('MENU_BINDING_INVALID', 'An existing action identity cannot be changed.');
            }
            if ($legacy !== null && $permission !== null && strlen($permission) > 100) {
                throw AdminAccessException::invalid('MENU_BINDING_INVALID', 'The permission key is too long for this menu.');
            }
            $definition = null;
            if ($permission !== null) {
                $definition = Db::name('permission')->where('key', $permission)->where('status', 'active')->lock(true)->find();
                $owner = $definition['module_key'] ?? null;
                if ($definition === null || $definition['key'] !== $permission || str_starts_with($permission, 'platform.')
                    || in_array($owner, ['platform', 'instance.platform'], true)
                    || in_array($permission, InstanceControlPlanePolicy::tenantAdminPermissions(), true)
                    || ($owner === 'core' && !in_array($permission, CorePermissionCatalog::TENANT, true))) {
                    throw AdminAccessException::invalid('MENU_BINDING_INVALID', 'An active tenant permission is required.');
                }
                if (!in_array($owner, ['core', 'peanut.admin', 'instance.tenant'], true)
                    && !in_array($owner, array_map(static fn($module): string => $module->moduleKey, $modules->installedModules()), true)) {
                    throw AdminAccessException::invalid('MENU_BINDING_INVALID', 'The permission module is not installed.');
                }
            }
            $effective = $permission ?? $before['default_permission_key'];
            if (!is_string($effective)) {
                throw AdminAccessException::conflict('MENU_DEFAULT_PERMISSION_UNKNOWN', 'The source default permission is unknown.');
            }
            if ($before['type'] === 'A' && $effective !== '') {
                foreach ($items as $item) {
                    if ($item['menu_key'] !== $key && $item['type'] === 'A' && $item['page_path'] === $before['page_path']
                        && $item['action_key'] === $action && $item['permission_key'] !== '' && $item['permission_key'] !== $effective) {
                        throw AdminAccessException::conflict('ACTION_PERMISSION_BINDING_CONFLICT', 'A page action can reference only one permission.');
                    }
                }
            }
            if ($native !== null) {
                $native->save(['instance_permission_id' => $permission === null || $permission === $before['default_permission_key'] ? null : (int) $definition['id']]);
            }
            if ($legacy !== null) {
                $changes = ['perms' => $effective];
                if ($before['type'] === 'A') {
                    $changes['paths'] = $action;
                }
                Db::name('system_menu')->where('id', $legacy['id'])->update($changes);
            }
            return ['before' => $before, 'after' => array_column($this->permissionBindings(true), null, 'menu_key')[$key]];
        });
    }
}
