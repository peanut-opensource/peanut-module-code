import type { PluginFrontendContribution } from '@peanut-admin/vue';
import { DEFAULT_LAYOUT } from '@/router/routes/base';

const contribution: PluginFrontendContribution = {
  moduleKey: 'peanut.task',
  routes: [
    {
      path: '/system',
      name: 'officialTaskRoot',
      component: DEFAULT_LAYOUT,
      meta: { requiresAuth: true, tenantModuleKey: 'peanut.task' },
      children: [
        {
          path: 'crontab',
          name: 'SystemCrontab',
          component: () => import('@/modules/peanut-task/views/index.vue'),
          meta: {
            locale: 'menu.system.crontab',
            requiresAuth: true,
            tenantModuleKey: 'peanut.task',
            requiredPermissions: 'peanut.task.list',
          },
        },
        {
          path: 'task-jobs',
          name: 'OfficialTaskJobs',
          component: () => import('./TaskJobsPage.vue'),
          meta: {
            locale: 'menu.system.taskJobs',
            requiresAuth: true,
            tenantModuleKey: 'peanut.task',
            requiredPermissions: 'peanut.task.jobs.read',
          },
        },
      ],
    },
  ],
};
export default contribution;
