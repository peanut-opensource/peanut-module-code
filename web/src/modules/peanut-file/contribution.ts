import type { PluginFrontendContribution } from '@peanut-admin/vue';
import { DEFAULT_LAYOUT } from '@/router/routes/base';

const contribution: PluginFrontendContribution = {
  moduleKey: 'peanut.file',
  routes: [
    {
      path: '/system/file',
      name: 'officialFileRoot',
      component: DEFAULT_LAYOUT,
      meta: {
        requiresAuth: true,
        tenantModuleKey: 'peanut.file',
        requiredPermissions: 'peanut.file.list',
      },
      children: [
        {
          path: '',
          name: 'SystemFile',
          component: () => import('@/modules/peanut-file/views/index.vue'),
          meta: {
            locale: 'menu.system.file',
            requiresAuth: true,
            tenantModuleKey: 'peanut.file',
            requiredPermissions: 'peanut.file.list',
          },
        },
        {
          path: 'assets',
          name: 'peanut.file.assets',
          component: () =>
            import(
              '@/modules/peanut-file/optional-runtime/src/FileMediaPage.vue'
            ),
          meta: {
            locale: 'menu.system.file',
            requiresAuth: true,
            hideInMenu: true,
            activeMenu: 'SystemFile',
            tenantModuleKey: 'peanut.file',
            requiredPermissions: 'peanut.file.list',
          },
        },
      ],
    },
  ],
};
export default contribution;
