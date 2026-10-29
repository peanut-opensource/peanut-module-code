import type { ModuleFrontendContribution } from '@/module-contributions';
import { DEFAULT_LAYOUT } from '@/router/routes/base';

import localeAppChannelEn from './views/channel/locale/en-US';
import localeAppChannelCn from './views/channel/locale/zh-CN';

const contribution: ModuleFrontendContribution = {
  moduleKey: 'peanut.oauth',
  locales: {
    'en-US': { ...localeAppChannelEn },
    'zh-CN': { ...localeAppChannelCn },
  },
  routes: [
    {
      path: '/app-setting',
      name: 'officialOAuthRoot',
      component: DEFAULT_LAYOUT,
      meta: { requiresAuth: true, tenantModuleKey: 'peanut.oauth' },
      children: [
        {
          path: 'channel',
          name: 'AppSettingChannel',
          component: () =>
            import('@/modules/peanut-oauth/views/channel/index.vue'),
          meta: {
            locale: 'menu.appSetting.channel',
            requiresAuth: true,
            tenantModuleKey: 'peanut.oauth',
            requiredPermissions: 'peanut.oauth.web-page.config',
          },
        },
      ],
    },
  ],
};
export default contribution;
