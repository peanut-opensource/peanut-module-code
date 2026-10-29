import type { PluginFrontendContribution } from '@peanut-admin/vue';
import { DEFAULT_LAYOUT } from '@/router/routes/base';

const contribution: PluginFrontendContribution = {
  moduleKey: 'peanut.integration',
  routes: [
    {
      path: '/system',
      name: 'officialIntegrationRoot',
      component: DEFAULT_LAYOUT,
      meta: {
        requiresAuth: true,
        tenantModuleKey: 'peanut.integration',
        requiredPermissions: 'peanut.integration.access',
      },
      children: [
        {
          path: 'integration-security',
          name: 'peanut.integration.index',
          component: () => import('./RuntimePage.vue'),
          meta: {
            locale: 'menu.integrationSecurity',
            requiresAuth: true,
            tenantModuleKey: 'peanut.integration',
            requiredPermissions: 'peanut.integration.access',
          },
        },
      ],
    },
  ],
};

export default contribution;
