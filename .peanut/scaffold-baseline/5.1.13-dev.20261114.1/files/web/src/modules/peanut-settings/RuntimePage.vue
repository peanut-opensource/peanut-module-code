<script setup lang="ts">
  import { onBeforeUnmount, provide } from 'vue';
  import { useRoute } from 'vue-router';
  import { hasPermission } from '@/hooks/permission';
  import { tenantSessionFetch } from '@/api/tenant-session-fetch';
  import {
    SettingsPage,
    createSettingsFetchTransport,
    createSettingsRuntime,
    SETTINGS_MANAGE_PERMISSION,
    SETTINGS_READ_PERMISSION,
    settingsRuntimeKey,
  } from './runtime/src';

  const route = useRoute();
  const apiBaseUrl = new URL(
    import.meta.env.VITE_API_BASE_URL || '/',
    window.location.origin
  ).toString();
  const runtime = createSettingsRuntime({
    transport: createSettingsFetchTransport({
      baseUrl: apiBaseUrl,
      fetch: tenantSessionFetch,
    }),
    canRead: () => hasPermission(SETTINGS_READ_PERMISSION, route.path),
    canManage: () => hasPermission(SETTINGS_MANAGE_PERMISSION, route.path),
  });

  provide(settingsRuntimeKey, runtime);
  onBeforeUnmount(runtime.dispose);
</script>

<template>
  <SettingsPage />
</template>
