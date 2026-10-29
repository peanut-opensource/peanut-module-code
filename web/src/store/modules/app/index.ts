import { defineStore } from 'pinia';
import { ElNotification, type NotificationHandle } from 'element-plus';
import type { RouteRecordRaw } from 'vue-router';
import { enabledTenantModulesFromRoutes } from '@peanut-admin/vue';
import defaultSettings from '@/config/settings.json';
import { getMenuList } from '@/api/user';
import type { AppSettings, AppState, ServerMenuRecord } from './types';
import mapServerMenu from './server-menu';

const useAppStore = defineStore('app', {
  state: (): AppState => ({ ...defaultSettings }),

  getters: {
    appCurrentSetting(state: AppState): AppState {
      return { ...state };
    },
    appDevice(state: AppState) {
      return state.device;
    },
    appAsyncMenus(state: AppState): RouteRecordRaw[] {
      return state.serverMenu;
    },
  },

  actions: {
    // Update presentation settings; runtime menus use setServerMenu/clearServerMenu.
    updateSettings(partial: Partial<AppSettings>) {
      this.$patch(partial);
    },

    // Change theme color
    toggleTheme(dark: boolean) {
      if (dark) {
        this.theme = 'dark';
        document.documentElement.classList.add('dark');
      } else {
        this.theme = 'light';
        document.documentElement.classList.remove('dark');
      }
    },
    toggleDevice(device: string) {
      this.device = device;
    },
    toggleMenu(value: boolean) {
      this.hideMenu = value;
    },
    setServerMenu(menu: ServerMenuRecord[]) {
      this.serverMenu = mapServerMenu(menu);
      this.enabledTenantModules = enabledTenantModulesFromRoutes(
        this.serverMenu
      );
      this.serverMenuLoaded = true;
    },
    async fetchServerMenuConfig() {
      let notifyInstance: NotificationHandle | null = null;
      try {
        notifyInstance = ElNotification.info({
          message: 'loading',
          showClose: true,
        });
        const { data } = await getMenuList();
        this.setServerMenu(data);
        notifyInstance.close();
        notifyInstance = ElNotification.success({
          message: 'success',
          showClose: true,
        });
      } catch (error) {
        this.serverMenu = [];
        this.serverMenuLoaded = true;
        // eslint-disable-next-line @typescript-eslint/no-unused-vars
        notifyInstance?.close();
        notifyInstance = ElNotification.error({
          message: 'error',
          showClose: true,
        });
      }
    },
    clearServerMenu() {
      this.serverMenu = [];
      this.enabledTenantModules = [];
      this.serverMenuLoaded = false;
    },
  },
});

export default useAppStore;
