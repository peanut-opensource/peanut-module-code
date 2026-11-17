import type { ModuleFrontendContribution } from '@peanut-admin/vue';
import { DEFAULT_LAYOUT } from '@/router/routes/base';

const contribution: ModuleFrontendContribution = {
  moduleKey: 'custom.ai-access',
  routes: [
    {
      path: '/ai-access',
      name: 'customAiAccessRoot',
      component: DEFAULT_LAYOUT,
      meta: { requiresAuth: true, tenantModuleKey: 'custom.ai-access' },
      children: [
        {
          path: 'catalog',
          name: 'AiAccessCatalog',
          component: () => import('./views/index.vue'),
          meta: {
            title: 'AI 接入配置',
            requiresAuth: true,
            tenantModuleKey: 'custom.ai-access',
            requiredPermissions: 'custom.ai-access.view',
          },
        },
      ],
    },
  ],
};

export default contribution;
