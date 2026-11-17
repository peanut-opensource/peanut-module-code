import type { ModuleFrontendContribution } from '@/module-contributions';
import { DEFAULT_LAYOUT } from '@/router/routes/base';
import localeEn from './runtime/locale/en-US';
import localeCn from './runtime/locale/zh-CN';

const contribution: ModuleFrontendContribution = {
  moduleKey: 'peanut.integration',
  locales: {
    'en-US': localeEn,
    'zh-CN': localeCn,
  },
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
