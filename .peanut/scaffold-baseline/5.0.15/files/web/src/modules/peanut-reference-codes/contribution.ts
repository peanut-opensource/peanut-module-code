import type { PluginFrontendContribution } from '@peanut-admin/vue';
import { DEFAULT_LAYOUT } from '@/router/routes/base';

const contribution: PluginFrontendContribution = {
  moduleKey: 'peanut.reference-codes',
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
