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
export function hasPermission(
  requiredPermissions: string | string[],
  pagePath: string | undefined,
  grantedPermissions?: string[]
): boolean {
  if (!permissionRequirement(requiredPermissions)) return false;
  const permissions = grantedPermissions ?? useUserStore().permissions;
  if (!stringList(permissions)) return false;
  if (grantedPermissions === undefined) {
    if (!nonemptyString(pagePath)) return false;
    const bindings = useUserStore().action_bindings.filter(
      (item) => item.page_path === pagePath
    );
    const required = Array.isArray(requiredPermissions)
      ? requiredPermissions
      : [requiredPermissions];
    return required.some((key) => {
      const matches = bindings.filter((item) => item.action_key === key);
      const binding = matches[0];
      if (
        matches.some((item) => item.permission_key !== binding.permission_key)
      )
        return false;
      return evaluateRequiredPermissions(
        binding ? binding.permission_key : key,
        permissions,
        permissionEvaluator
      );
    });
  }
  return evaluateRequiredPermissions(
    requiredPermissions,
    permissions,
    permissionEvaluator
  );
}
function matchingRoutes(
  routes: RouteRecordRaw[],
  routeName: string,
  tenantModuleKey?: string
): RouteRecordRaw[] {
  const pending = [...routes];
  const matches: RouteRecordRaw[] = [];
  while (pending.length) {
    const route = pending.shift();
    if (
      route &&
      String(route.name || '') === routeName &&
      (tenantModuleKey === undefined ||
        route.meta?.tenantModuleKey === tenantModuleKey)
    )
      matches.push(route);
    if (route?.children?.length) pending.push(...route.children);
  }
  return matches;
}
export default function usePermission() {
  const userStore = useUserStore();
  const appStore = useAppStore();
  const accessRouter = (
    route: RouteLocationNormalized | RouteRecordRaw
  ): boolean => {
    if (!route.meta?.requiresAuth) return true;
    const required = route.meta.requiredPermissions;
    const tenant = route.meta.tenantModuleKey;
    if (
      tenant !== undefined &&
      (!moduleKey(tenant) || !permissionRequirement(required))
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
      return matchingRoutes(
        appStore.appAsyncMenus,
        authorizedName,
        moduleKey(tenant) ? tenant : undefined
      ).some((menu) => {
        const gate = menu.meta?.requiredPermissions;
        return (
          gate === undefined ||
          hasPermission(gate, route.path, userStore.permissions)
        );
      });
    }
    if (
      required !== undefined &&
      !hasPermission(required, route.path, userStore.permissions)
    )
      return false;
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
        if (route) {
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
