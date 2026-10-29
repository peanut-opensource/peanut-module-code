<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Authorization;

use JsonException;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Modules\Identity\Module\Model\TenantModule;
use PeanutAdmin\Modules\Identity\Persistence\Model\Account;
use PeanutAdmin\Modules\Identity\Persistence\Model\MemberRole;
use PeanutAdmin\Modules\Identity\Persistence\Model\Permission;
use PeanutAdmin\Modules\Identity\Persistence\Model\Role;
use PeanutAdmin\Modules\Identity\Persistence\Model\RolePermission;
use PeanutAdmin\Modules\Identity\Persistence\Model\Tenant;
use PeanutAdmin\Modules\Identity\Persistence\Model\TenantMember;
use PeanutAdmin\Modules\Identity\Persistence\Model\TenantSession;
use think\db\Raw;
use think\facade\Db;

final class ThinkPhpTenantAuthorizationRepository implements \PeanutAdmin\Kernel\Authorization\TenantAuthorizationRepository
{
    private const AVAILABLE_MODULE_CONDITION = "tenant_module.status = 'enabled' AND installation.status = 'active'"
        . ' AND (tenant_module.effective_at IS NULL OR tenant_module.effective_at <= UTC_TIMESTAMP(3))'
        . ' AND (tenant_module.expires_at IS NULL OR tenant_module.expires_at > UTC_TIMESTAMP(3))';

    /** Native authority inputs only; the caller retains the existing permission decision. */
    public function readFence(TenantContext $context, array $permissionKeys, array $moduleKeys, bool $lock): string
    {
        if (count($permissionKeys) < 1 || count($permissionKeys) > 32 || count($moduleKeys) < 1 || count($moduleKeys) > 16) {
            throw new \InvalidArgumentException('IDENTITY_AUTHORIZATION_FENCE_INPUT_INVALID');
        }
        foreach ([...$permissionKeys, ...$moduleKeys] as $key) {
            if (!is_string($key) || preg_match('/^[a-z][a-z0-9_.-]{1,95}$/D', $key) !== 1) {
                throw new \InvalidArgumentException('IDENTITY_AUTHORIZATION_FENCE_INPUT_INVALID');
            }
        }
        $permissionKeys = array_values(array_unique($permissionKeys));
        $moduleKeys = array_values(array_unique($moduleKeys));
        sort($permissionKeys, SORT_STRING);
        sort($moduleKeys, SORT_STRING);
        $read = static fn($query): array => $query->lock($lock)->select()->toArray();
        $mappingQuery = static fn() => Db::name('plugin_module')->whereIn('module_key', $moduleKeys)
            ->field('module_key,plugin_key,manifest_digest')->order('module_key');
        $mapping = $mappingQuery()->select()->toArray();
        $packages = array_values(array_unique(array_column($mapping, 'plugin_key')));
        sort($packages, SORT_STRING);
        // lockAndAssert first holds the existing lifecycle package -> installation locks.
        $facts = [
            'packages' => $read(Db::name('plugin_installation')->whereIn('plugin_key', $packages)
                ->field('plugin_key,status,revision')->order('plugin_key')),
            'installations' => $read(Db::name('module_installation')->whereIn('module_key', $moduleKeys)
                ->field('module_key,status,revision,installed_version,manifest_digest')->order('module_key')),
        ];
        if ($lock) {
            $mapping = $read($mappingQuery());
            $currentPackages = array_values(array_unique(array_column($mapping, 'plugin_key')));
            sort($currentPackages, SORT_STRING);
            if ($packages !== $currentPackages) {
                throw new \DomainException('IDENTITY_AUTHORIZATION_PROOF_STALE');
            }
        }
        $facts['mapping'] = $mapping;
        $facts['tenant'] = $read(Tenant::where('id', $context->tenantId)->field('id,status,security_revision,authorization_revision'));
        $facts['account'] = $read(Account::where('id', $context->accountId)->field('id,status,security_revision'));
        $facts['member'] = $read(TenantMember::where('tenant_id', $context->tenantId)->where('id', $context->memberId)
            ->field('id,tenant_id,account_id,status,security_revision,authorization_revision'));
        $facts['memberships'] = $read(MemberRole::where('tenant_id', $context->tenantId)->where('tenant_member_id', $context->memberId)
            ->field('role_id')->order('role_id'));
        $roleIds = array_column($facts['memberships'], 'role_id');
        if (count($roleIds) > 64) {
            throw new \DomainException('IDENTITY_AUTHORIZATION_FENCE_ROLE_LIMIT');
        }
        $facts['roles'] = $read(Role::where('tenant_id', $context->tenantId)->whereIn('id', $roleIds)
            ->field('id,key,status,is_builtin,authorization_revision')->order('id'));
        $facts['permissions'] = $read(Permission::whereIn('key', $permissionKeys)
            ->field('id,key,module_key,status')->order('id'));
        $facts['grants'] = $read(RolePermission::where('tenant_id', $context->tenantId)->whereIn('role_id', $roleIds)
            ->whereIn('permission_id', array_column($facts['permissions'], 'id'))
            ->field('role_id,permission_id')->order('role_id,permission_id'));
        $facts['modules'] = $read(TenantModule::where('tenant_id', $context->tenantId)->whereIn('module_key', $moduleKeys)
            ->field('module_key,status,authorization_revision,effective_at,expires_at')->order('module_key'));
        $sessionQuery = static fn() => TenantSession::where('tenant_id', $context->tenantId)
            ->where('account_id', $context->accountId)->where('tenant_member_id', $context->memberId)
            ->where('session_key', $context->sessionKey)->where('client_key', $context->clientKey);
        $facts['session'] = $read($sessionQuery()
            ->field('id,status,client_key,account_security_revision,tenant_security_revision,member_security_revision,idle_expires_at,absolute_expires_at'));
        // Current database-clock conditions detect expiry without requiring a revision change.
        $facts['effective_modules'] = $read(TenantModule::where('tenant_id', $context->tenantId)->whereIn('module_key', $moduleKeys)
            ->where('status', 'enabled')->where(fn($q) => $q->whereNull('effective_at')->whereOr('effective_at', '<=', new Raw('UTC_TIMESTAMP(3)')))
            ->where(fn($q) => $q->whereNull('expires_at')->whereOr('expires_at', '>', new Raw('UTC_TIMESTAMP(3)')))
            ->field('module_key')->order('module_key'));
        $facts['session_current'] = $read($sessionQuery()->where('status', 'active')
            ->where('idle_expires_at', '>', new Raw('UTC_TIMESTAMP(3)'))
            ->where('absolute_expires_at', '>', new Raw('UTC_TIMESTAMP(3)'))->field('id'));

        return hash('sha256', json_encode($facts, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    public function member(int $tenantId, int $memberId): ?array
    {
        $record = TenantMember::where('tenant_id', $tenantId)
            ->where('id', $memberId)
            ->field('id,display_name,status,primary_department_id')
            ->find();
        $row = $record?->toArray();

        return $row === null ? null : [
            'id' => (int) $row['id'],
            'display_name' => is_string($row['display_name']) ? $row['display_name'] : null,
            'status' => (string) $row['status'],
            'primary_department_id' => $row['primary_department_id'] === null
                ? null
                : (int) $row['primary_department_id'],
        ];
    }

    public function activeRoles(int $tenantId, int $memberId): array
    {
        $rows = TenantMember::alias('member')
            ->join(
                'member_role member_role',
                'member_role.tenant_id = member.tenant_id AND member_role.tenant_member_id = member.id',
            )
            ->join(
                'role role',
                "role.tenant_id = member_role.tenant_id AND role.id = member_role.role_id AND role.status = 'active'",
            )
            ->where('member.tenant_id', $tenantId)
            ->where('member.id', $memberId)
            ->where('member.status', 'active')
            ->field(['role.id', 'role.key', 'role.name', 'role.is_builtin'])
            ->order('role.key')
            ->order('role.id')
            ->select()
            ->toArray();

        return array_values(array_map(static fn(array $row): array => [
            'id' => (int) $row['id'],
            'key' => (string) $row['key'],
            'name' => (string) $row['name'],
            'is_builtin' => (int) $row['is_builtin'] === 1,
        ], $rows));
    }

    public function revision(int $tenantId, int $memberId): string
    {
        $tenantRecord = Tenant::where('id', $tenantId)
            ->field('status,authorization_revision')
            ->find();
        $tenant = $tenantRecord?->toArray();
        if ($tenant === null) {
            return hash('sha256', "missing:{$tenantId}:{$memberId}");
        }
        $memberRecord = TenantMember::where('tenant_id', $tenantId)
            ->where('id', $memberId)
            ->field('status,authorization_revision')
            ->find();
        $member = $memberRecord?->toArray();
        $roles = MemberRole::alias('member_role')
            ->join('role role', 'role.tenant_id = member_role.tenant_id AND role.id = member_role.role_id')
            ->where('member_role.tenant_id', $tenantId)
            ->where('member_role.tenant_member_id', $memberId)
            ->field('role.id,role.status,role.authorization_revision')
            ->order('role.id')
            ->select()
            ->toArray();
        $modules = TenantModule::alias('tenant_module')
            ->leftJoin('module_installation installation', 'installation.module_key = tenant_module.module_key')
            ->where('tenant_module.tenant_id', $tenantId)
            ->field([
                'tenant_module.module_key', 'tenant_module.status', 'tenant_module.authorization_revision',
                'tenant_module.effective_at', 'tenant_module.expires_at',
                'installation.status' => 'installation_status', 'installation.revision' => 'installation_revision',
            ])
            ->fieldRaw('CASE WHEN ' . self::AVAILABLE_MODULE_CONDITION . ' THEN 1 ELSE 0 END AS permission_available')
            ->order('tenant_module.module_key')
            ->select()
            ->toArray();

        try {
            return hash('sha256', json_encode([
                'tenant' => $tenant,
                'member' => $member,
                'roles' => $roles,
                'modules' => $modules,
            ], JSON_THROW_ON_ERROR));
        } catch (JsonException) {
            return hash('sha256', "invalid:{$tenantId}:{$memberId}");
        }
    }

    public function permissions(int $tenantId, int $memberId): \PeanutAdmin\Kernel\Authorization\EffectivePermissionSet
    {
        $tenantActive = Tenant::where('id', $tenantId)->where('status', 'active')->value('id');
        if ($tenantActive === null) {
            return new \PeanutAdmin\Kernel\Authorization\EffectivePermissionSet([]);
        }

        $roles = $this->activeRoles($tenantId, $memberId);
        $roleIds = array_column($roles, 'id');
        $isTenantOwner = false;
        foreach ($roles as $role) {
            $isTenantOwner = $isTenantOwner
                || ($role['key'] === 'core.tenant-owner' && $role['is_builtin'] === true);
        }
        $permissions = [];
        if ($roleIds !== []) {
            $availableModules = TenantModule::alias('tenant_module')
                ->join(
                    'module_installation installation',
                    'installation.module_key = tenant_module.module_key',
                )
                ->where('tenant_module.tenant_id', $tenantId)
                ->whereRaw(self::AVAILABLE_MODULE_CONDITION)
                ->column('tenant_module.module_key');
            $permissions = RolePermission::alias('role_permission')
                ->join('permission permission', "permission.id = role_permission.permission_id AND permission.status = 'active'")
                ->where('role_permission.tenant_id', $tenantId)
                ->whereIn('role_permission.role_id', $roleIds)
                ->whereNotLike('permission.key', 'platform.%')
                ->whereIn('permission.module_key', array_values(array_unique(['core', ...$availableModules])))
                ->distinct(true)
                ->order('permission.key')
                ->column('permission.key');
        }

        if ($isTenantOwner) {
            $permissions = [...$permissions, ...CorePermissionCatalog::TENANT];
        }

        return new \PeanutAdmin\Kernel\Authorization\EffectivePermissionSet(array_map('strval', $permissions));
    }
}
