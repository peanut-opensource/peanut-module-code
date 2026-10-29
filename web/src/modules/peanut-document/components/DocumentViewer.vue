<template>
  <DocumentSurface
    v-bind="props"
    mode="read"
    :read-only="true"
    @ready="emit('ready')"
    @load="emit('load', $event)"
    @capabilities="emit('capabilities', $event)"
    @error="emit('error', $event)"
  />
</template>

<script setup lang="ts">
  import type { SurfaceCapabilities } from '@/modules/peanut-content-tools/src/contracts';
  import DocumentSurface from './DocumentSurface.vue';
  import type {
    DocumentSurfaceOptions,
    DocumentDetail,
  } from '../src/contracts';

  const props = defineProps<DocumentSurfaceOptions & { revisionId?: number }>();
  const emit = defineEmits<{
    (event: 'ready'): void;
    (event: 'load', detail: DocumentDetail): void;
    (event: 'capabilities', value: SurfaceCapabilities): void;
    (event: 'error', code: string): void;
  }>();
</script>
