<template>
  <ContentSurface
    v-bind="props"
    intent="edit"
    @ready="emit('ready')"
    @capabilities-changed="emit('capabilities-changed', $event)"
    @dirty-change="emit('dirty-change', $event)"
    @save-request="emit('save-request', $event)"
    @error="emit('error', $event)"
  />
</template>

<script setup lang="ts">
  import ContentSurface from './ContentSurface.vue';
  import type { ContentSurfaceProps, SurfaceCapabilities } from './contracts';

  const props = defineProps<ContentSurfaceProps>();
  const emit = defineEmits<{
    (event: 'ready'): void;
    (event: 'capabilities-changed', value: SurfaceCapabilities): void;
    (event: 'dirty-change', value: boolean): void;
    (event: 'save-request', value: unknown): void;
    (event: 'error', code: string): void;
  }>();
</script>
