<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Contract;

use app\common\execution\CurrentExecutionContext;
use PeanutAdmin\Modules\Identity\Audit\AuditService;
use PeanutAdmin\Modules\Identity\Persistence\Model\MemberRole;
use PeanutAdmin\Modules\Identity\Persistence\Model\Tenant;
use PeanutAdmin\Modules\Identity\Persistence\Model\TenantMember;
use PeanutAdmin\Modules\Identity\Persistence\Model\Permission;
use PeanutAdmin\Modules\Identity\Persistence\Model\RolePermission;
use think\db\Raw;
use think\facade\Db;

/** 仅供已建立受信上下文的应用所有者初始化；普通角色变更仍走 RoleAdminService。 */
final readonly class TenantAuthorizationCommands
{
    public function __construct(
        private CurrentExecutionContext $execution,
        private AuditService $audit,
    ) {}

    public function grantActiveModulePermissions(
        int $tenantId,
        int $ownerMemberId,
        int $ownerRoleId,
        string $moduleKey,
    ): void {
        if (min($tenantId, $ownerMemberId, $ownerRoleId) < 1 || $moduleKey !== 'peanut.admin') {
            throw new \DomainException('TENANT_AUTHORIZATION_BOOTSTRAP_INPUT_INVALID');
        }
        $system = $this->execution->system();
        if ($system->tenantId !== $tenantId || $system->actorKey !== 'platform.tenant-bootstrap'
            || $system->operation !== 'identity.bootstrap-owner-permissions') {
            throw new \DomainException('TENANT_AUTHORIZATION_BOOTSTRAP_CONTEXT_INVALID');
        }
        Db::transaction(function () use ($tenantId, $ownerMemberId, $ownerRoleId, $moduleKey, $system): void {
            // 首位所有者邀请会在租户仍为 provisioning 时初始化；暂停状态不允许初始化。
            $owner = TenantMember::alias('member')
                ->join('tenant tenant', 'tenant.id = member.tenant_id')
                ->join('account account', "account.id = member.account_id AND account.status = 'active'")
                ->join('member_role membership', 'membership.tenant_id = member.tenant_id AND membership.tenant_member_id = member.id')
                ->join('role role', "role.tenant_id = membership.tenant_id AND role.id = membership.role_id AND role.`key` = 'core.tenant-owner' AND role.is_builtin = 1 AND role.status = 'active'")
                ->where('member.tenant_id', $tenantId)->where('member.id', $ownerMemberId)
                ->where('member.status', 'active')->where('role.id', $ownerRoleId)
                ->whereIn('tenant.status', ['provisioning', 'active'])->lock(true)->value('member.id');
            if ($owner === null) {
                throw new \DomainException('TENANT_AUTHORIZATION_BOOTSTRAP_OWNER_INVALID');
            }
            $permissionIds = array_map('intval', Permission::where('module_key', $moduleKey)
                ->where('status', 'active')->order('id')->column('id'));
            $existing = $permissionIds === [] ? [] : array_map('intval', RolePermission::where('tenant_id', $tenantId)
                ->where('role_id', $ownerRoleId)
                ->whereIn('permission_id', $permissionIds)->column('permission_id'));
            $missing = array_values(array_diff($permissionIds, $existing));
            if ($missing === []) {
                return;
            }
            (new RolePermission())->saveAll(array_map(static fn(int $permissionId): array => [
                'tenant_id' => $tenantId,
                'role_id' => $ownerRoleId,
                'permission_id' => $permissionId,
                'granted_by_member_id' => $ownerMemberId,
                'granted_at' => new Raw('UTC_TIMESTAMP(3)'),
            ], $missing));
            $members = MemberRole::where('tenant_id', $tenantId)->where('role_id', $ownerRoleId)->column('tenant_member_id');
            TenantMember::where('tenant_id', $tenantId)->whereIn('id', $members)
                ->update(['authorization_revision' => new Raw('authorization_revision + 1')]);
            Tenant::where('id', $tenantId)->update(['authorization_revision' => new Raw('authorization_revision + 1')]);
            $this->audit->tenantSystem($tenantId, 'identity.owner-permissions.bootstrapped', $system->operation, $system->operationId, [
                'owner_member_id' => $ownerMemberId,
                'owner_role_id' => $ownerRoleId,
                'module_key' => $moduleKey,
                'permission_ids' => $missing,
            ]);
        });
    }
}
