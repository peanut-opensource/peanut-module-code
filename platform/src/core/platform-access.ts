import {
  platformDeveloperOfficialActions,
  platformDeveloperPage,
  platformDeveloperUploadActions,
  platformOperatorCreate,
  platformOperatorRolesAssign,
  platformOperatorRolesReplace,
  platformOperatorsPage,
  platformOperatorUpdate,
  platformOpsLogs,
  platformOpsBackupCreate,
  platformOpsMaintenanceSchedule,
  platformOpsOverviewPage,
  platformOpsRestoreVerify,
  platformOpsUpgradeExecute,
  platformPermissionBundleCreate,
  platformPermissionApiBindingUpdate,
  platformPermissionCatalogRead,
  platformPlanActions,
  platformPlansPage,
  platformRoleArchive,
  platformRoleCreate,
  platformRolePermissionAssign,
  platformRolesPage,
  platformRoleUpdate,
  platformTenantCreate,
  platformTenantEntryBindingActions,
  platformTenantLicenseUpdate,
  platformTenantOwnerInvitationActions,
  platformTenantsPage,
} from './platform-shell-definitions';

export interface PlatformApiAccess {
  method: string;
  path: string;
}

export function allowsPlatformApi(
  access: readonly PlatformApiAccess[],
  method: string,
  path: string
): boolean {
  return access.some(
    (item) => item.method === method.toUpperCase() && item.path === path
  );
}

// Existing action names remain presentation labels; only the server's actual API
// grant projection decides access, including instance-defined permission bundles.
const actionEndpoints: Record<string, readonly [string, string]> = {
  'platform.tenant.read': platformTenantsPage.endpoints[0],
  [platformTenantCreate.key]: platformTenantCreate.endpoints[0],
  'platform.tenant.update': platformTenantEntryBindingActions.enable.endpoints[0],
  'platform.tenant.provision-owner': platformTenantOwnerInvitationActions.create.endpoints[0],
  'platform.tenant.license.manage': platformTenantLicenseUpdate.endpoints[0],
  'platform.operator.read': platformOperatorsPage.endpoints[0],
  [platformOperatorCreate.key]: platformOperatorCreate.endpoints[0],
  [platformOperatorUpdate.key]: platformOperatorUpdate.endpoints[0],
  [platformOperatorRolesAssign.key]: platformOperatorRolesReplace,
  'platform.role.read': platformRolesPage.endpoints[0],
  [platformRoleCreate.key]: platformRoleCreate.endpoints[0],
  [platformRoleUpdate.key]: platformRoleUpdate.endpoints[0],
  [platformRoleArchive.key]: platformRoleArchive.endpoints[0],
  [platformRolePermissionAssign.key]: platformRolePermissionAssign.endpoints[0],
  'platform.permission.read': platformPermissionCatalogRead,
  'platform.permission.bundle.manage': platformPermissionBundleCreate.endpoints[0],
  'platform.permission.api.manage': platformPermissionApiBindingUpdate.endpoints[0],
  'platform.plan.read': platformPlansPage.endpoints[0],
  'platform.plan.manage': platformPlanActions.update.endpoints[0],
  'platform.module.read': platformDeveloperPage.endpoints[0],
  'platform.ops.read': platformOpsOverviewPage.endpoints[0],
  'platform.ops.backup.manage': platformOpsBackupCreate.endpoints[0],
  'platform.ops.restore.manage': platformOpsRestoreVerify.endpoints[0],
  'platform.ops.maintenance.manage': platformOpsMaintenanceSchedule.endpoints[0],
  [platformOpsLogs.key]: platformOpsLogs.endpoints[0],
  'platform.ops.upgrade.manage': platformOpsUpgradeExecute.endpoints[0],
  'platform.ops.module.manage': ['POST', '/platformapi/v1/ops/tasks/module'],
};

export function allowsPlatformAction(
  access: readonly PlatformApiAccess[],
  action: string
): boolean {
  const endpoint = actionEndpoints[action];
  return !!endpoint && allowsPlatformApi(access, endpoint[0], endpoint[1]);
}

export function platformDeveloperModuleAccess(
  access: readonly PlatformApiAccess[]
) {
  return {
    canInstall: allowsPlatformApi(access, ...platformDeveloperUploadActions.install.endpoints[0]),
    canUpdate: allowsPlatformApi(access, ...platformDeveloperUploadActions.update.endpoints[0]),
    canInstallOfficial: allowsPlatformApi(access, ...platformDeveloperOfficialActions.install.endpoints[0]),
    canUpdateOfficial: allowsPlatformApi(access, ...platformDeveloperOfficialActions.update.endpoints[0]),
  };
}
