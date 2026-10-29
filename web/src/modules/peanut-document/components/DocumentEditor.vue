<template>
  <DocumentSurface
    ref="surface"
    v-bind="props"
    :revision-id="initialRevisionId"
    @ready="emit('ready')"
    @load="emit('load', $event)"
    @capabilities="emit('capabilities', $event)"
    @save-state="emit('saveState', $event)"
    @error="emit('error', $event)"
    @operation-state="emit('operationState', $event)"
  />
</template>

<script setup lang="ts">
  import { ref } from 'vue';
  import type { SurfaceCapabilities } from '@/modules/peanut-content-tools/src/contracts';
  import DocumentSurface from './DocumentSurface.vue';
  import type {
    DocumentSurfaceOptions,
    DocumentDetail,
    DocumentSaveState,
    DocumentOperationFeedback,
  } from '../src/contracts';

  const surface = ref<InstanceType<typeof DocumentSurface>>();
  const prepareSaveTarget = (requestId: string) => {
    if (!surface.value) throw new Error('DOCUMENT_COLLABORATION_NOT_READY');
    return surface.value.prepareSaveTarget(requestId);
  };
  const releaseSaveTargetHold = () => surface.value?.releaseSaveTargetHold();
  defineExpose({ prepareSaveTarget, releaseSaveTargetHold });

  const props = withDefaults(
    defineProps<
      DocumentSurfaceOptions & {
        mode?: 'read' | 'edit';
        readOnly?: boolean;
        initialRevisionId?: number;
      }
    >(),
    { mode: 'edit' }
  );
  const emit = defineEmits<{
    (event: 'ready'): void;
    (event: 'load', detail: DocumentDetail): void;
    (event: 'capabilities', value: SurfaceCapabilities): void;
    (event: 'saveState', state: DocumentSaveState): void;
    (event: 'error', code: string): void;
    (event: 'operationState', state: DocumentOperationFeedback): void;
  }>();
</script>
