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
        roles: ['admin'],
      },
    },
    {
      path: 'tabbar',
      name: 'DecorationTabbar',
      component: () => import('@/views/decoration/tabbar/index.vue'),
      meta: {
        locale: 'menu.decoration.tabbar',
        requiresAuth: true,
        roles: ['admin'],
      },
    },
    {
      path: 'pc',
      name: 'DecorationPc',
      component: () => import('@/views/decoration/pc/index.vue'),
      meta: {
        locale: 'menu.decoration.pc',
        requiresAuth: true,
        roles: ['admin'],
      },
    },
  ],
};

export default DECORATION;
