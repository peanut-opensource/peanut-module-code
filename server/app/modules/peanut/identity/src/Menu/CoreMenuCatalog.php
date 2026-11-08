<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Menu;

final class CoreMenuCatalog
{
    /** @return list<MenuDefinition> */
    public static function definitions(): array
    {
        return [
            new \PeanutAdmin\Kernel\Menu\MenuDefinition('core.workbench.index', 'core', 'tenant', null, 'page', '工作台', 'Workplace', '/dashboard/workplace', 'Workplace', 'workbench/index', ['admin-web'], -20, 'icon-dashboard'),
            new \PeanutAdmin\Kernel\Menu\MenuDefinition('core.organization', 'core', 'tenant', null, 'group', '组织与权限', null, null, null, null, ['admin-web'], 10, 'icon-user-group'),
            new \PeanutAdmin\Kernel\Menu\MenuDefinition('core.member.list', 'core', 'tenant', 'core.organization', 'page', '成员管理', 'tenant.members.list', '/app/members', 'core.member.list', 'core.member.read', ['admin-web'], 11, 'UserRound'),
            new \PeanutAdmin\Kernel\Menu\MenuDefinition('core.department.list', 'core', 'tenant', 'core.organization', 'page', '部门管理', 'tenant.departments.list', '/app/departments', 'core.department.list', 'core.department.read', ['admin-web'], 12, 'Network'),
            new \PeanutAdmin\Kernel\Menu\MenuDefinition('core.role.list', 'core', 'tenant', 'core.organization', 'page', '角色管理', 'tenant.roles.list', '/app/roles', 'core.role.list', 'core.role.read', ['admin-web'], 13, 'Shield'),
            new \PeanutAdmin\Kernel\Menu\MenuDefinition('core.job.list', 'core', 'tenant', 'core.organization', 'page', '岗位管理', 'SystemJobs', '/system/jobs', 'SystemJobs', 'core.job.read', ['admin-web'], 15, 'icon-user-group'),
            new \PeanutAdmin\Kernel\Menu\MenuDefinition('core.governance.workbench', 'core', 'tenant', 'core.organization', 'page', '权限治理', 'tenant.governance.workbench', '/app/governance', 'core.governance.workbench', 'core.role.read', ['admin-web'], 14, 'SlidersHorizontal'),
            new \PeanutAdmin\Kernel\Menu\MenuDefinition('core.business', 'core', 'tenant', null, 'group', '会员与财务', null, null, null, null, ['admin-web'], 20, 'icon-user'),
            new \PeanutAdmin\Kernel\Menu\MenuDefinition('core.content', 'core', 'tenant', null, 'group', '内容与资源', null, null, null, null, ['admin-web'], 30, 'icon-folder'),
            new \PeanutAdmin\Kernel\Menu\MenuDefinition('core.applications', 'core', 'tenant', null, 'group', '应用与集成', null, null, null, null, ['admin-web'], 40, 'icon-apps'),
            new \PeanutAdmin\Kernel\Menu\MenuDefinition('core.system', 'core', 'tenant', null, 'group', '系统设置', null, null, null, null, ['admin-web'], 50, 'icon-settings'),
            new \PeanutAdmin\Kernel\Menu\MenuDefinition('core.operations', 'core', 'tenant', null, 'group', '运维与审计', null, null, null, null, ['admin-web'], 60, 'icon-history'),
            new \PeanutAdmin\Kernel\Menu\MenuDefinition('core.module.list', 'core', 'tenant', 'core.applications', 'page', '模块管理', 'tenant.modules.list', '/app/modules', 'core.module.list', 'core.module.read', ['admin-web'], 21, 'Blocks'),
            new \PeanutAdmin\Kernel\Menu\MenuDefinition('core.audit.list', 'core', 'tenant', 'core.operations', 'page', '审计日志', 'SystemLog', '/system/log', 'SystemLog', 'core.audit.read', ['admin-web'], 22, 'icon-history'),
            new \PeanutAdmin\Kernel\Menu\MenuDefinition('platform.governance', 'platform', 'platform', null, 'group', '组织与权限', null, null, null, null, ['platform-web'], 10, 'Landmark'),
            new \PeanutAdmin\Kernel\Menu\MenuDefinition('platform.tenancy', 'platform', 'platform', null, 'group', '租户运营', null, null, null, null, ['platform-web'], 5, 'Building2'),
            new \PeanutAdmin\Kernel\Menu\MenuDefinition('platform.operations', 'platform', 'platform', null, 'group', '运维与审计', null, null, null, null, ['platform-web'], 20, 'Activity'),
            new \PeanutAdmin\Kernel\Menu\MenuDefinition('platform.tenant.list', 'platform', 'platform', 'platform.tenancy', 'page', '租户管理', 'platform.tenants.list', '/platform/#/tenants', 'platform.tenant.list', 'platform.tenant.read', ['platform-web'], 11, 'Building2'),
            new \PeanutAdmin\Kernel\Menu\MenuDefinition('platform.operator.list', 'platform', 'platform', 'platform.governance', 'page', '平台操作员', 'platform.operators.list', '/platform/#/operators', 'platform.operator.list', 'platform.operator.read', ['platform-web'], 12, 'UserCog'),
            new \PeanutAdmin\Kernel\Menu\MenuDefinition('platform.role.list', 'platform', 'platform', 'platform.governance', 'page', '平台角色', 'platform.roles.list', '/platform/#/roles', 'platform.role.list', 'platform.role.read', ['platform-web'], 13, 'ShieldCheck'),
            new \PeanutAdmin\Kernel\Menu\MenuDefinition('platform.governance.workbench', 'platform', 'platform', 'platform.governance', 'page', '角色授权', 'platform.governance.workbench', '/platform/#/role-permissions', 'platform.governance.workbench', 'platform.role.read', ['platform-web'], 14, 'SlidersHorizontal'),
            new \PeanutAdmin\Kernel\Menu\MenuDefinition('platform.permission.bundle.list', 'platform', 'platform', 'platform.governance', 'page', '权限包', 'platform.permission.bundles', '/platform/#/permission-bundles', 'platform.permission.bundle.list', 'platform.permission.read', ['platform-web'], 15, 'KeyRound'),
            new \PeanutAdmin\Kernel\Menu\MenuDefinition('platform.permission.api.list', 'platform', 'platform', 'platform.governance', 'page', 'API目录与关联', 'platform.permission.apis', '/platform/#/permission-apis', 'platform.permission.api.list', 'platform.permission.api.read', ['platform-web'], 16, 'Network'),
            new \PeanutAdmin\Kernel\Menu\MenuDefinition('platform.permission.menu.list', 'platform', 'platform', 'platform.governance', 'page', '菜单与按钮权限', 'platform.permission.menus', '/platform/#/permission-menus', 'platform.permission.menu.list', 'platform.permission.menu.read', ['platform-web'], 17, 'ListTree'),
            new \PeanutAdmin\Kernel\Menu\MenuDefinition('platform.storage', 'platform', 'platform', null, 'group', '存储管理', null, null, null, null, ['platform-web'], 15, 'Database'),
            new \PeanutAdmin\Kernel\Menu\MenuDefinition('platform.storage.account.list', 'platform', 'platform', 'platform.storage', 'page', '存储账号', 'platform.storage.accounts', '/platform/#/storage-accounts', 'platform.storage.account.list', 'platform.storage.account.read', ['platform-web'], 1, 'KeyRound'),
            new \PeanutAdmin\Kernel\Menu\MenuDefinition('platform.storage.space.list', 'platform', 'platform', 'platform.storage', 'page', '存储空间', 'platform.storage.spaces', '/platform/#/storage-spaces', 'platform.storage.space.list', 'platform.storage.space.read', ['platform-web'], 2, 'Folder'),
            new \PeanutAdmin\Kernel\Menu\MenuDefinition('platform.storage.route.list', 'platform', 'platform', 'platform.storage', 'page', '上传路由', 'platform.storage.routes', '/platform/#/storage-routes', 'platform.storage.route.list', 'platform.storage.route.read', ['platform-web'], 3, 'Route'),
            new \PeanutAdmin\Kernel\Menu\MenuDefinition('platform.audit.list', 'platform', 'platform', 'platform.operations', 'page', '平台审计', 'platform.audit.list', '/platform/#/audit', 'platform.audit.list', 'platform.audit.read', ['platform-web'], 15, 'ScrollText'),
            new \PeanutAdmin\Kernel\Menu\MenuDefinition('platform.upgrade.status', 'platform', 'platform', 'platform.operations', 'page', '版本与升级', 'platform.upgrade.status', '/platform/#/upgrades', 'platform.upgrade.status', 'platform.ops.read', ['platform-web'], 16, 'ArrowUpCircle'),
            new \PeanutAdmin\Kernel\Menu\MenuDefinition('platform.ops.console', 'platform', 'platform', 'platform.operations', 'page', '运行状态', 'platform.ops.console', '/platform/#/ops', 'platform.ops.console', 'platform.ops.read', ['platform-web'], 17, 'Activity'),
            new \PeanutAdmin\Kernel\Menu\MenuDefinition('platform.ops.maintenance', 'platform', 'platform', 'platform.operations', 'page', '维护窗口', 'platform.ops.maintenance', '/platform/#/maintenance', 'platform.ops.maintenance', 'platform.ops.read', ['platform-web'], 18, 'Calendar'),
            new \PeanutAdmin\Kernel\Menu\MenuDefinition('platform.ops.backups', 'platform', 'platform', 'platform.operations', 'page', '备份与恢复', 'platform.ops.backups', '/platform/#/backups', 'platform.ops.backups', 'platform.ops.read', ['platform-web'], 19, 'Archive'),
            new \PeanutAdmin\Kernel\Menu\MenuDefinition('platform.ops.diagnostics', 'platform', 'platform', 'platform.operations', 'page', '日志与诊断', 'platform.ops.diagnostics', '/platform/#/diagnostics', 'platform.ops.diagnostics', 'platform.ops.logs.read', ['platform-web'], 20, 'FileSearch'),
            new \PeanutAdmin\Kernel\Menu\MenuDefinition('platform.ops.providers', 'platform', 'platform', 'platform.operations', 'page', '供应商资格', 'platform.ops.providers', '/platform/#/providers', 'platform.ops.providers', 'platform.ops.read', ['platform-web'], 21, 'BadgeCheck'),
        ];
    }

    private function __construct() {}
}
