import { DEFAULT_LAYOUT } from '../base';
import { AppRouteRecordRaw } from '../types';

const DEV_TOOLS: AppRouteRecordRaw = {
  path: '/dev-tools',
  name: 'devTools',
  component: DEFAULT_LAYOUT,
  meta: {
    locale: 'menu.devTools',
    requiresAuth: true,
    icon: 'icon-code',
    order: 0,
    instanceTool: true,
  },
  children: [
    {
      path: 'code',
      name: 'DevToolsCode',
      component: () => import('@/views/dev-tools/code/index.vue'),
      meta: {
        locale: 'menu.devTools.code',
        requiresAuth: true,
        roles: ['admin'],
      },
    },
    {
      path: 'modules',
      name: 'DevToolsModules',
      component: () => import('@/views/dev-tools/modules/index.vue'),
      meta: {
        locale: 'menu.devTools.modules',
        requiresAuth: true,
        roles: ['admin'],
        instanceTool: true,
      },
    },
  ],
};

export default DEV_TOOLS;
