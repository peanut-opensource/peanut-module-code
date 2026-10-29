<script setup lang="ts">
  import { onBeforeUnmount, provide } from 'vue';
  import { hasPermission } from '@/hooks/permission';
  import { tenantSessionFetch } from '@/api/tenant-session-fetch';
  import InboxPage from './optional-runtime/src/NotificationInboxPage.vue';
  import {
    createNotificationFetchTransport,
    createNotificationRuntime,
    NOTIFICATION_SMS_MANAGE_PERMISSION,
    NOTIFICATION_SMS_READ_PERMISSION,
    notificationRuntimeKey,
  } from './optional-runtime/src';

  const baseUrl = new URL(
    import.meta.env.VITE_API_BASE_URL || '/',
    window.location.origin
  ).toString();
  const runtime = createNotificationRuntime({
    transport: createNotificationFetchTransport({
      baseUrl,
      fetch: tenantSessionFetch,
    }),
    canRead: () => hasPermission(NOTIFICATION_SMS_READ_PERMISSION),
    canManage: () => hasPermission(NOTIFICATION_SMS_MANAGE_PERMISSION),
  });

  provide(notificationRuntimeKey, runtime);
  onBeforeUnmount(runtime.dispose);
</script>

<template>
  <InboxPage />
</template>
