import { computed } from 'vue';
import type { RouteRecordRaw } from 'vue-router';
import usePermission from '@/hooks/permission';
import { useAppStore } from '@/store';
import appClientMenus from '@/router/app-menus';
import { cloneDeep } from 'lodash';

function normalizePath(path: string): string {
  const normalized = `/${path}`.replace(/\/{2,}/g, '/');
  return normalized.length > 1 ? normalized.replace(/\/$/, '') : normalized;
}

function findMenuPathByName(
  routes: RouteRecordRaw[],
  name: string,
  ancestors: RouteRecordRaw[] = []
): RouteRecordRaw[] | undefined {
  let match: RouteRecordRaw[] | undefined;
  routes.some((route) => {
    const path = [...ancestors, route];
    if (String(route.name ?? '') === name) {
      match = path;
      return true;
    }
    match = route.children?.length
      ? findMenuPathByName(route.children, name, path)
      : undefined;
    return Boolean(match);
  });
  return match;
}

function findMenuPathByPath(
  routes: RouteRecordRaw[],
  targetPath: string,
  parentPath = '',
  ancestors: RouteRecordRaw[] = []
): RouteRecordRaw[] | undefined {
  let match: RouteRecordRaw[] | undefined;
  routes.some((route) => {
    const routePath = route.path.startsWith('/')
      ? normalizePath(route.path)
      : normalizePath(`${parentPath}/${route.path}`);
    const path = [...ancestors, route];
    match = route.children?.length
      ? findMenuPathByPath(route.children, targetPath, routePath, path)
      : undefined;
    if (match) return true;
    if (targetPath === routePath) {
      match = path;
      return true;
    }
    return false;
  });
  return match;
}

export function findMenuRoutePath(
  menuTree: RouteRecordRaw[],
  route: {
    name?: string | symbol | null;
    path: string;
    matched: Array<{ name?: string | symbol | null; path: string }>;
    meta: { activeMenu?: string };
  }
): RouteRecordRaw[] | undefined {
  const { activeMenu } = route.meta;
  if (activeMenu) {
    const nameMatch = findMenuPathByName(menuTree, activeMenu);
    if (nameMatch) return nameMatch;

    const pathMatch = findMenuPathByPath(menuTree, normalizePath(activeMenu));
    if (pathMatch) return pathMatch;
  }

  const names = [
    String(route.name ?? ''),
    ...route.matched.slice().reverse().map((record) => String(record.name ?? '')),
  ].filter(Boolean);
  const nameMatch = names
    .map((name) => findMenuPathByName(menuTree, name))
    .find((match): match is RouteRecordRaw[] => Boolean(match));
  if (nameMatch) return nameMatch;

  const paths = [
    route.path,
    ...route.matched.slice().reverse().map((record) => record.path),
  ].filter(Boolean);
  return paths
    .map((path) => findMenuPathByPath(menuTree, normalizePath(path)))
    .find((match): match is RouteRecordRaw[] => Boolean(match));
}

export default function useMenuTree() {
  const permission = usePermission();
  const appStore = useAppStore();
  const appRoute = computed(() => {
    if (appStore.menuFromServer) {
      return appStore.appAsyncMenus;
    }
    return appClientMenus;
  });
  const menuTree = computed(() => {
    const copyRouter: RouteRecordRaw[] = cloneDeep(appRoute.value).filter(
      (route) =>
        route.meta?.order === undefined ||
        (typeof route.meta.order === 'number' &&
          Number.isFinite(route.meta.order))
    );
    if (!appStore.menuFromServer) {
      copyRouter.sort((a, b) => {
        const left = a.meta?.order;
        const right = b.meta?.order;
        return (left ?? 0) - (right ?? 0);
      });
    }
    function travel(
      _routes: RouteRecordRaw[],
      layer: number
    ): RouteRecordRaw[] {
      const collector: Array<RouteRecordRaw | null> = _routes.map((element) => {
        // no access
        if (!permission.accessRouter(element)) {
          return null;
        }

        // leaf node
        if (element.meta?.hideChildrenInMenu || !element.children) {
          element.children = [];
          return element;
        }

        // route filter hideInMenu true
        element.children = element.children.filter(
          (x) => x.meta?.hideInMenu !== true
        );

        // Associated child node
        const subItem = travel(element.children, layer + 1);

        if (subItem.length) {
          element.children = subItem;
          return element;
        }
        // the else logic
        if (layer > 1) {
          element.children = subItem;
          return element;
        }

        if (element.meta?.hideInMenu === false) {
          return element;
        }

        return null;
      });
      return collector.filter(
        (route): route is RouteRecordRaw => route !== null
      );
    }
    return travel(copyRouter, 0);
  });

  return {
    menuTree,
  };
}
