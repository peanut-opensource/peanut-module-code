import type { ModuleFrontendContribution } from '@/module-contributions';
import { DEFAULT_LAYOUT } from '@/router/routes/base';
import {
  fileUploadAccept,
  getFileCateList,
  getFileList,
  uploadFile,
} from './api';
import localeEn from './views/locale/en-US';
import localeCn from './views/locale/zh-CN';

const contribution: ModuleFrontendContribution = {
  moduleKey: 'peanut.file',
  locales: {
    'en-US': localeEn,
    'zh-CN': localeCn,
  },
  fileProvider: {
    fileUploadAccept,
    getFileCateList,
    getFileList,
    uploadFile,
  },
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
