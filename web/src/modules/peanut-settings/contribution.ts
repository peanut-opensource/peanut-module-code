import type { ModuleFrontendContribution } from '@/module-contributions';
import { DEFAULT_LAYOUT } from '@/router/routes/base';
import localeEn from './locale/en-US';
import localeCn from './locale/zh-CN';

const contribution: ModuleFrontendContribution = {
  moduleKey: 'peanut.settings',
  locales: {
    'en-US': localeEn,
    'zh-CN': localeCn,
  },
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
