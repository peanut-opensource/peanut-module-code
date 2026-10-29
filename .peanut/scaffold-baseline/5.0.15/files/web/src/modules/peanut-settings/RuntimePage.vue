<script setup lang="ts">
  import { onBeforeUnmount, provide } from 'vue';
  import { hasPermission } from '@/hooks/permission';
  import { tenantSessionFetch } from '@/api/tenant-session-fetch';
  import SettingsPage from './runtime/src/SettingsPage.vue';
  import {
    createSettingsFetchTransport,
    createSettingsRuntime,
    SETTINGS_MANAGE_PERMISSION,
    SETTINGS_READ_PERMISSION,
    settingsRuntimeKey,
  } from './runtime/src';

  const apiBaseUrl = new URL(
    import.meta.env.VITE_API_BASE_URL || '/',
    window.location.origin
  ).toString();
  const runtime = createSettingsRuntime({
    transport: createSettingsFetchTransport({
      baseUrl: apiBaseUrl,
      fetch: tenantSessionFetch,
    }),
    canRead: () => hasPermission(SETTINGS_READ_PERMISSION),
    canManage: () => hasPermission(SETTINGS_MANAGE_PERMISSION),
  });

  provide(settingsRuntimeKey, runtime);
  onBeforeUnmount(runtime.dispose);
</script>

<template>
  <SettingsPage />
</template>
