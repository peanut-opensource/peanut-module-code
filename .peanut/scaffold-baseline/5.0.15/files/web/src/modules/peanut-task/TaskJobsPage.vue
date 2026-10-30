<script setup lang="ts">
  import { onBeforeUnmount, provide } from 'vue';
  import { useRoute } from 'vue-router';
  import { hasPermission } from '@/hooks/permission';
  import { tenantSessionFetch } from '@/api/tenant-session-fetch';
  import TaskJobPage from './optional-runtime/src/TaskJobPage.vue';
  import {
    createTaskJobFetchTransport,
    createTaskJobRuntime,
    TASK_JOB_MANAGE_PERMISSION,
    TASK_JOB_READ_PERMISSION,
    taskJobRuntimeKey,
  } from './optional-runtime/src';

  const route = useRoute();
  const baseUrl = new URL(
    import.meta.env.VITE_API_BASE_URL || '/',
    window.location.origin
  ).toString();
  const runtime = createTaskJobRuntime({
    transport: createTaskJobFetchTransport({
      baseUrl,
      fetch: tenantSessionFetch,
    }),
    canRead: () => hasPermission(TASK_JOB_READ_PERMISSION, route.path),
    canManage: () => hasPermission(TASK_JOB_MANAGE_PERMISSION, route.path),
  });

  provide(taskJobRuntimeKey, runtime);
  onBeforeUnmount(runtime.dispose);
</script>

<template>
  <TaskJobPage />
</template>
