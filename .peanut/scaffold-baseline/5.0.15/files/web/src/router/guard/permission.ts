import type { Router } from 'vue-router';
import NProgress from 'nprogress'; // progress bar

import usePermission from '@/hooks/permission';
import { useUserStore, useAppStore } from '@/store';
import { appRoutes } from '../routes';
import { WHITE_LIST, NOT_FOUND, DEFAULT_ROUTE_NAME } from '../constants';

/**
 * Loads server menus and gates tenant-module routes before navigation.
 * Protected API operations still require server-side authorization.
 */
export default function setupPermissionGuard(router: Router) {
  router.beforeEach(async (to, from, next) => {
    const appStore = useAppStore();
    const userStore = useUserStore();
    const Permission = usePermission();
    if (
      appStore.menuFromServer &&
      !appStore.serverMenuLoaded &&
      !WHITE_LIST.find((el) => el.name === to.name)
    ) {
      await appStore.fetchServerMenuConfig();
    }
    if (to.meta.tenantModuleKey) {
      const required = to.meta.requiredPermissions;
      if (
        !appStore.enabledTenantModules.includes(to.meta.tenantModuleKey) ||
        (typeof required !== 'string' && !Array.isArray(required)) ||
        !Permission.hasPermission(required)
      ) {
        next(NOT_FOUND);
        NProgress.done();
        return;
      }
    }
    if (appStore.menuFromServer) {
      if (Permission.accessRouter(to)) {
        next();
      } else {
        const firstAllowedRoute = Permission.findFirstPermissionRoute(
          appStore.appAsyncMenus,
          userStore.role
        );
        const shouldUseDefault =
          from.name === 'login' || to.name === DEFAULT_ROUTE_NAME;
        next(
          shouldUseDefault && firstAllowedRoute ? firstAllowedRoute : NOT_FOUND
        );
      }
    } else if (Permission.accessRouter(to)) {
      next();
    } else {
      const destination =
        Permission.findFirstPermissionRoute(appRoutes, userStore.role) ||
        NOT_FOUND;
      next(destination);
    }
    NProgress.done();
  });
}
