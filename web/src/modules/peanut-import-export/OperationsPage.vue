<script setup lang="ts">
  import { onBeforeUnmount, provide } from 'vue';
  import { hasPermission } from '@/hooks/permission';
  import { tenantSessionFetch } from '@/api/tenant-session-fetch';
  import ImportExportPage from './optional-runtime/src/ImportExportPage.vue';
  import {
    createImportExportFetchTransport,
    createImportExportRuntime,
    IMPORT_EXPORT_CANCEL_PERMISSION,
    IMPORT_EXPORT_CREATE_PERMISSION,
    IMPORT_EXPORT_READ_PERMISSION,
    importExportRuntimeKey,
  } from './optional-runtime/src';

  const baseUrl = new URL(
    import.meta.env.VITE_API_BASE_URL || '/',
    window.location.origin
  ).toString();
  const runtime = createImportExportRuntime({
    transport: createImportExportFetchTransport({
      baseUrl,
      fetch: tenantSessionFetch,
    }),
    canRead: () => hasPermission(IMPORT_EXPORT_READ_PERMISSION),
    canCreate: () => hasPermission(IMPORT_EXPORT_CREATE_PERMISSION),
    canCancel: () => hasPermission(IMPORT_EXPORT_CANCEL_PERMISSION),
  });

  provide(importExportRuntimeKey, runtime);
  onBeforeUnmount(runtime.dispose);
</script>

<template>
  <ImportExportPage />
</template>
