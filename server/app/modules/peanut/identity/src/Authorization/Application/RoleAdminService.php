<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Authorization\Application;

use DateTimeImmutable;
use DateTimeZone;
use PeanutAdmin\Modules\Identity\Audit\AuditService;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Modules\Identity\Module\Model\TenantModule;
use PeanutAdmin\Modules\Identity\Persistence\Model\Permission;
use PeanutAdmin\Modules\Identity\Persistence\Model\Role;
use PeanutAdmin\Modules\Identity\Persistence\Model\RolePermission;
use PeanutAdmin\Modules\Identity\Persistence\Model\Tenant;
use Throwable;
use think\db\Raw;
use think\facade\Db;

final readonly class RoleAdminService
{
    public function __construct(private AuditService $audit) {}

    /** @return array{items: list<array<string, mixed>>, total: int} */
    public function list(int $tenantId, \PeanutAdmin\Kernel\Authorization\Application\PageRequest $page): array
    {
        $query = Role::where('tenant_id', $tenantId);
        $total = (int) (clone $query)->count();
        $rows = $query->field('id,key,name,description,is_builtin,status,authorization_revision,created_at')
            ->order('id')->limit($page->offset(), $page->pageSize)->select()->toArray();

        return ['items' => $this->hydratePermissions($tenantId, array_values($rows)), 'total' => $total];
    }

    /** @return array<string, mixed> */
    public function get(int $tenantId, int $roleId): array
    {
        $row = Role::where('tenant_id', $tenantId)->where('id', $roleId)
            ->field('id,key,name,description,is_builtin,status,authorization_revision,created_at')->find()?->toArray();
        if ($row === null) {
            throw \PeanutAdmin\Kernel\Authorization\Application\AdminAccessException::notFound();
        }

        return $this->hydratePermissions($tenantId, [$row])[0];
    }

    /** @return array{items: list<array<string, mixed>>, total: int} */
    public function permissionCatalog(int $tenantId, \PeanutAdmin\Kernel\Authorization\Application\PageRequest $page, string $search = ''): array
    {
        $query = $this->assignablePermissionQuery($tenantId);
        if ($search !== '') {
            $query->whereLike('key|name', '%' . $search . '%');
        }
        $total = (int) (clone $query)->count();
        $items = $query->field('key,name,module_key')->order('module_key,key')
            ->limit($page->offset(), $page->pageSize)->select()->toArray();
        return ['items' => array_values($items), 'total' => $total];
    }

    /** @return array<string, mixed> */
    public function create(TenantContext $actor, string $key, string $name, ?string $description): array
    {
        if (str_starts_with($key, 'core.') || str_starts_with($key, 'platform.')) {
            throw \PeanutAdmin\Kernel\Authorization\Application\AdminAccessException::invalid('ROLE_KEY_RESERVED', 'The role key uses a reserved namespace.');
        }

        return $this->transaction(function () use ($actor, $key, $name, $description): array {
            $this->lockTenant($actor->tenantId);
            $now = $this->now();
            $roleId = (int) Role::insertGetId([
                'tenant_id' => $actor->tenantId,
                'key' => $key,
                'name' => $name,
                'description' => $description,
                'is_builtin' => 0,
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->bumpTenant($actor->tenantId, $now);
            $this->recordAudit($actor, 'tenant.role.created', 'core.role.create', $roleId);

            return $this->get($actor->tenantId, $roleId);
        });
    }

    /** @return array<string, mixed> */
    public function update(
        TenantContext $actor,
        int $roleId,
        string $name,
        ?string $description,
        int $expectedRevision,
    ): array {
        return $this->transaction(function () use ($actor, $roleId, $name, $description, $expectedRevision): array {
            $this->lockTenant($actor->tenantId, false);
            $role = $this->requireRole($actor->tenantId, $roleId, true);
            $this->assertRevision($role, $expectedRevision);
            $now = $this->now();
            if (Role::where('tenant_id', $actor->tenantId)->where('id', $roleId)
                ->where('authorization_revision', $expectedRevision)->update([
                    'name' => $name,
                    'description' => $description,
                    'authorization_revision' => new Raw('authorization_revision + 1'),
                    'updated_at' => $now,
                ]) !== 1) {
                throw \PeanutAdmin\Kernel\Authorization\Application\AdminAccessException::revisionMismatch();
            }
            $this->bumpTenant($actor->tenantId, $now);
            $this->recordAudit($actor, 'tenant.role.updated', 'core.role.update', $roleId);

            return $this->get($actor->tenantId, $roleId);
        });
    }

    /** @return array<string, mixed> */
    public function archive(TenantContext $actor, int $roleId, int $expectedRevision): array
    {
        return $this->transaction(function () use ($actor, $roleId, $expectedRevision): array {
            $this->lockTenant($actor->tenantId, false);
            $role = $this->requireRole($actor->tenantId, $roleId, true);
            $this->assertRevision($role, $expectedRevision);
            if ((int) $role['is_builtin'] === 1) {
                throw \PeanutAdmin\Kernel\Authorization\Application\AdminAccessException::conflict('BUILTIN_ROLE_IMMUTABLE', 'Built-in roles cannot be archived.');
            }
            if ($role['status'] === 'archived') {
                throw \PeanutAdmin\Kernel\Authorization\Application\AdminAccessException::conflict('ROLE_ALREADY_ARCHIVED', 'The role is already archived.');
            }
            $now = $this->now();
            if (Role::where('tenant_id', $actor->tenantId)->where('id', $roleId)
                ->where('authorization_revision', $expectedRevision)->update([
                    'status' => 'archived',
                    'archived_at' => $now,
                    'authorization_revision' => new Raw('authorization_revision + 1'),
                    'updated_at' => $now,
                ]) !== 1) {
                throw \PeanutAdmin\Kernel\Authorization\Application\AdminAccessException::revisionMismatch();
            }
            $this->bumpTenant($actor->tenantId, $now);
            $this->recordAudit($actor, 'tenant.role.archived', 'core.role.archive', $roleId);
            $this->recordRoleRevocations(
                $actor,
                $roleId,
                'role_archived',
                ['authorization_revision' => $expectedRevision, 'status' => $role['status']],
                ['authorization_revision' => $expectedRevision + 1, 'status' => 'archived'],
            );

            return $this->get($actor->tenantId, $roleId);
        });
    }

    /** @param list<string> $permissionKeys
     * @return array<string, mixed>
     */
    public function replacePermissions(
        TenantContext $actor,
        int $roleId,
        array $permissionKeys,
        int $expectedRevision,
    ): array {
        $permissionKeys = array_values(array_unique($permissionKeys));

        return $this->transaction(function () use ($actor, $roleId, $permissionKeys, $expectedRevision): array {
            $this->lockTenant($actor->tenantId, false);
            $role = $this->requireRole($actor->tenantId, $roleId, true);
            $this->assertRevision($role, $expectedRevision);
            if ((int) $role['is_builtin'] === 1 && $role['key'] === 'core.tenant-owner') {
                throw \PeanutAdmin\Kernel\Authorization\Application\AdminAccessException::conflict(
                    'TENANT_OWNER_PERMISSIONS_FIXED',
                    'Tenant owner core permissions are fixed by the release catalog.',
                );
            }
            $permissions = $this->assignablePermissions($actor->tenantId, $permissionKeys);
            if (count($permissions) !== count($permissionKeys)) {
                throw \PeanutAdmin\Kernel\Authorization\Application\AdminAccessException::invalid(
                    'PERMISSION_NOT_ASSIGNABLE',
                    'A permission is retired, belongs to the platform, or its module is unavailable.',
                );
            }
            $oldPermissionIds = array_map('intval', RolePermission::where('tenant_id', $actor->tenantId)->where('role_id', $roleId)->order('permission_id')->column('permission_id'));
            $newPermissionIds = array_map(static fn(array $p): int => (int) $p['id'], $permissions);
            RolePermission::where('tenant_id', $actor->tenantId)->where('role_id', $roleId)->delete();
            $now = $this->now();
            if ($permissions !== []) {
                RolePermission::insertAll(array_map(
                    static fn(array $permission): array => [
                        'tenant_id' => $actor->tenantId,
                        'role_id' => $roleId,
                        'permission_id' => (int) $permission['id'],
                        'granted_by_member_id' => $actor->memberId,
                        'granted_at' => $now,
                    ],
                    $permissions,
                ));
            }
            if (Role::where('tenant_id', $actor->tenantId)->where('id', $roleId)
                ->where('authorization_revision', $expectedRevision)->update([
                    'authorization_revision' => new Raw('authorization_revision + 1'),
                    'updated_at' => $now,
                ]) !== 1) {
                throw \PeanutAdmin\Kernel\Authorization\Application\AdminAccessException::revisionMismatch();
            }
            $this->bumpTenant($actor->tenantId, $now);
            $this->recordAudit(
                $actor,
                'tenant.role.permissions-replaced',
                'core.role.permission.assign',
                $roleId,
            );
            if (array_diff($oldPermissionIds, $newPermissionIds) !== []) {
                $this->recordRoleRevocations(
                    $actor,
                    $roleId,
                    'role_permissions_removed',
                    ['authorization_revision' => $expectedRevision, 'permission_ids' => $oldPermissionIds],
                    ['authorization_revision' => $expectedRevision + 1, 'permission_ids' => $newPermissionIds],
                );
            }

            return $this->get($actor->tenantId, $roleId);
        });
    }

    /** @return array<string, mixed> */
    private function requireRole(int $tenantId, int $roleId, bool $forUpdate): array
    {
        $query = Role::where('tenant_id', $tenantId)->where('id', $roleId);
        if ($forUpdate) {
            $query->lock(true);
        }

        return $query->find()?->toArray() ?? throw \PeanutAdmin\Kernel\Authorization\Application\AdminAccessException::notFound();
    }

    /** @param array<string, mixed> $role */
    private function assertRevision(array $role, int $expectedRevision): void
    {
        if ((int) $role['authorization_revision'] !== $expectedRevision) {
            throw \PeanutAdmin\Kernel\Authorization\Application\AdminAccessException::revisionMismatch();
        }
    }

    /** @param list<string> $permissionKeys
     * @return list<array{id: int, key: string}>
     */
    private function assignablePermissions(int $tenantId, array $permissionKeys): array
    {
        if ($permissionKeys === []) {
            return [];
        }
        /** @var list<array{id: int, key: string}> $permissions */
        $permissions = $this->assignablePermissionQuery($tenantId)->whereIn('key', $permissionKeys)
            ->field('id,key')->order('key')->select()->toArray();

        return $permissions;
    }

    private function assignablePermissionQuery(int $tenantId): \think\db\BaseQuery
    {
        $modules = TenantModule::where('tenant_id', $tenantId)->where('status', 'enabled')
            ->where(function ($query): void {
                $query->whereNull('effective_at')->whereOr('effective_at', '<=', new Raw('UTC_TIMESTAMP(3)'));
            })->where(function ($query): void {
                $query->whereNull('expires_at')->whereOr('expires_at', '>', new Raw('UTC_TIMESTAMP(3)'));
            })->column('module_key');

        return Permission::where('status', 'active')
            // Application permissions are shipped by the host, not tenant-enabled Modules.
            ->whereNotLike('key', 'platform.%')->whereIn('module_key', array_values(array_unique(['core', 'peanut.admin', ...$modules])));
    }

    /** @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function hydratePermissions(int $tenantId, array $rows): array
    {
        if ($rows === []) {
            return [];
        }
        $roleIds = array_map('intval', array_column($rows, 'id'));
        $permissionKeys = [];
        foreach (RolePermission::alias('role_permission')
            ->join('permission permission', 'permission.id = role_permission.permission_id')
            ->where('role_permission.tenant_id', $tenantId)->whereIn('role_permission.role_id', $roleIds)
            ->field(['role_permission.role_id', 'permission.key'])->order('permission.key')->select()->toArray() as $permission) {
            $permissionKeys[(int) $permission['role_id']][] = (string) $permission['key'];
        }
        foreach ($rows as &$row) {
            $row = [
                'id' => (string) $row['id'],
                'key' => $row['key'],
                'name' => $row['name'],
                'description' => $row['description'],
                'is_builtin' => (int) $row['is_builtin'] === 1,
                'status' => $row['status'],
                'revision' => (string) $row['authorization_revision'],
                'created_at' => $row['created_at'],
                'permission_keys' => array_values(array_unique($permissionKeys[(int) $row['id']] ?? [])),
            ];
        }
        unset($row);

        return $rows;
    }

    private function lockTenant(int $tenantId, bool $requireActive = true): void
    {
        $query = Tenant::where('id', $tenantId);
        if ($requireActive) {
            $query->where('status', 'active');
        }
        if ($query->lock(true)->value('id') === null) {
            throw new \PeanutAdmin\Kernel\Authorization\Application\AdminAccessException('TENANT_STATUS_INVALID', 403, 'The tenant is not active.');
        }
    }

    private function bumpTenant(int $tenantId, string $now): void
    {
        Tenant::where('id', $tenantId)->update([
            'authorization_revision' => new Raw('authorization_revision + 1'),
            'updated_at' => $now,
        ]);
    }

    private function recordRoleRevocations(TenantContext $actor, int $roleId, string $reason, array $before, array $after): void
    {
        $cursor = 0;
        do {
            $members = \PeanutAdmin\Modules\Identity\Persistence\Model\MemberRole::where('tenant_id', $actor->tenantId)
                ->where('role_id', $roleId)->where('tenant_member_id', '>', $cursor)->order('tenant_member_id')->limit(100)->column('tenant_member_id');
            foreach ($members as $memberId) {
                $cursor = (int) $memberId;
                $this->audit->recordRevocation($actor, $actor->tenantId, $cursor, 'role', (string) $roleId, $reason, $before, $after);
            }
        } while (count($members) === 100);
    }

    private function recordAudit(TenantContext $actor, string $eventType, string $action, int $roleId): void
    {
        $this->audit->tenantMember(
            context: $actor,
            eventType: $eventType,
            action: $action,
            targetResourceType: 'role',
            targetResourceId: (string) $roleId,
        );
    }

    /** @template T
     * @param callable(): T $operation
     * @return T
     */
    private function transaction(callable $operation): mixed
    {
        try {
            return Db::transaction($operation);
        } catch (Throwable $exception) {
            if ((string) $exception->getCode() === '23000') {
                throw \PeanutAdmin\Kernel\Authorization\Application\AdminAccessException::conflict('ROLE_CONFLICT', 'Role key or relation conflicts.');
            }

            throw $exception;
        }
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.v');
    }
}
