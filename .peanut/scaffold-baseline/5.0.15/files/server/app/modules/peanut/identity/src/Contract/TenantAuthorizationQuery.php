<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Contract;

use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Modules\Identity\Persistence\Model\MemberRole;
use PeanutAdmin\Modules\Identity\Persistence\Model\Permission;
use PeanutAdmin\Modules\Identity\Persistence\Model\RolePermission;
use PeanutAdmin\Modules\Identity\Persistence\Model\Tenant;
use think\facade\Db;

/** 公开授权投影；人员权限查询逐次复核原生会话与修订，不缓存跨请求身份。 */
final readonly class TenantAuthorizationQuery
{
    public function __construct(private TenantMemberDirectory $members) {}

    /** @param list<string> $moduleKeys @return list<string> */
    public function registeredPermissionKeys(array $moduleKeys): array
    {
        return array_values(array_map('strval', Permission::where('status', 'active')
            ->whereIn('module_key', array_values(array_unique($moduleKeys)))
            ->distinct(true)->order('key')->column('key')));
    }

    /**
     * 只读取调用方已选菜单引用的定义元数据；不存在与停用必须区分，不授予人员权限。
     * 以有界批次查询；数据库负责引用相等性，避免大小写/排序规则差异把停用定义误判成未登记。
     * 输入键映射到规范键；缺失项为空缺，存储失败原样传播，不返回SQL或查询对象。
     * @param list<string> $permissionKeys
     * @return array<string,array{key:string,module_key:string|null,status:string}>
     */
    public function permissionStates(array $permissionKeys): array
    {
        foreach ($permissionKeys as $key) {
            if (!is_string($key) || $key === '') {
                throw new \InvalidArgumentException('PERMISSION_KEY_INVALID');
            }
        }
        $states = [];
        if ($permissionKeys === []) {
            return $states;
        }
        $table = (new Permission())->getTable();
        if (!is_string($table) || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $table) !== 1) {
            throw new \LogicException('PERMISSION_TABLE_INVALID');
        }
        foreach (array_chunk(array_values(array_unique($permissionKeys)), 500) as $keys) {
            $references = implode(' UNION ALL ', array_fill(0, count($keys), 'SELECT ? AS input_key'));
            // Fixed owner-side join; every caller value is bound, never interpolated into SQL.
            $rows = Db::query('SELECT selected.input_key, permission.`key`, permission.module_key, permission.status '
                . 'FROM (' . $references . ') selected JOIN `' . $table . '` permission '
                . 'ON permission.`key` = selected.input_key ORDER BY permission.`key`', $keys);
            foreach ($rows as $row) {
                $states[(string) $row['input_key']] = [
                    'key' => (string) $row['key'],
                    'module_key' => $row['module_key'] === null ? null : (string) $row['module_key'],
                    'status' => (string) $row['status'],
                ];
            }
        }
        ksort($states, SORT_STRING);
        return $states;
    }

    /** @return list<string> */
    public function applicationPermissionKeys(TenantContext $context): array
    {
        if ($this->members->current($context) === null) {
            return [];
        }
        return array_values(array_map('strval', Tenant::alias('tenant')
            ->join('tenant_member member', "member.tenant_id=tenant.id AND member.status='active'")
            ->join('member_role membership', 'membership.tenant_id=tenant.id AND membership.tenant_member_id=member.id')
            ->join('role role', "role.tenant_id=tenant.id AND role.id=membership.role_id AND role.status='active'")
            ->join('role_permission binding', 'binding.tenant_id=tenant.id AND binding.role_id=role.id')
            ->join('permission permission', "permission.id=binding.permission_id AND permission.module_key='peanut.admin' AND permission.status='active'")
            ->where('tenant.id', $context->tenantId)->where('tenant.status', 'active')->where('member.id', $context->memberId)
            ->distinct(true)->order('permission.key')->column('permission.key')));
    }

    public function isTenantOwner(TenantContext $context): bool
    {
        if ($this->members->current($context) === null) {
            return false;
        }
        return MemberRole::alias('membership')
            ->join('role role', "role.tenant_id=membership.tenant_id AND role.id=membership.role_id AND role.`key`='core.tenant-owner' AND role.is_builtin=1 AND role.status='active'")
            ->where('membership.tenant_id', $context->tenantId)
            ->where('membership.tenant_member_id', $context->memberId)
            ->value('membership.tenant_member_id') !== null;
    }

    /** @return list<array{id:int,key:string,name:string,is_builtin:bool}> */
    public function roles(int $tenantId, int $memberId): array
    {
        $rows = MemberRole::alias('membership')
            ->join('role role', "role.tenant_id=membership.tenant_id AND role.id=membership.role_id AND role.status='active'")
            ->where('membership.tenant_id', $tenantId)
            ->where('membership.tenant_member_id', $memberId)
            ->field('role.id,role.key,role.name,role.is_builtin')
            ->order('role.key')->order('role.id')->select()->toArray();

        return array_map(static fn(array $row): array => [
            'id' => (int) $row['id'],
            'key' => (string) $row['key'],
            'name' => (string) $row['name'],
            'is_builtin' => (int) $row['is_builtin'] === 1,
        ], $rows);
    }

    public function roleMemberCount(int $tenantId, int $roleId): int
    {
        return MemberRole::where('tenant_id', $tenantId)->where('role_id', $roleId)->count();
    }

    /**
     * Counts include roles without memberships, using the same membership semantics as roleMemberCount().
     * @param list<int> $roleIds
     * @return array<int,int>
     */
    public function batchRoleMemberCounts(int $tenantId, array $roleIds): array
    {
        if ($roleIds === []) {
            return [];
        }
        foreach ($roleIds as $roleId) {
            if (!is_int($roleId) || $roleId < 1) {
                throw new \InvalidArgumentException('ROLE_ID_INVALID');
            }
        }
        $counts = array_fill_keys(array_values(array_unique($roleIds)), 0);
        $rows = MemberRole::where('tenant_id', $tenantId)->whereIn('role_id', array_keys($counts))
            ->field('role_id')->fieldRaw('COUNT(*) AS member_count')->group('role_id')->select()->toArray();
        foreach ($rows as $row) {
            $counts[(int) $row['role_id']] = (int) $row['member_count'];
        }
        return $counts;
    }

    public function permissionAssigned(string $permission): bool
    {
        return RolePermission::alias('role_permission')
            ->join('permission permission', 'permission.id = role_permission.permission_id')
            ->where('permission.key', $permission)->count() > 0;
    }
}
