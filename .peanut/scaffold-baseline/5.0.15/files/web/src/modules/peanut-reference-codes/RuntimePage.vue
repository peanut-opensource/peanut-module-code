<script setup lang="ts">
  import { onBeforeUnmount, provide } from 'vue';
  import { hasPermission } from '@/hooks/permission';
  import { tenantSessionFetch } from '@/api/tenant-session-fetch';
  import ReferenceCodesPage from './runtime/src/ReferenceCodesPage.vue';
  import {
    createReferenceCodesFetchTransport,
    createReferenceCodesRuntime,
    REFERENCE_CODES_MANAGE_PERMISSION,
    REFERENCE_CODES_READ_PERMISSION,
    referenceCodesRuntimeKey,
  } from './runtime/src';

  const apiBaseUrl = new URL(
    import.meta.env.VITE_API_BASE_URL || '/',
    window.location.origin
  ).toString();
  const runtime = createReferenceCodesRuntime({
    transport: createReferenceCodesFetchTransport({
      baseUrl: apiBaseUrl,
      fetch: tenantSessionFetch,
    }),
    canRead: () => hasPermission(REFERENCE_CODES_READ_PERMISSION),
    canManage: () => hasPermission(REFERENCE_CODES_MANAGE_PERMISSION),
  });

  provide(referenceCodesRuntimeKey, runtime);
  onBeforeUnmount(runtime.dispose);
</script>

<template>
  <ReferenceCodesPage />
</template>
