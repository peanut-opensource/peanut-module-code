import type { PluginFrontendContribution } from '@peanut-admin/vue';
import { DEFAULT_LAYOUT } from '@/router/routes/base';

const contribution: PluginFrontendContribution = {
  moduleKey: 'peanut.settings',
  routes: [
    {
      path: '/system',
      name: 'officialSettingsRoot',
      component: DEFAULT_LAYOUT,
      meta: {
        requiresAuth: true,
        tenantModuleKey: 'peanut.settings',
        requiredPermissions: 'peanut.settings.read',
      },
      children: [
        {
          path: 'settings',
          name: 'OfficialSettings',
          component: () => import('./RuntimePage.vue'),
          meta: {
            locale: 'officialSettings.title',
            requiresAuth: true,
            tenantModuleKey: 'peanut.settings',
            requiredPermissions: 'peanut.settings.read',
          },
        },
      ],
    },
  ],
};

export default contribution;
