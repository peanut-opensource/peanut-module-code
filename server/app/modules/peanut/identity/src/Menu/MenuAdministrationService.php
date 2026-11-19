<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Menu;

use DateTimeImmutable;
use DateTimeZone;
use PeanutAdmin\Kernel\Authorization\Application\AdminAccessException;
use PeanutAdmin\Modules\Identity\Authorization\CorePermissionCatalog;
use PeanutAdmin\Modules\Identity\Platform\InstanceControlPlanePolicy;
use PeanutAdmin\Modules\Identity\Persistence\Model\MenuDefinition;
use think\facade\Db;

/** Single-table menu administration backed by pa_system_menu. */
final class MenuAdministrationService
{
    /** @return list<array<string,mixed>> */
    public function records(string $scope = 'tenant', bool $lock = false): array
    {
        $query = MenuDefinition::alias('menu')
            ->leftJoin('permission p', 'p.id=menu.required_permission_id')
            ->leftJoin('permission custom', 'custom.id=menu.instance_permission_id')
            ->where('menu.scope', $scope)
            ->field('menu.*,p.key AS required_permission,custom.key AS instance_permission')
            ->order('menu.sort_order')
            ->order('menu.key');
        if ($lock) {
            $query->lock(true);
        }
        return $query->select()->toArray();
    }

    /** @param array<string,mixed> $attributes */
    public function createCustom(array $attributes): void
    {
        Db::transaction(function () use ($attributes): void {
            $key = (string) ($attributes['key'] ?? ('custom.' . bin2hex(random_bytes(16))));
            $this->assertParent($key, $attributes['parent_key'] ?? null);
            $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.000');
            $type = $this->normalizeType((string) ($attributes['type'] ?? 'C'));
            MenuDefinition::create([
                'key' => $key,
                'module_key' => 'application',
                'source' => 'custom',
                'scope' => 'tenant',
                'parent_key' => $attributes['parent_key'] ?? null,
                'type' => $type,
                'name' => (string) ($attributes['name'] ?? ''),
                'route_name' => $type === 'page' ? $key : null,
                'route_path' => (string) ($attributes['paths'] ?? ''),
                'component_key' => $type === 'page' ? (string) ($attributes['component'] ?? '') : null,
                'icon' => (string) ($attributes['icon'] ?? ''),
                'sort_order' => -(int) ($attributes['sort'] ?? 0),
                'required_permission_id' => $attributes['required_permission_id'] ?? null,
                'instance_permission_id' => $attributes['instance_permission_id'] ?? null,
                'client_keys_json' => '[]',
                'status' => 'active',
                'manifest_digest' => str_repeat('0', 64),
                'is_show' => (int) ($attributes['is_show'] ?? 1),
                'is_cache' => (int) ($attributes['is_cache'] ?? 0),
                'is_disable' => (int) ($attributes['is_disable'] ?? 0),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });
    }

    /** @param array<string,mixed> $presentation */
    public function updatePresentation(string $key, array $presentation): void
    {
        Db::transaction(function () use ($key, $presentation): void {
            $menu = MenuDefinition::where('key', $key)->where('scope', 'tenant')->lock(true)->find();
            if ($menu === null) {
                throw new \DomainException('ADMIN_MENU_NOT_FOUND');
            }
            $this->assertParent($key, $presentation['parent_key'] ?? null);
            $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.000');
            $changes = [
                'name' => $presentation['name'],
                'icon' => $presentation['icon'] ?? '',
                'sort_order' => -(int) ($presentation['sort'] ?? 0),
                'parent_key' => $presentation['parent_key'] ?? null,
                'is_show' => (int) ($presentation['is_show'] ?? 1),
                'is_cache' => (int) ($presentation['is_cache'] ?? 0),
                'is_disable' => (int) ($presentation['is_disable'] ?? 0),
                'instance_permission_id' => $presentation['instance_permission_id'] ?? null,
                'updated_at' => $now,
            ];
            if ($menu->getAttr('source') === 'custom') {
                if (isset($presentation['type'])) {
                    $changes['type'] = $this->normalizeType((string) $presentation['type']);
                }
                if (array_key_exists('paths', $presentation)) {
                    $changes['route_path'] = (string) ($presentation['paths'] ?? '');
                }
                if (array_key_exists('component', $presentation)) {
                    $changes['component_key'] = $presentation['component'] ?: null;
                }
            }
            $menu->save($changes);
        });
    }

    public function deleteCustom(string $key): void
    {
        Db::transaction(function () use ($key): void {
            $menu = MenuDefinition::where('key', $key)->where('scope', 'tenant')->lock(true)->find();
            if ($menu === null) {
                throw new \DomainException('ADMIN_MENU_NOT_FOUND');
            }
            if ($menu->getAttr('source') !== 'custom') {
                throw new \DomainException('MANAGED_MENU_DELETE_FORBIDDEN');
            }
            $menu->delete();
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
        $records = $this->records('tenant', $lock);
        $rows = [];
        foreach ($records as $row) {
            $rows[$row['key']] = [
                'menu_key' => $row['key'],
                'parent_key' => $row['parent_key'],
                'type' => match ($row['type']) {
                    'group' => 'M',
                    'action' => 'A',
                    default => 'C',
                },
                'name' => $row['name'],
                'path' => $row['route_path'] ?? '',
                'permission_key' => $row['instance_permission'] ?? $row['required_permission'] ?? '',
                'default_permission_key' => $row['required_permission'] ?? '',
            ];
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
            $item = [
                'menu_key' => $row['menu_key'],
                'parent_key' => $row['parent_key'],
                'type' => $row['type'],
                'name' => $row['name'],
                'page_path' => $pagePath,
                'action_key' => $row['type'] === 'A' ? $row['path'] : '',
                'permission_key' => $row['permission_key'],
                'default_permission_key' => $row['default_permission_key'],
            ];
            $item['revision'] = hash('sha256', json_encode($item, JSON_THROW_ON_ERROR));
            $items[] = $item;
        }
        return $items;
    }

    public function replacePermissionBinding(string $key, ?string $permission, ?string $action, string $revision, \app\common\contract\module\ModuleQualificationQuery $modules): array
    {
        return Db::transaction(function () use ($key, $permission, $action, $revision, $modules): array {
            $items = $this->permissionBindings(true);
            $before = array_column($items, null, 'menu_key')[$key] ?? null;
            if ($before === null) {
                throw AdminAccessException::notFound();
            }
            if (!hash_equals($before['revision'], $revision)) {
                throw AdminAccessException::revisionMismatch();
            }
            $native = MenuDefinition::where('key', $key)->where('scope', 'tenant')->lock(true)->find();
            if ($native === null) {
                throw AdminAccessException::notFound();
            }
            if (($before['type'] === 'A' && ($action === null || trim($action) === '' || $before['page_path'] === ''))
                || ($before['type'] === 'C' && $action !== null)) {
                throw AdminAccessException::invalid('MENU_BINDING_INVALID', 'An action needs a page and an action key.');
            }
            if ($before['type'] === 'A' && $before['action_key'] !== '' && $before['action_key'] !== $action) {
                throw AdminAccessException::invalid('MENU_BINDING_INVALID', 'An existing action identity cannot be changed.');
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
            $changes = [
                'instance_permission_id' => $permission === null || $permission === $before['default_permission_key']
                    ? null
                    : (int) $definition['id'],
            ];
            if ($before['type'] === 'A' && $action !== null) {
                $changes['route_path'] = $action;
            }
            $native->save($changes);
            return ['before' => $before, 'after' => array_column($this->permissionBindings(true), null, 'menu_key')[$key]];
        });
    }

    private function assertParent(string $key, ?string $parentKey): void
    {
        $seen = [$key => true];
        while ($parentKey !== null) {
            if (isset($seen[$parentKey])) {
                throw new \DomainException('ADMIN_MENU_PARENT_CYCLE');
            }
            $seen[$parentKey] = true;
            $parent = MenuDefinition::where('key', $parentKey)->where('scope', 'tenant')->lock(true)->find();
            if ($parent === null || $parent->getAttr('type') === 'action') {
                throw new \DomainException('ADMIN_MODULE_MENU_PARENT_INVALID');
            }
            $parentKey = $parent->getAttr('parent_key');
        }
    }

    private function normalizeType(string $type): string
    {
        return match ($type) {
            'M', 'group' => 'group',
            'A', 'action' => 'action',
            'link' => 'link',
            default => 'page',
        };
    }
}
