import type { ModuleFrontendContribution } from '@/module-contributions';
import { DEFAULT_LAYOUT } from '@/router/routes/base';

import localeConfigurationTransferEn from './views/locale/en-US';
import localeConfigurationTransferCn from './views/locale/zh-CN';

const contribution: ModuleFrontendContribution = {
  moduleKey: 'peanut.import-export',
  locales: {
    'en-US': { ...localeConfigurationTransferEn },
    'zh-CN': { ...localeConfigurationTransferCn },
  },
  routes: [
    {
      path: '/system/configuration-transfer',
      name: 'ConfigurationTransfer',
      component: DEFAULT_LAYOUT,
      meta: {
        locale: 'menu.system.configurationTransfer',
        requiresAuth: true,
        tenantModuleKey: 'peanut.import-export',
        requiredPermissions: 'peanut.import-export.configuration.export',
      },
      children: [
        {
          path: '',
          name: 'SystemConfigurationTransfer',
          component: () => import('./views/index.vue'),
          meta: {
            locale: 'menu.system.configurationTransfer',
            requiresAuth: true,
            tenantModuleKey: 'peanut.import-export',
            requiredPermissions: 'peanut.import-export.configuration.export',
          },
        },
      ],
    },
    {
      path: '/system/import-export-operations',
      name: 'OfficialImportExportOperationsRoot',
      component: DEFAULT_LAYOUT,
      meta: {
        locale: 'menu.system.importExportOperations',
        requiresAuth: true,
        tenantModuleKey: 'peanut.import-export',
        requiredPermissions: 'peanut.import-export.operations.read',
      },
      children: [
        {
          path: '',
          name: 'OfficialImportExportOperations',
          component: () => import('./OperationsPage.vue'),
          meta: {
            locale: 'menu.system.importExportOperations',
            requiresAuth: true,
            tenantModuleKey: 'peanut.import-export',
            requiredPermissions: 'peanut.import-export.operations.read',
          },
        },
      ],
    },
  ],
};
export default contribution;
