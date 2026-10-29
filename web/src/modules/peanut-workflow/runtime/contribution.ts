import type { PluginFrontendContribution } from '@peanut-admin/vue';
import { DEFAULT_LAYOUT } from '@/router/routes/base';

/** This entry has no dependency on the designer, Vue Flow or its styles. */
const runtimeContribution: PluginFrontendContribution = {
  moduleKey: 'peanut.workflow',
  routes: [
    {
      path: '/workflow',
      name: 'WorkflowRoot',
      component: DEFAULT_LAYOUT,
      meta: {
        requiresAuth: true,
        tenantModuleKey: 'peanut.workflow',
        requiredPermissions: 'peanut.workflow.instance.read',
      },
      children: [
        {
          path: 'runtime',
          name: 'WorkflowRuntime',
          component: () => import('./WorkflowRuntimePage.vue'),
          meta: {
            requiresAuth: true,
            tenantModuleKey: 'peanut.workflow',
            requiredPermissions: 'peanut.workflow.instance.read',
            locale: '流程运行中心',
          },
        },
      ],
    },
  ],
};
export default runtimeContribution;
