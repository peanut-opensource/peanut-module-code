import type {
  GovernanceAudience,
  GovernanceCatalog,
  GovernanceCatalogInput,
  GovernancePermissionDefinition,
} from './types';

const permissionPattern = /^[a-z][a-z0-9]*(?:[.-][a-z][a-z0-9-]*)+$/;
const modulePattern = /^[a-z][a-z0-9]*(?:[.-][a-z][a-z0-9-]*)*$/;

const fail = (code: string): never => {
  throw new Error(code);
};

const canonicalRoutePath = (
  audience: GovernanceAudience,
  path: string
): string => {
  if (audience !== 'tenant' && audience !== 'platform')
    fail('GOVERNANCE_ROUTE_INVALID');
  const prefix = audience === 'tenant' ? '/app' : '/platform';
  if (
    path === '' ||
    !path.startsWith('/') ||
    path.endsWith('/') ||
    path.includes('//') ||
    /[\\%?#\u0000-\u0020\u007f]/.test(path) ||
    path.split('/').some((segment) => segment === '.' || segment === '..') ||
    (path !== prefix && !path.startsWith(`${prefix}/`))
  )
    fail('GOVERNANCE_ROUTE_INVALID');
  return path;
};

export const createGovernanceCatalog = (
  input: GovernanceCatalogInput
): GovernanceCatalog => {
  const permissions = new Map<string, GovernancePermissionDefinition>();
  for (const permission of input.permissions) {
    if (
      !['tenant', 'platform'].includes(permission.audience) ||
      !permissionPattern.test(permission.key) ||
      !modulePattern.test(permission.moduleKey) ||
      (permission.audience === 'platform') !==
        permission.key.startsWith('platform.') ||
      permissions.has(permission.key)
    )
      fail('GOVERNANCE_PERMISSION_INVALID');
    permissions.set(permission.key, { ...permission });
  }

  const routes = new Map<string, GovernanceCatalogInput['routes'][number]>();
  const paths = new Set<string>();
  for (const route of input.routes) {
    const path = canonicalRoutePath(route.audience, route.path);
    if (
      route.name === '' ||
      route.componentKey === '' ||
      route.permissionKeys.length === 0 ||
      route.clientKeys.length === 0 ||
      new Set(route.permissionKeys).size !== route.permissionKeys.length ||
      new Set(route.clientKeys).size !== route.clientKeys.length ||
      routes.has(route.name) ||
      paths.has(path)
    )
      fail('GOVERNANCE_ROUTE_INVALID');
    const expectedModule =
      route.moduleKey ?? (route.audience === 'platform' ? 'platform' : 'core');
    for (const permissionKey of route.permissionKeys) {
      const permission =
        permissions.get(permissionKey) ??
        fail('GOVERNANCE_PERMISSION_UNDECLARED');
      if (permission.audience !== route.audience)
        fail('GOVERNANCE_PERMISSION_AUDIENCE_MISMATCH');
      if (!permission.active) fail('GOVERNANCE_PERMISSION_INACTIVE');
      if (permission.moduleKey !== expectedModule)
        fail('GOVERNANCE_PERMISSION_MODULE_MISMATCH');
    }
    routes.set(route.name, {
      ...route,
      path,
      permissionKeys: [...route.permissionKeys],
      clientKeys: [...route.clientKeys],
    });
    paths.add(path);
  }

  const icons = new Map<string, GovernanceCatalogInput['icons'][string]>();
  for (const [key, icon] of Object.entries(input.icons)) {
    if (
      !/^[A-Z][A-Za-z0-9]{0,63}$/.test(key) ||
      icon.label.trim() === '' ||
      icon.glyph.trim() === '' ||
      icons.has(key)
    )
      fail('GOVERNANCE_ICON_INVALID');
    icons.set(key, { ...icon });
  }

  return { permissions, routes, icons };
};

export const requireGovernancePermission = (
  catalog: GovernanceCatalog,
  key: string,
  audience: GovernanceAudience
): GovernancePermissionDefinition => {
  const permission =
    catalog.permissions.get(key) ?? fail('GOVERNANCE_PERMISSION_UNDECLARED');
  if (permission.audience !== audience)
    fail('GOVERNANCE_PERMISSION_AUDIENCE_MISMATCH');
  if (!permission.active) fail('GOVERNANCE_PERMISSION_INACTIVE');
  return permission;
};
