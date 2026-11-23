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
  'platform.tenant.read': ['GET', '/platformapi/tenants'],
  'platform.tenant.create': ['POST', '/platformapi/tenants/provision'],
  'platform.tenant.update': [
    'POST',
    '/platformapi/tenant-entry-bindings/enable',
  ],
  'platform.tenant.provision-owner': [
    'POST',
    '/platformapi/tenants/invitations',
  ],
  'platform.tenant.license.manage': ['PUT', '/platformapi/tenants/license'],
  'platform.operator.read': ['GET', '/platformapi/operators'],
  'platform.operator.create': ['POST', '/platformapi/operators/create'],
  'platform.operator.update': ['POST', '/platformapi/operators/update'],
  'platform.operator.role.assign': [
    'POST',
    '/platformapi/operators/roles/replace',
  ],
  'platform.role.read': ['GET', '/platformapi/roles'],
  'platform.role.create': ['POST', '/platformapi/roles/create'],
  'platform.role.update': ['POST', '/platformapi/roles/update'],
  'platform.role.archive': ['POST', '/platformapi/roles/archive'],
  'platform.role.permission.assign': [
    'POST',
    '/platformapi/roles/permissions/replace',
  ],
  'platform.permission.read': ['GET', '/platformapi/permissions'],
  'platform.permission.bundle.manage': [
    'POST',
    '/platformapi/permission/bundles',
  ],
  'platform.permission.api.manage': [
    'PUT',
    '/platformapi/permission/apis/bindings',
  ],
  'platform.plan.read': ['GET', '/platformapi/plans'],
  'platform.plan.manage': ['PUT', '/platformapi/plans'],
  'platform.module.read': ['GET', '/platformapi/developer-center/catalog'],
  'platform.ops.read': ['GET', '/platformapi/v1/ops/status'],
  'platform.ops.backup.manage': ['POST', '/platformapi/v1/ops/tasks/backup'],
  'platform.ops.restore.manage': ['POST', '/platformapi/v1/ops/tasks/restore'],
  'platform.ops.maintenance.manage': ['PUT', '/platformapi/v1/ops/maintenance'],
  'platform.ops.logs.read': ['GET', '/platformapi/v1/ops/logs'],
  'platform.ops.upgrade.manage': ['POST', '/platformapi/v1/ops/tasks/upgrade'],
  'platform.ops.module.manage': ['POST', '/platformapi/v1/ops/tasks/module'],
};

export function allowsPlatformAction(
  access: readonly PlatformApiAccess[],
  action: string
): boolean {
  const endpoint = actionEndpoints[action];
  return !!endpoint && allowsPlatformApi(access, endpoint[0], endpoint[1]);
}
