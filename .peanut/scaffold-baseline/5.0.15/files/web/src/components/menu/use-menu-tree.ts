import { computed } from 'vue';
import type { RouteRecordRaw } from 'vue-router';
import usePermission from '@/hooks/permission';
import { useAppStore } from '@/store';
import appClientMenus from '@/router/app-menus';
import { cloneDeep } from 'lodash';

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
