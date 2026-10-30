import type { RouteRecordRaw } from 'vue-router';
import appClientMenus from '@/router/app-menus';
import { validRouteMeta } from '@/router/route-meta';
import type { ServerMenuRecord } from './types';

function normalizePath(path: string): string {
  const normalized = `/${path}`.replace(/\/{2,}/g, '/');
  return normalized.length > 1 ? normalized.replace(/\/$/, '') : normalized;
}

function resolvePath(parentPath: string, path: string): string {
  if (path.startsWith('/')) return normalizePath(path);
  return normalizePath(`${parentPath}/${path}`);
}

const staticRoutesByPath = new Map<string, RouteRecordRaw>();

function indexStaticRoutes(routes: RouteRecordRaw[], parentPath = ''): void {
  routes.forEach((route) => {
    const fullPath = resolvePath(parentPath, route.path);
    staticRoutesByPath.set(fullPath, route);
    if (route.children?.length) {
      indexStaticRoutes(route.children, fullPath);
    }
  });
}

indexStaticRoutes(appClientMenus as RouteRecordRaw[]);

/**
 * 服务端菜单只作为授权与可见性来源；组件始终来自已注册的静态路由。
 * 无静态路由映射的服务端节点会被忽略，避免运行时加载任意 component 字符串。
 */
export default function mapServerMenu(
  menus: ServerMenuRecord[]
): RouteRecordRaw[] {
  return menus
    .map((menu): RouteRecordRaw | null => {
      const children = mapServerMenu(menu.children || []);
      if (menu.type === 'M') {
        if (children.length === 0) return null;
        // Presentation-only containers are never registered with the router and
        // never load a component. Every leaf still resolves a static route below.
        return {
          path: normalizePath(menu.paths || `/menu-group/${menu.menu_key}`),
          name: `menu-group-${menu.menu_key}`,
          meta: {
            title: menu.name,
            icon: menu.icon,
            requiresAuth: true,
            hideInMenu: Number(menu.is_show ?? 1) === 0,
          },
          children,
        };
      }
      const staticRoute = staticRoutesByPath.get(
        normalizePath(menu.paths || '')
      );
      if (!staticRoute) return null;

      if (staticRoute.children?.length && children.length === 0) return null;

      const route = {
        ...staticRoute,
        path: normalizePath(menu.paths || staticRoute.path),
        meta: {
          ...staticRoute.meta,
          requiredPermissions:
            menu.required_permission ||
            menu.perms ||
            staticRoute.meta?.requiredPermissions,
          icon: menu.icon || staticRoute.meta?.icon,
          title: menu.name || staticRoute.meta?.title,
          hideInMenu:
            Number(menu.is_show ?? 1) === 0 ||
            (!menu.module_key && staticRoute.meta?.hideInMenu === true),
        },
        children,
      } as RouteRecordRaw;
      return validRouteMeta(route) ? route : null;
    })
    .filter((route): route is RouteRecordRaw => route !== null);
}
