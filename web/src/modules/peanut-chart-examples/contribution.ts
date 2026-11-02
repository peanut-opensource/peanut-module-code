import type { PluginFrontendContribution } from '@peanut-admin/vue';
import { DEFAULT_LAYOUT } from '@/router/routes/base';

const contribution: PluginFrontendContribution = {
  moduleKey: 'peanut.chart-examples',
  routes: [
    {
      path: '/examples',
      name: 'ChartExamplesRoot',
      component: DEFAULT_LAYOUT,
      meta: { requiresAuth: true, tenantModuleKey: 'peanut.chart-examples' },
      children: [
        {
          path: 'charts',
          name: 'ChartExamples',
          component: () => import('./views/index.vue'),
          meta: {
            locale: '图表示例',
            requiresAuth: true,
            tenantModuleKey: 'peanut.chart-examples',
            requiredPermissions: 'peanut.chart-examples.view',
          },
        },
      ],
    },
  ],
};

export default contribution;
