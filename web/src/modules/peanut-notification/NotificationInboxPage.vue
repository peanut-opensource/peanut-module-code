<script setup lang="ts">
  import { onBeforeUnmount, provide } from 'vue';
  import { useRoute } from 'vue-router';
  import { hasPermission } from '@/hooks/permission';
  import { tenantSessionFetch } from '@/api/tenant-session-fetch';
  import {
    NotificationInboxPage as InboxPage,
    createNotificationFetchTransport,
    createNotificationRuntime,
    NOTIFICATION_SMS_MANAGE_PERMISSION,
    NOTIFICATION_SMS_READ_PERMISSION,
    notificationRuntimeKey,
  } from './optional-runtime/src';

  const route = useRoute();
  const baseUrl = new URL(
    import.meta.env.VITE_API_BASE_URL || '/',
    window.location.origin
  ).toString();
  const runtime = createNotificationRuntime({
    transport: createNotificationFetchTransport({
      baseUrl,
      fetch: tenantSessionFetch,
    }),
    canRead: () => hasPermission(NOTIFICATION_SMS_READ_PERMISSION, route.path),
    canManage: () =>
      hasPermission(NOTIFICATION_SMS_MANAGE_PERMISSION, route.path),
  });

  provide(notificationRuntimeKey, runtime);
  onBeforeUnmount(runtime.dispose);
</script>

<template>
  <InboxPage />
</template>
