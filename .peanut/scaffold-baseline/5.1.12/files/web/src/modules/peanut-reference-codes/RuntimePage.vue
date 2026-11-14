<script setup lang="ts">
  import { onBeforeUnmount, provide } from 'vue';
  import { useRoute } from 'vue-router';
  import { hasPermission } from '@/hooks/permission';
  import { tenantSessionFetch } from '@/api/tenant-session-fetch';
  import {
    ReferenceCodesPage,
    createReferenceCodesFetchTransport,
    createReferenceCodesRuntime,
    REFERENCE_CODES_MANAGE_PERMISSION,
    REFERENCE_CODES_READ_PERMISSION,
    referenceCodesRuntimeKey,
  } from './runtime/src';

  const route = useRoute();
  const apiBaseUrl = new URL(
    import.meta.env.VITE_API_BASE_URL || '/',
    window.location.origin
  ).toString();
  const runtime = createReferenceCodesRuntime({
    transport: createReferenceCodesFetchTransport({
      baseUrl: apiBaseUrl,
      fetch: tenantSessionFetch,
    }),
    canRead: () => hasPermission(REFERENCE_CODES_READ_PERMISSION, route.path),
    canManage: () =>
      hasPermission(REFERENCE_CODES_MANAGE_PERMISSION, route.path),
  });

  provide(referenceCodesRuntimeKey, runtime);
  onBeforeUnmount(runtime.dispose);
</script>

<template>
  <ReferenceCodesPage />
</template>
