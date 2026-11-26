export const platformTenancyNavigation = {
  id: 'tenant-operations',
  key: 'platform.tenancy',
  parentKey: null,
  type: 'group',
  label: '租户运营',
  endpoints: [],
} as const;

export const platformTenantsPage = {
  key: 'platform.tenant.list',
  parentKey: platformTenancyNavigation.key,
  type: 'page',
  view: 'tenants',
  label: '租户与生命周期',
  endpoints: [['GET', '/platformapi/tenants']],
} as const;

export const platformTenantCreate = {
  key: 'platform.tenant.create',
  parentKey: platformTenantsPage.key,
  type: 'action',
  label: '新建租户',
  endpoints: [['POST', '/platformapi/tenants/provision']],
} as const;

export const platformTenantDetail = {
  key: 'platform.tenant.detail',
  parentKey: platformTenantsPage.key,
  type: 'action',
  label: '详情',
  endpoints: [['GET', '/platformapi/tenants/detail']],
} as const;

export const platformTenantTransitions = {
  activate: {
    key: 'platform.tenant.activate',
    parentKey: platformTenantsPage.key,
    type: 'action',
    label: '启用',
    endpoints: [['POST', '/platformapi/tenants/activate']],
  },
  suspend: {
    key: 'platform.tenant.suspend',
    parentKey: platformTenantsPage.key,
    type: 'action',
    label: '暂停',
    endpoints: [['POST', '/platformapi/tenants/suspend']],
  },
  close: {
    key: 'platform.tenant.close',
    parentKey: platformTenantsPage.key,
    type: 'action',
    label: '关闭',
    endpoints: [['POST', '/platformapi/tenants/close']],
  },
} as const;

export const platformTenantOwnerInvitationsPage = {
  key: 'platform.tenant.owner-invitation.list',
  parentKey: platformTenancyNavigation.key,
  type: 'page',
  view: 'owners',
  label: '租户所有者邀请管理',
  endpoints: [platformTenantsPage.endpoints[0], ['GET', '/platformapi/tenants/invitations']],
} as const;

export const platformTenantOwnerRead = {
  key: 'platform.tenant.owner.read',
  parentKey: platformTenantOwnerInvitationsPage.key,
  type: 'operation',
  label: '读取目标租户所有者',
  endpoints: [['GET', '/platformapi/tenants/owner']],
} as const;

export const platformTenantOwnerInvitationActions = {
  create: {
    key: 'platform.tenant.owner-invitation.create',
    parentKey: platformTenantOwnerInvitationsPage.key,
    type: 'action',
    label: '发送邀请',
    endpoints: [['POST', '/platformapi/tenants/invitations']],
  },
  resend: {
    key: 'platform.tenant.owner-invitation.resend',
    parentKey: platformTenantOwnerInvitationsPage.key,
    type: 'action',
    label: '重发',
    endpoints: [['POST', '/platformapi/tenants/invitations/resend']],
  },
  revoke: {
    key: 'platform.tenant.owner-invitation.revoke',
    parentKey: platformTenantOwnerInvitationsPage.key,
    type: 'action',
    label: '撤销',
    endpoints: [['POST', '/platformapi/tenants/invitations/revoke']],
  },
} as const;

export const platformTenantEntryBindingsPage = {
  key: 'platform.tenant.entry-binding.list',
  parentKey: platformTenancyNavigation.key,
  type: 'page',
  view: 'endpoints',
  label: '入口域名与客户端',
  endpoints: [platformTenantsPage.endpoints[0], ['GET', '/platformapi/tenant-entry-bindings']],
} as const;

export const platformTenantEntryBindingActions = {
  enable: {
    key: 'platform.tenant.entry-binding.enable',
    parentKey: platformTenantEntryBindingsPage.key,
    type: 'action',
    label: '启用绑定',
    endpoints: [['POST', '/platformapi/tenant-entry-bindings/enable']],
  },
  disable: {
    key: 'platform.tenant.entry-binding.disable',
    parentKey: platformTenantEntryBindingsPage.key,
    type: 'action',
    label: '停用',
    endpoints: [['POST', '/platformapi/tenant-entry-bindings/disable']],
  },
} as const;

export const platformTenantEntitlementsPage = {
  key: 'platform.tenant.entitlements',
  parentKey: platformTenancyNavigation.key,
  type: 'page',
  view: 'modules',
  label: '租户许可证与模块权益',
  endpoints: [platformTenantsPage.endpoints[0], ['GET', '/platformapi/tenants/license'], ['GET', '/platformapi/tenants/modules']],
} as const;

export const platformPlansPage = {
  key: 'platform.plan.list',
  parentKey: platformTenancyNavigation.key,
  type: 'page',
  view: 'plans',
  label: '套餐管理',
  endpoints: [['GET', '/platformapi/plans']],
} as const;

export const platformTenantLicenseUpdate = {
  key: 'platform.tenant.license.update',
  parentKey: platformTenantEntitlementsPage.key,
  type: 'action',
  label: '维护当前许可证',
  endpoints: [['PUT', '/platformapi/tenants/license'], platformPlansPage.endpoints[0]],
} as const;

export const organizationAccessNavigation = {
  id: 'organization-access',
  key: 'platform.governance',
  parentKey: null,
  type: 'group',
  label: '组织与权限',
  endpoints: [],
} as const;

export const platformOperatorsPage = {
  key: 'platform.operator.list',
  parentKey: organizationAccessNavigation.key,
  type: 'page',
  view: 'operators',
  label: '实例平台操作员',
  endpoints: [['GET', '/platformapi/operators']],
} as const;

export const platformOperatorCreate = {
  key: 'platform.operator.create',
  parentKey: platformOperatorsPage.key,
  type: 'action',
  label: '创建操作员',
  endpoints: [['POST', '/platformapi/operators/create']],
} as const;

export const platformRolesPage = {
  key: 'platform.role.list',
  parentKey: organizationAccessNavigation.key,
  type: 'page',
  view: 'roles',
  label: '平台角色',
  endpoints: [['GET', '/platformapi/roles']],
} as const;

export const platformRoleCreate = {
  key: 'platform.role.create',
  parentKey: platformRolesPage.key,
  type: 'action',
  label: '创建角色',
  endpoints: [['POST', '/platformapi/roles/create']],
} as const;

export const platformRoleUpdate = {
  key: 'platform.role.update',
  parentKey: platformRolesPage.key,
  type: 'action',
  label: '编辑',
  endpoints: [['POST', '/platformapi/roles/update']],
} as const;

export const platformRoleArchive = {
  key: 'platform.role.archive',
  parentKey: platformRolesPage.key,
  type: 'action',
  label: '归档',
  endpoints: [['POST', '/platformapi/roles/archive']],
} as const;

export const platformOperatorUpdate = {
  key: 'platform.operator.update',
  parentKey: platformOperatorsPage.key,
  type: 'action',
  label: '编辑',
  endpoints: [['POST', '/platformapi/operators/update']],
} as const;

export const platformOperatorRolesReplace = [
  'POST',
  '/platformapi/operators/roles/replace',
] as const;

export const platformOperatorRolesAssign = {
  key: 'platform.operator.role.assign',
  parentKey: platformOperatorsPage.key,
  type: 'action',
  label: '分配角色',
  endpoints: [platformRolesPage.endpoints[0], platformOperatorRolesReplace],
} as const;

export const platformOperatorTransitions = {
  activate: {
    key: 'platform.operator.activate',
    parentKey: platformOperatorsPage.key,
    type: 'action',
    label: '启用',
    endpoints: [['POST', '/platformapi/operators/activate']],
  },
  suspend: {
    key: 'platform.operator.suspend',
    parentKey: platformOperatorsPage.key,
    type: 'action',
    label: '暂停',
    endpoints: [['POST', '/platformapi/operators/suspend']],
  },
  close: {
    key: 'platform.operator.close',
    parentKey: platformOperatorsPage.key,
    type: 'action',
    label: '关闭',
    endpoints: [['POST', '/platformapi/operators/close']],
  },
} as const;

export const platformPermissionCatalogRead = [
  'GET',
  '/platformapi/permissions',
] as const;

export const platformRoleAuthorizationPage = {
  key: 'platform.governance.workbench',
  parentKey: organizationAccessNavigation.key,
  type: 'page',
  view: 'role-permissions',
  label: '角色授权',
  endpoints: [platformRolesPage.endpoints[0], platformPermissionCatalogRead],
} as const;

export const platformRolePermissionAssign = {
  key: 'platform.role.permission.assign',
  parentKey: platformRoleAuthorizationPage.key,
  type: 'action',
  label: '保存角色授权',
  endpoints: [['POST', '/platformapi/roles/permissions/replace'], platformPermissionCatalogRead],
} as const;

export const platformPermissionBundlesPage = {
  key: 'platform.permission.bundle.list',
  parentKey: organizationAccessNavigation.key,
  type: 'page',
  view: 'permission-bundles',
  label: '权限包管理',
  endpoints: [['GET', '/platformapi/permission/bundles']],
} as const;

export const platformPermissionBundleCreate = {
  key: 'platform.permission.bundle.create',
  parentKey: platformPermissionBundlesPage.key,
  type: 'action',
  label: '创建权限包',
  endpoints: [['POST', '/platformapi/permission/bundles']],
} as const;

export const platformPermissionBundleUpdate = {
  key: 'platform.permission.bundle.update',
  parentKey: platformPermissionBundlesPage.key,
  type: 'action',
  label: '编辑',
  endpoints: [['PUT', '/platformapi/permission/bundles']],
} as const;

export const platformPermissionApisPage = {
  key: 'platform.permission.api.list',
  parentKey: organizationAccessNavigation.key,
  type: 'page',
  view: 'permission-apis',
  label: 'API 目录与关联',
  endpoints: [['GET', '/platformapi/permission/apis']],
} as const;

export const platformPermissionApiBindingRead = {
  key: 'platform.permission.api.binding.read',
  parentKey: platformPermissionApisPage.key,
  type: 'operation',
  label: '查看权限包 API 关联',
  endpoints: [['GET', '/platformapi/permission/apis/bindings'], platformPermissionBundlesPage.endpoints[0]],
} as const;

export const platformPermissionApiBindingUpdate = {
  key: 'platform.permission.api.binding.update',
  parentKey: platformPermissionApisPage.key,
  type: 'action',
  label: '保存 API 关联',
  endpoints: [['PUT', '/platformapi/permission/apis/bindings'], ...platformPermissionApiBindingRead.endpoints],
} as const;

export const platformPermissionMenusPage = {
  key: 'platform.permission.menu.list',
  parentKey: organizationAccessNavigation.key,
  type: 'page',
  view: 'permission-menus',
  label: '菜单与按钮权限',
  endpoints: [['GET', '/platformapi/permission/menus']],
} as const;

export const platformPermissionMenuBindingUpdate = {
  key: 'platform.permission.menu.binding.update',
  parentKey: platformPermissionMenusPage.key,
  type: 'action',
  label: '编辑菜单与按钮引用',
  endpoints: [['PUT', '/platformapi/permission/menus/binding'], platformPermissionBundlesPage.endpoints[0]],
} as const;

export const platformRoleAuthorizationPreview = {
  key: 'platform.role.authorization.preview',
  parentKey: null,
  type: 'operation',
  label: '只读候选预览',
  source: '平台角色与角色授权 · 只读候选预览',
  endpoints: [['POST', '/platformapi/roles/permissions/preview'], platformOperatorsPage.endpoints[0], platformRolesPage.endpoints[0], platformPermissionApisPage.endpoints[0]],
} as const;

export const platformRoleAuthorizationCatalogAudit = {
  key: 'platform.role.authorization.catalog-audit',
  parentKey: null,
  type: 'operation',
  label: '全角色目录只读审计',
  source: '平台角色与角色授权 · 包含无成员角色的原生目录审计',
  endpoints: [['POST', '/platformapi/roles/permissions/catalog-audit'], platformOperatorsPage.endpoints[0], platformRolesPage.endpoints[0], platformPermissionApisPage.endpoints[0]],
} as const;

export const platformStorageCatalogOwner = 'peanut.file';

export const platformStorageNavigation = {
  id: 'storage',
  key: 'platform.storage',
  parentKey: null,
  type: 'group',
  label: '存储管理',
  endpoints: [],
} as const;

export const platformStoragePages = {
  accounts: {
    key: 'platform.storage.account.list',
    parentKey: platformStorageNavigation.key,
    type: 'page',
    view: 'storage-accounts',
    label: '存储账号',
    endpoints: [['GET', '/platformapi/infrastructure/storage/accounts']],
  },
  spaces: {
    key: 'platform.storage.space.list',
    parentKey: platformStorageNavigation.key,
    type: 'page',
    view: 'storage-spaces',
    label: '存储空间',
    endpoints: [['GET', '/platformapi/infrastructure/storage/spaces']],
  },
  routes: {
    key: 'platform.storage.route.list',
    parentKey: platformStorageNavigation.key,
    type: 'page',
    view: 'storage-routes',
    label: '上传路由',
    endpoints: [['GET', '/platformapi/infrastructure/storage/routes']],
  },
} as const;

export const platformStorageLegacyRead = [
  'GET',
  '/platformapi/infrastructure/storage',
] as const;

export const platformStorageReadAlternatives = {
  accounts: [platformStoragePages.accounts.endpoints[0], platformStorageLegacyRead],
  spaces: [platformStoragePages.spaces.endpoints[0], platformStorageLegacyRead],
  routes: [platformStoragePages.routes.endpoints[0], platformStorageLegacyRead],
} as const;

export const platformStorageLegacyOperation = {
  key: 'platform.storage.legacy.read',
  parentKey: platformStorageNavigation.key,
  type: 'operation',
  label: '读取旧聚合存储目录',
  source: '存储管理 · File 旧聚合兼容入口',
  endpoints: [platformStorageLegacyRead],
} as const;

export const platformStorageAccountCreate = {
  key: 'platform.storage.account.create',
  parentKey: platformStoragePages.accounts.key,
  type: 'action',
  label: '新增存储账号',
  endpoints: [['POST', '/platformapi/infrastructure/storage/account']],
} as const;

const platformStorageSpaceCreateEndpoint = ['POST', '/platformapi/infrastructure/storage/space'] as const;
const platformStorageRouteUpdateEndpoint = ['POST', '/platformapi/infrastructure/storage/route'] as const;

export const platformStorageSpaceCreateActions = {
  fine: {
    key: 'platform.storage.space.create.fine',
    parentKey: platformStoragePages.spaces.key,
    type: 'action',
    label: '新增存储空间（分项读取账号）',
    endpoints: [platformStorageSpaceCreateEndpoint, platformStorageReadAlternatives.accounts[0]],
  },
  legacy: {
    key: 'platform.storage.space.create.legacy',
    parentKey: platformStoragePages.spaces.key,
    type: 'action',
    label: '新增存储空间（旧聚合读取）',
    endpoints: [platformStorageSpaceCreateEndpoint, platformStorageReadAlternatives.accounts[1]],
  },
} as const;

export const platformStorageRouteUpdateActions = {
  fine: {
    key: 'platform.storage.route.update.fine',
    parentKey: platformStoragePages.routes.key,
    type: 'action',
    label: '调整上传路由（分项读取空间）',
    endpoints: [platformStorageRouteUpdateEndpoint, platformStorageReadAlternatives.spaces[0]],
  },
  legacy: {
    key: 'platform.storage.route.update.legacy',
    parentKey: platformStoragePages.routes.key,
    type: 'action',
    label: '调整上传路由（旧聚合读取）',
    endpoints: [platformStorageRouteUpdateEndpoint, platformStorageReadAlternatives.spaces[1]],
  },
} as const;

export const platformOperationsNavigation = {
  id: 'operations-audit',
  key: 'platform.operations',
  parentKey: null,
  type: 'group',
  label: '运维与审计',
  endpoints: [],
} as const;

export const platformOpsOverviewPage = {
  key: 'platform.ops.console',
  parentKey: platformOperationsNavigation.key,
  type: 'page',
  view: 'ops',
  label: '运维概览',
  endpoints: [['GET', '/platformapi/v1/ops/status']],
} as const;

export const platformOpsMaintenancePage = {
  key: 'platform.ops.maintenance',
  parentKey: platformOperationsNavigation.key,
  type: 'page',
  view: 'maintenance',
  label: '维护窗口',
  endpoints: [['GET', '/platformapi/v1/ops/maintenance']],
} as const;

export const platformOpsMaintenanceSchedule = {
  key: 'platform.ops.maintenance.schedule',
  parentKey: platformOpsMaintenancePage.key,
  type: 'action',
  label: '安排或替换维护窗口',
  endpoints: [['PUT', '/platformapi/v1/ops/maintenance'], platformOpsMaintenancePage.endpoints[0]],
} as const;

export const platformOpsMaintenanceClose = {
  key: 'platform.ops.maintenance.close',
  parentKey: platformOpsMaintenancePage.key,
  type: 'action',
  label: '关闭维护窗口',
  endpoints: [['POST', '/platformapi/v1/ops/maintenance/{maintenance_key}/close'], platformOpsMaintenancePage.endpoints[0]],
} as const;

export const platformOpsBackupsPage = {
  key: 'platform.ops.backups',
  parentKey: platformOperationsNavigation.key,
  type: 'page',
  view: 'backups',
  label: '备份与恢复',
  endpoints: [['GET', '/platformapi/v1/ops/backups']],
} as const;

export const platformOpsBackupCreate = {
  key: 'platform.ops.backup.create',
  parentKey: platformOpsBackupsPage.key,
  type: 'action',
  label: '创建备份',
  endpoints: [['POST', '/platformapi/v1/ops/tasks/backup']],
} as const;

export const platformOpsRestoreVerify = {
  key: 'platform.ops.restore.verify',
  parentKey: platformOpsBackupsPage.key,
  type: 'action',
  label: '恢复并验证',
  endpoints: [['POST', '/platformapi/v1/ops/tasks/restore']],
} as const;

export const platformOpsTaskRead = {
  key: 'platform.ops.task.read',
  parentKey: platformOpsBackupsPage.key,
  type: 'operation',
  label: '刷新运维任务状态',
  endpoints: [['GET', '/platformapi/v1/ops/tasks/{task_key}']],
} as const;

const opsUpgradesPageKey = 'platform.upgrade.status';

export const platformOpsUpgradeReadiness = {
  key: 'platform.upgrade.readiness.read',
  parentKey: opsUpgradesPageKey,
  type: 'operation',
  label: '读取升级就绪状态',
  endpoints: [['GET', '/platformapi/v1/ops/upgrade-readiness']],
} as const;

export const platformOpsUpgradeHistory = {
  key: 'platform.upgrade.history.read',
  parentKey: opsUpgradesPageKey,
  type: 'operation',
  label: '读取升级任务',
  endpoints: [['GET', '/platformapi/v1/ops/upgrades']],
} as const;

export const platformOpsUpgradesPage = {
  key: opsUpgradesPageKey,
  parentKey: platformOperationsNavigation.key,
  type: 'page',
  view: 'upgrades',
  label: '应用升级',
  endpoints: [],
  readAlternatives: [platformOpsUpgradeReadiness, platformOpsUpgradeHistory],
} as const;

export const platformOpsUpgradeExecute = {
  key: 'platform.ops.upgrade.execute',
  parentKey: platformOpsUpgradesPage.key,
  type: 'action',
  label: '执行固定升级',
  endpoints: [['POST', '/platformapi/v1/ops/tasks/upgrade'], platformOpsUpgradeReadiness.endpoints[0], platformOpsUpgradeHistory.endpoints[0]],
} as const;

const opsDiagnosticsPageKey = 'platform.ops.diagnostics';

export const platformOpsLogs = {
  key: 'platform.ops.logs.read',
  parentKey: opsDiagnosticsPageKey,
  type: 'operation',
  label: '读取运行日志',
  endpoints: [['GET', '/platformapi/v1/ops/logs']],
} as const;

export const platformOpsDiagnosticsDownload = {
  key: 'platform.ops.diagnostics.download',
  parentKey: opsDiagnosticsPageKey,
  type: 'operation',
  label: '下载脱敏诊断包',
  endpoints: [['GET', '/platformapi/v1/ops/diagnostics']],
} as const;

export const platformOpsDiagnosticsPage = {
  key: opsDiagnosticsPageKey,
  parentKey: platformOperationsNavigation.key,
  type: 'page',
  view: 'diagnostics',
  label: '运行诊断',
  endpoints: [],
  readAlternatives: [platformOpsLogs, platformOpsDiagnosticsDownload],
} as const;

export const platformOpsProvidersPage = {
  key: 'platform.ops.providers',
  parentKey: platformOperationsNavigation.key,
  type: 'page',
  view: 'providers',
  label: '服务提供者',
  endpoints: [['GET', '/platformapi/v1/ops/providers']],
} as const;

export const platformAuditPage = {
  key: 'platform.audit.list',
  parentKey: platformOperationsNavigation.key,
  type: 'page',
  view: 'audit',
  label: '平台审计',
  endpoints: [['GET', '/platformapi/audit']],
} as const;

export const platformDeveloperPage = {
  key: 'platform.developer.catalog',
  parentKey: platformOperationsNavigation.key,
  type: 'page',
  view: 'developer',
  label: '开发者中心',
  endpoints: [['GET', '/platformapi/developer-center/catalog']],
} as const;

export const platformDeveloperRuntimeRead = {
  key: 'platform.developer.runtime.read',
  parentKey: platformDeveloperPage.key,
  type: 'operation',
  label: '读取安装与依赖状态',
  endpoints: [['GET', '/platformapi/instance-tools/modules']],
} as const;

export const platformDeveloperOfficialUpdatesRead = {
  key: 'platform.developer.official-updates.read',
  parentKey: platformDeveloperPage.key,
  type: 'operation',
  label: '刷新官方目录',
  endpoints: [['GET', '/platformapi/instance-tools/modules/updates']],
} as const;

export const platformDeveloperUploadActions = {
  install: {
    key: 'platform.developer.module.upload-install',
    parentKey: platformDeveloperPage.key,
    type: 'action',
    label: '上传包安装',
    endpoints: [['POST', '/platformapi/instance-tools/modules/install']],
  },
  update: {
    key: 'platform.developer.module.upload-update',
    parentKey: platformDeveloperPage.key,
    type: 'action',
    label: '上传包升级',
    endpoints: [['POST', '/platformapi/instance-tools/modules/update']],
  },
} as const;

export const platformDeveloperOfficialActions = {
  install: {
    key: 'platform.developer.module.official-install',
    parentKey: platformDeveloperPage.key,
    type: 'action',
    label: '官方安装',
    endpoints: [['POST', '/platformapi/instance-tools/modules/official-install']],
  },
  update: {
    key: 'platform.developer.module.official-update',
    parentKey: platformDeveloperPage.key,
    type: 'action',
    label: '官方升级',
    endpoints: [['POST', '/platformapi/instance-tools/modules/official-update']],
  },
} as const;

export const platformPlanCatalogReads = [
  platformDeveloperRuntimeRead.endpoints[0],
  platformPermissionBundlesPage.endpoints[0],
] as const;

export const platformPlanActions = {
  create: {
    key: 'platform.plan.create',
    parentKey: platformPlansPage.key,
    type: 'action',
    label: '新建套餐',
    endpoints: [['POST', '/platformapi/plans'], ...platformPlanCatalogReads],
  },
  update: {
    key: 'platform.plan.update',
    parentKey: platformPlansPage.key,
    type: 'action',
    label: '编辑',
    endpoints: [['PUT', '/platformapi/plans'], ...platformPlanCatalogReads],
  },
} as const;

export const platformModuleTaskRead = {
  key: 'platform.module.operation-task.read',
  parentKey: null,
  type: 'operation',
  label: '读取模块操作任务',
  source: '开发者中心 · 模块任务回执',
  endpoints: [['GET', '/platformapi/instance-tools/modules/tasks/{task_key}']],
} as const;

export function platformViewHash(view: string): string {
  return '#/' + view;
}

export const platformShellPreviewDefinitions = [
  platformTenancyNavigation,
  platformTenantsPage,
  platformTenantCreate,
  platformTenantDetail,
  ...Object.values(platformTenantTransitions),
  platformTenantOwnerInvitationsPage,
  platformTenantOwnerRead,
  ...Object.values(platformTenantOwnerInvitationActions),
  platformTenantEntryBindingsPage,
  ...Object.values(platformTenantEntryBindingActions),
  platformTenantEntitlementsPage,
  platformTenantLicenseUpdate,
  platformPlansPage,
  ...Object.values(platformPlanActions),
  organizationAccessNavigation,
  platformOperatorsPage,
  platformOperatorCreate,
  platformOperatorUpdate,
  platformOperatorRolesAssign,
  ...Object.values(platformOperatorTransitions),
  platformRolesPage,
  platformRoleCreate,
  platformRoleUpdate,
  platformRoleArchive,
  platformRoleAuthorizationPage,
  platformRolePermissionAssign,
  platformPermissionBundlesPage,
  platformPermissionBundleCreate,
  platformPermissionBundleUpdate,
  platformPermissionApisPage,
  platformPermissionApiBindingRead,
  platformPermissionApiBindingUpdate,
  platformPermissionMenusPage,
  platformPermissionMenuBindingUpdate,
  platformRoleAuthorizationPreview,
  platformRoleAuthorizationCatalogAudit,
  platformStorageNavigation,
  ...Object.values(platformStoragePages),
  platformStorageAccountCreate,
  ...Object.values(platformStorageSpaceCreateActions),
  ...Object.values(platformStorageRouteUpdateActions),
  platformStorageLegacyOperation,
  platformOperationsNavigation,
  platformOpsOverviewPage,
  platformOpsMaintenancePage,
  platformOpsMaintenanceSchedule,
  platformOpsMaintenanceClose,
  platformOpsBackupsPage,
  platformOpsBackupCreate,
  platformOpsRestoreVerify,
  platformOpsTaskRead,
  platformOpsUpgradesPage,
  ...platformOpsUpgradesPage.readAlternatives,
  platformOpsUpgradeExecute,
  platformOpsDiagnosticsPage,
  ...platformOpsDiagnosticsPage.readAlternatives,
  platformOpsProvidersPage,
  platformDeveloperPage,
  platformDeveloperRuntimeRead,
  platformDeveloperOfficialUpdatesRead,
  ...Object.values(platformDeveloperUploadActions),
  ...Object.values(platformDeveloperOfficialActions),
  platformAuditPage,
  platformModuleTaskRead,
] as const;
