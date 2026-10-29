import { DEFAULT_LAYOUT } from '../base';
import { AppRouteRecordRaw } from '../types';

const SYSTEM: AppRouteRecordRaw = {
  path: '/system',
  name: 'system',
  component: DEFAULT_LAYOUT,
  meta: {
    locale: 'menu.system',
    requiresAuth: true,
    icon: 'icon-settings',
    order: 1,
  },
  children: [
    {
      path: 'menu',
      name: 'SystemMenu',
      component: () => import('@/views/system/menu/index.vue'),
      meta: {
        locale: 'menu.system.menu',
        requiresAuth: true,
        roles: ['admin'],
      },
    },
    {
      path: 'role',
      name: 'SystemRole',
      component: () => import('@/views/system/role/index.vue'),
      meta: {
        locale: 'menu.system.role',
        requiresAuth: true,
        roles: ['admin'],
      },
    },
    {
      path: 'admin',
      name: 'SystemAdmin',
      component: () => import('@/views/system/admin/index.vue'),
      meta: {
        locale: 'menu.system.admin',
        requiresAuth: true,
        roles: ['admin'],
      },
    },
    {
      path: '/app/departments',
      name: 'tenant.departments.list',
      component: () => import('@/views/system/dept/index.vue'),
      meta: {
        locale: 'menu.system.dept',
        requiresAuth: true,
        requiredPermissions: 'core.department.read',
      },
    },
    {
      path: 'jobs',
      name: 'SystemJobs',
      component: () => import('@/views/system/jobs/index.vue'),
      meta: {
        locale: 'menu.system.jobs',
        requiresAuth: true,
        roles: ['admin'],
      },
    },
    {
      path: 'dict',
      name: 'SystemDict',
      component: () => import('@/views/system/dict/index.vue'),
      meta: {
        locale: 'menu.system.dict',
        requiresAuth: true,
        roles: ['admin'],
      },
    },
    {
      path: 'log',
      name: 'SystemLog',
      component: () => import('@/views/system/log/index.vue'),
      meta: {
        locale: 'menu.system.log',
        requiresAuth: true,
        roles: ['admin'],
      },
    },
    {
      path: 'maintenance',
      name: 'SystemMaintenance',
      component: () => import('@/views/system/maintenance/index.vue'),
      meta: {
        locale: 'menu.system.maintenance',
        requiresAuth: true,
        roles: ['admin'],
        instanceTool: true,
      },
    },
  ],
};

export default SYSTEM;
