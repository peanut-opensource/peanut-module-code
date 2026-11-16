import type { ModuleFrontendContribution } from '@/module-contributions';
import { DEFAULT_LAYOUT } from '@/router/routes/base';
import localeEn from './locale/en-US';
import localeCn from './locale/zh-CN';

const contribution: ModuleFrontendContribution = {
  moduleKey: 'peanut.reference-codes',
  locales: {
    'en-US': localeEn,
    'zh-CN': localeCn,
  },
  routes: [
    {
      path: '/system',
      name: 'officialReferenceCodesRoot',
      component: DEFAULT_LAYOUT,
      meta: {
        requiresAuth: true,
        tenantModuleKey: 'peanut.reference-codes',
        requiredPermissions: 'peanut.reference-codes.read',
      },
      children: [
        {
          path: 'dictionary-catalog',
          name: 'OfficialDictionaryCatalog',
          component: () => import('./DictionaryCatalogPage.vue'),
          meta: {
            locale: 'officialReferenceCodes.catalog.title',
            requiresAuth: true,
            tenantModuleKey: 'peanut.reference-codes',
            requiredPermissions: 'peanut.reference-codes.read',
          },
        },
        {
          path: 'reference-codes',
          name: 'OfficialReferenceCodes',
          component: () => import('./RuntimePage.vue'),
          meta: {
            locale: 'officialReferenceCodes.title',
            requiresAuth: true,
            tenantModuleKey: 'peanut.reference-codes',
            requiredPermissions: 'peanut.reference-codes.read',
          },
        },
      ],
    },
  ],
};

export default contribution;
