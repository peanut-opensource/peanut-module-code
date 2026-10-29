import { defineAdminModule } from '@peanut-admin/vue';
import type { AdminModuleContribution } from '@peanut-admin/vue';
import {
  NOTIFICATION_SMS_MODULE_KEY,
  NOTIFICATION_SMS_READ_PERMISSION,
  NOTIFICATION_SMS_ROUTE_NAME,
  NOTIFICATION_SMS_ROUTE_PATH,
  NOTIFICATION_SMS_STORE_KEY,
} from './runtime';
import type { NotificationRuntime } from './runtime';

const createNotificationModuleContribution = (
  runtime: NotificationRuntime
): AdminModuleContribution =>
  defineAdminModule({
    key: NOTIFICATION_SMS_MODULE_KEY,
    routes: [
      {
        name: NOTIFICATION_SMS_ROUTE_NAME,
        path: NOTIFICATION_SMS_ROUTE_PATH,
        component: async () => ({
          default: (await import('./NotificationInboxPage.vue')).default,
        }),
        access: {
          moduleKey: NOTIFICATION_SMS_MODULE_KEY,
          permissionKeys: [NOTIFICATION_SMS_READ_PERMISSION],
        },
      },
    ],
    disposeOnTenantChange: true,
    stores: [{ key: NOTIFICATION_SMS_STORE_KEY, dispose: runtime.dispose }],
  });

export default createNotificationModuleContribution;
