# Identity：正式能力复用

`peanut.identity` 是必需核心模块，账号、员工成员、组织、角色、会话和安全审计由它维护；客户档案属于 Member。正式 PHP 导出以 [`module.json`](module.json) 的 `contracts.exports` 为准，容器绑定由 [`ModuleProvider`](src/ModuleProvider.php) 登记。消费者声明模块依赖并构造注入公开合同，不读取 Identity 私表或持久化 Model。

## 成员目录

[`TenantMemberDirectory`](src/Contract/TenantMemberDirectory.php) 返回只读 [`TenantMemberSummary`](src/Contract/Dto/TenantMemberSummary.php)，含租户、成员、账号、显示名称、主部门及安全/授权修订，不返回凭据或 ORM 对象。

| 入口 | 输入与结果 | 边界 |
| --- | --- | --- |
| `current($context)` | 可信 `TenantContext` → 当前成员或 `null` | 核对活动账号、租户、成员、授权修订及未过期会话 |
| `activeMember($context, $memberId)` | 当前上下文及目标成员 ID → 成员或 `null` | 先重验当前操作者，只读取同租户活动成员 |
| `activeMembership($tenantId, $memberId)` | 明确的租户及成员 ID → 成员或 `null` | 供可信 worker 做身份归属；不要求员工在线，不授予业务权限 |
| `activeMembershipCount($accountId)` | 账号 ID → 活动成员关系数量 | 不授予切租户或跨租户访问权 |

最小接入示例（调用前由所属用例验证自己的动作、模块及对象权限）：

```php
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Modules\Identity\Contract\TenantMemberDirectory;
use PeanutAdmin\Modules\Identity\Contract\Dto\TenantMemberSummary;

final readonly class RecipientLookup
{
    public function __construct(private TenantMemberDirectory $members) {}

    public function find(TenantContext $actor, int $memberId): ?TenantMemberSummary
    {
        return $this->members->activeMember($actor, $memberId);
    }
}
```

真实消费者：Notification 的 `Delivery/Identity/TenantMemberRecipientResolver::snapshot` 通过 `activeMember` 生成收件人快照，成员不可用时拒绝；Collaboration 的 `Service/CollaborationService` 也注入此合同。两者随各自模块交付，不是本核心的依赖。目录查询不发送通知；数据库错误沿原边界传播，不能当作“无成员”吞掉。上下文与查询结果只用于本次操作，不能跨请求缓存为永久授权。

## 会话与角色

- [`TenantSessionAccess`](src/Contract/TenantSessionAccess.php)：`revalidateSession` 接收服务端持有的租户、账号、成员、会话键、客户端键、请求 ID，返回重新核验的 `TenantContext`；不得用客户端参数合成身份。`ownedSessions` 返回当前成员自有会话摘要，`revokeOwnedSession` 仅撤销自有会话并事务记录审计、失效令牌。失效身份、未知目标及冲突通过 [`TenantSessionAccessException`](src/Contract/TenantSessionAccessException.php) 区分；调用方按自己的边界处理。
- [`RoleAdminService`](src/Authorization/Application/RoleAdminService.php)：角色查询、创建、修改、归档和权限包替换，使用明确租户或可信上下文。它不是 HTTP 权限中间件；入口仍须验证自己的权限。`replacePermissions` 接收角色 ID、权限键数组及读取到的整数修订，事务检查租户、角色、修订和可分配目录，更新授权修订及审计。所有者权限固定，过期修订和不可分配权限均拒绝。
- 真实角色消费者是宿主 `app/adminapi/controller/auth/RolePermissionController` 与 Web `src/views/system/role/index.vue`，前端正式请求入口为 `web/src/api/system/role.ts` 的 `getRolePermissions`、`getRolePermissionCatalog` 和 `replaceRolePermissions`。HTTP 读取快照含 `role_id/key/revision/permission_keys`；写入携带原 `expected_revision`，不从菜单显示反推授权。目录分页按当前租户已开通模块过滤；页面搜索保留原已选项，固定所有者只读。

完整身份、组织、权限包和数据 Scope 合同见[身份与组织](../../../../../docs/architecture/identity-organization-access.md)，HTTP/SDK 合同见 [API 与 SDK](../../../../../docs/public/api-and-sdk.md)。本说明只定位现有复用入口；源码存在不代表下游已更新或已通过业务验收。

## 自定义管理台的角色预设

管理台可以更换目录、角色和授权表单的布局，仍使用上述 DTO/正式请求入口。成员选择读取 `TenantMemberSummary`；角色预设只产生待审阅的权限键集合，不能从菜单可见性、角色名称或前端开关生成实际授权。宿主现有 API 的组合示例：

```ts
import {
  getRolePermissions,
  replaceRolePermissions,
} from '@/api/system/role';

// roleId 与 permissionKeys 来自当前租户已授权管理页的选择和确认。
async function applyPreset(roleId: number, permissionKeys: string[]) {
  const { data: snapshot } = await getRolePermissions(roleId);
  return replaceRolePermissions({
    role_id: snapshot.role_id,
    expected_revision: snapshot.revision,
    permission_keys: permissionKeys,
  });
}
```

这是宿主源码 API 入口，不是 Identity 独立前端包导出。界面先通过 `getRolePermissionCatalog` 分页读取当前可分配目录，并让管理员确认预设；不要在替换外壳时自动执行示例写入。若编辑表单已持有读取快照，直接提交该快照的 revision，不在保存前重新读取以掩盖并发修改。HTTP revision 是字符串，PHP `RoleAdminService` 接收经入口校验的整数，不自行宽松转换用户输入。

拒绝例：固定所有者的权限替换返回 `TENANT_OWNER_PERMISSIONS_FIXED`；预设含已退休、平台或不可用模块的权限返回 `PERMISSION_NOT_ASSIGNABLE`。保留错误及草稿，不能过滤掉被拒绝的键后悄悄保存。过期修订按冲突处理，重新读取并由管理员确认；不能无条件重放写入。目录、角色、权限包和 Scope 的服务端校验与审计保持原入口，外观定制不新增授权来源。
