import { DEFAULT_LAYOUT } from '../base';
import { AppRouteRecordRaw } from '../types';

const APP_SETTING: AppRouteRecordRaw = {
  path: '/app-setting',
  name: 'appSetting',
  component: DEFAULT_LAYOUT,
  meta: {
    locale: 'menu.appSetting',
    requiresAuth: true,
    icon: 'icon-apps',
    order: 6,
  },
  children: [
    {
      path: 'readiness',
      name: 'AppSettingReadiness',
      component: () => import('@/views/app-setting/readiness/index.vue'),
      meta: {
        locale: 'menu.appSetting.readiness',
        requiresAuth: true,
        requiredPermissions: ['peanut.settings.read'],
        tenantModuleKey: 'peanut.settings',
      },
    },
    {
      path: 'website',
      name: 'AppSettingWebsite',
      component: () => import('@/views/system/config/index.vue'),
      meta: {
        locale: 'menu.appSetting.website',
        requiresAuth: true,
        requiredPermissions: ['peanut.settings.read'],
        tenantModuleKey: 'peanut.settings',
      },
    },
    {
      path: 'user',
      name: 'AppSettingUser',
      component: () => import('@/views/app-setting/user/index.vue'),
      meta: {
        locale: 'menu.appSetting.user',
        requiresAuth: true,
        requiredPermissions: ['peanut.settings.read'],
        tenantModuleKey: 'peanut.settings',
      },
    },
    {
      path: 'hot-search',
      name: 'AppSettingHotSearch',
      component: () => import('@/views/app-setting/hot-search/index.vue'),
      meta: {
        locale: 'menu.appSetting.hotSearch',
        requiresAuth: true,
        requiredPermissions: ['peanut.settings.read'],
        tenantModuleKey: 'peanut.settings',
      },
    },
    {
      path: 'customer-service',
      name: 'LegacyAppSettingCustomerService',
      component: DEFAULT_LAYOUT,
      redirect: '/decoration/mobile',
      meta: {
        requiresAuth: true,
        hideInMenu: true,
      },
    },
    {
      path: 'decorate',
      name: 'LegacyAppSettingDecorate',
      component: DEFAULT_LAYOUT,
      redirect: '/decoration/mobile',
      meta: {
        requiresAuth: true,
        hideInMenu: true,
      },
    },
    {
      path: 'transaction',
      name: 'AppSettingTransaction',
      component: () => import('@/views/app-setting/transaction/index.vue'),
      meta: {
        locale: 'menu.appSetting.transaction',
        requiresAuth: true,
        requiredPermissions: ['peanut.settings.read'],
        tenantModuleKey: 'peanut.settings',
      },
    },
  ],
};

export default APP_SETTING;
