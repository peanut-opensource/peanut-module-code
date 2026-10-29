import { RouteLocationNormalized, RouteRecordRaw } from 'vue-router';
import { useAppStore, useUserStore } from '@/store';
import { REDIRECT_ROUTE_NAME } from '@/router/constants';
import { permissionEvaluator } from '@/core/runtime';
import { evaluateRequiredPermissions } from '@peanut-admin/vue';

const nonemptyString = (value: unknown): value is string =>
  typeof value === 'string' && value.trim() !== '';
const stringList = (value: unknown): value is string[] =>
  Array.isArray(value) && value.every(nonemptyString);
const permissionRequirement = (value: unknown): value is string | string[] =>
  nonemptyString(value) || (stringList(value) && value.length > 0);
const moduleKey = (value: unknown): value is string =>
  nonemptyString(value) && /^[a-z][a-z0-9-]*\.[a-z][a-z0-9-]*$/.test(value);
function validMeta(route: RouteLocationNormalized | RouteRecordRaw): boolean {
  const { meta } = route;
  if (!meta) return true;
  return (
    (meta.roles === undefined || stringList(meta.roles)) &&
    (meta.requiredPermissions === undefined ||
      permissionRequirement(meta.requiredPermissions)) &&
    (meta.tenantModuleKey === undefined || moduleKey(meta.tenantModuleKey)) &&
    (meta.activeMenu === undefined || nonemptyString(meta.activeMenu)) &&
    (meta.requiresAuth === undefined ||
      typeof meta.requiresAuth === 'boolean') &&
    (meta.order === undefined ||
      (typeof meta.order === 'number' && Number.isFinite(meta.order)))
  );
}
export function hasPermission(
  requiredPermissions: string | string[],
  grantedPermissions?: string[]
): boolean {
  if (!permissionRequirement(requiredPermissions)) return false;
  const permissions = grantedPermissions ?? useUserStore().permissions;
  if (!stringList(permissions)) return false;
  return evaluateRequiredPermissions(
    requiredPermissions,
    permissions,
    permissionEvaluator
  );
}
function hasRouteName(
  routes: RouteRecordRaw[],
  routeName: string,
  tenantModuleKey?: string
): boolean {
  const pending = [...routes];
  while (pending.length) {
    const route = pending.shift();
    if (
      route &&
      validMeta(route) &&
      String(route.name || '') === routeName &&
      (tenantModuleKey === undefined ||
        route.meta?.tenantModuleKey === tenantModuleKey)
    )
      return true;
    if (route?.children?.length) pending.push(...route.children);
  }
  return false;
}
export default function usePermission() {
  const userStore = useUserStore();
  const appStore = useAppStore();
  const accessRouter = (
    route: RouteLocationNormalized | RouteRecordRaw
  ): boolean => {
    if (!validMeta(route)) return false;
    if (!route.meta?.requiresAuth) return true;
    const required = route.meta.requiredPermissions;
    const tenant = route.meta.tenantModuleKey;
    if (
      tenant !== undefined &&
      (!moduleKey(tenant) || !permissionRequirement(required))
    )
      return false;
    if (
      required !== undefined &&
      (!permissionRequirement(required) || !hasPermission(required))
    )
      return false;
    if (appStore.menuFromServer) {
      const routeName = String(route.name || '');
      if (routeName === REDIRECT_ROUTE_NAME || routeName === 'redirectWrapper')
        return true;
      const active = route.meta.activeMenu;
      const authorizedName =
        route.meta.hideInMenu && active !== undefined ? active : routeName;
      if (!nonemptyString(authorizedName)) return false;
      return hasRouteName(
        appStore.appAsyncMenus,
        authorizedName,
        moduleKey(tenant) ? tenant : undefined
      );
    }
    const { roles } = route.meta;
    return (
      roles === undefined ||
      (stringList(roles) &&
        (roles.includes('*') || roles.includes(userStore.role)))
    );
  };
  return {
    accessRouter,
    findFirstPermissionRoute(routers: RouteRecordRaw[], role = 'admin') {
      const pending = [...routers];
      while (pending.length) {
        const route = pending.shift();
        if (route && validMeta(route)) {
          if (route.children?.length) pending.unshift(...route.children);
          else {
            const roles = route.meta?.roles;
            const roleAllowed =
              roles === undefined ||
              (stringList(roles) &&
                (roles.includes('*') || roles.includes(role)));
            if (roleAllowed && accessRouter(route) && route.name)
              return { name: route.name };
          }
        }
      }
      return null;
    },
    hasPermission,
  };
}
