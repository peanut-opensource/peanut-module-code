import { defineAdminModule } from '@peanut-admin/vue';
import type { AdminModuleContribution } from '@peanut-admin/vue';
import {
  IMPORT_EXPORT_MODULE_KEY,
  IMPORT_EXPORT_ROUTE_NAME,
  IMPORT_EXPORT_ROUTE_PATH,
  IMPORT_EXPORT_READ_PERMISSION,
  IMPORT_EXPORT_STORE_KEY,
} from './runtime';
import type { ImportExportRuntime } from './runtime';

const createImportExportModuleContribution = (
  runtime: ImportExportRuntime
): AdminModuleContribution =>
  defineAdminModule({
    key: IMPORT_EXPORT_MODULE_KEY,
    routes: [
      {
        name: IMPORT_EXPORT_ROUTE_NAME,
        path: IMPORT_EXPORT_ROUTE_PATH,
        component: async () => ({
          default: (await import('./ImportExportPage.vue')).default,
        }),
        access: {
          moduleKey: IMPORT_EXPORT_MODULE_KEY,
          permissionKeys: [IMPORT_EXPORT_READ_PERMISSION],
        },
      },
    ],
    disposeOnTenantChange: true,
    stores: [{ key: IMPORT_EXPORT_STORE_KEY, dispose: runtime.dispose }],
  });

export default createImportExportModuleContribution;
