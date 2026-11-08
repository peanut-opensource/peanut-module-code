import type { RouteRecordRaw } from 'vue-router';

const nonemptyString = (value: unknown): value is string =>
  typeof value === 'string' && value.trim() !== '';
const stringList = (value: unknown): value is string[] =>
  Array.isArray(value) && value.every(nonemptyString);

/** Validate source/plugin routes and server-menu projections before adoption. */
export function validRouteMeta(route: RouteRecordRaw): boolean {
  const { meta } = route;
  if (!meta) return true;
  const required = meta.requiredPermissions;
  return (
    (meta.roles === undefined || stringList(meta.roles)) &&
    (required === undefined ||
      nonemptyString(required) ||
      (stringList(required) && required.length > 0)) &&
    (meta.tenantModuleKey === undefined ||
      (nonemptyString(meta.tenantModuleKey) &&
        /^[a-z][a-z0-9-]*\.[a-z][a-z0-9-]*$/.test(meta.tenantModuleKey))) &&
    (meta.activeMenu === undefined || nonemptyString(meta.activeMenu)) &&
    (meta.requiresAuth === undefined ||
      typeof meta.requiresAuth === 'boolean') &&
    (meta.order === undefined ||
      (typeof meta.order === 'number' && Number.isFinite(meta.order)))
  );
}

export function validateRouteTree(routes: RouteRecordRaw[]): RouteRecordRaw[] {
  routes.forEach((route) => {
    if (!validRouteMeta(route))
      throw new Error(
        `ROUTE_META_INVALID: ${String(route.name ?? route.path)}`
      );
    if (route.children) validateRouteTree(route.children);
  });
  return routes;
}
