import { DEFAULT_LAYOUT } from '../base';
import { AppRouteRecordRaw } from '../types';

const DECORATION: AppRouteRecordRaw = {
  path: '/decoration',
  name: 'decoration',
  component: DEFAULT_LAYOUT,
  meta: {
    locale: 'menu.decoration',
    requiresAuth: true,
    icon: 'icon-brush',
    order: 5,
  },
  children: [
    {
      path: 'mobile',
      name: 'DecorationMobile',
      component: () => import('@/views/decoration/mobile/index.vue'),
      meta: {
        locale: 'menu.decoration.mobile',
        requiresAuth: true,
        requiredPermissions: ['peanut.settings.read'],
        tenantModuleKey: 'peanut.settings',
      },
    },
    {
      path: 'tabbar',
      name: 'DecorationTabbar',
      component: () => import('@/views/decoration/tabbar/index.vue'),
      meta: {
        locale: 'menu.decoration.tabbar',
        requiresAuth: true,
        requiredPermissions: ['peanut.settings.read'],
        tenantModuleKey: 'peanut.settings',
      },
    },
    {
      path: 'pc',
      name: 'DecorationPc',
      component: () => import('@/views/decoration/pc/index.vue'),
      meta: {
        locale: 'menu.decoration.pc',
        requiresAuth: true,
        requiredPermissions: ['peanut.settings.read'],
        tenantModuleKey: 'peanut.settings',
      },
    },
  ],
};

export default DECORATION;
