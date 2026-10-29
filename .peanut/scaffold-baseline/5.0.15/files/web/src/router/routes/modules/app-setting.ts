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
        requiredPermissions: ['readiness/checklist'],
        roles: ['admin'],
      },
    },
    {
      path: 'website',
      name: 'AppSettingWebsite',
      component: () => import('@/views/system/config/index.vue'),
      meta: {
        locale: 'menu.appSetting.website',
        requiresAuth: true,
        requiredPermissions: ['config/website'],
        roles: ['admin'],
      },
    },
    {
      path: 'user',
      name: 'AppSettingUser',
      component: () => import('@/views/app-setting/user/index.vue'),
      meta: {
        locale: 'menu.appSetting.user',
        requiresAuth: true,
        roles: ['admin'],
      },
    },
    {
      path: 'hot-search',
      name: 'AppSettingHotSearch',
      component: () => import('@/views/app-setting/hot-search/index.vue'),
      meta: {
        locale: 'menu.appSetting.hotSearch',
        requiresAuth: true,
        roles: ['admin'],
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
        roles: ['admin'],
      },
    },
  ],
};

export default APP_SETTING;
