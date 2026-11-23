import type { RouteLocationNormalized } from 'vue-router';
import { defineStore } from 'pinia';
import {
  getTabKey,
  tabFromRoute,
  type ShellTab as TagProps,
  type ShellTabState as TabBarState,
} from '@peanut-admin/ui-vue/tabs';
import {
  DEFAULT_ROUTE,
  REDIRECT_ROUTE_NAME,
} from '@/router/constants';
import { isString } from '@/utils/is';

const BAN_LIST = [REDIRECT_ROUTE_NAME];

const useAppStore = defineStore('tabBar', {
  state: (): TabBarState => ({
    cacheTabList: new Set([DEFAULT_ROUTE.fullPath]),
    tagList: [DEFAULT_ROUTE],
  }),

  getters: {
    getTabList(): TagProps[] {
      return this.tagList;
    },
    getCacheList(): string[] {
      return Array.from(this.cacheTabList);
    },
  },

  actions: {
    updateTabList(route: RouteLocationNormalized) {
      if (BAN_LIST.includes(route.name as string)) return;
      const tab = tabFromRoute(route);
      const key = getTabKey(tab);
      const index = this.tagList.findIndex((tag) => getTabKey(tag) === key);
      if (index < 0) this.tagList.push(tab);
      else this.tagList[index] = tab;
      if (!route.meta.ignoreCache) {
        this.cacheTabList.add(key);
      }
    },
    deleteTag(idx: number, tag: TagProps) {
      this.tagList.splice(idx, 1);
      if (!tag.ignoreCache) this.cacheTabList.delete(getTabKey(tag));
    },
    addCache(key: string) {
      if (isString(key) && key !== '') this.cacheTabList.add(key);
    },
    deleteCache(tag: TagProps) {
      this.cacheTabList.delete(getTabKey(tag));
    },
    freshTabList(tags: TagProps[]) {
      this.tagList = tags;
      this.cacheTabList.clear();
      // 要先判断ignoreCache
      this.tagList
        .filter((el) => !el.ignoreCache)
        .map(getTabKey)
        .forEach((x) => this.cacheTabList.add(x));
    },
    resetTabList() {
      this.tagList = [DEFAULT_ROUTE];
      this.cacheTabList.clear();
      this.cacheTabList.add(DEFAULT_ROUTE.fullPath);
    },
  },
});

export default useAppStore;
