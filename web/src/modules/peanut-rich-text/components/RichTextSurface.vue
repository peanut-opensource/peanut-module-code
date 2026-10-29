<template>
  <RichTextEditor
    :model-value="value"
    :context-key="contextKey"
    :callbacks="callbacks"
    :registry="registry"
    :collaboration-binding="collaborationBinding"
    :read-only="intent === 'view' || !capabilities.canEdit"
    @ready="ready"
    @dirty-change="dirty"
    @update:model-value="value = $event"
    @error="invalid"
  />
  <button
    v-if="intent === 'edit' && capabilities.canEdit && !collaborationBinding"
    type="button"
    :disabled="!changed || !valid"
    @click="emit('save-request', value)"
    >请求保存</button
  >
</template>

<script setup lang="ts">
  import { ref, watch } from 'vue';
  import type {
    RichTextContent,
    SurfaceCallbacks,
    SurfaceCapabilities,
    SurfaceIntent,
    ContentCollaborationBinding,
  } from '@/modules/peanut-content-tools/src/contracts';
  import type ContentToolRegistry from '@/modules/peanut-content-tools/src/registry';
  import RichTextEditor from './RichTextEditor.vue';
  import type { RichTextDocumentValue } from '../src/document';

  const props = defineProps<{
    content: RichTextContent;
    contextKey: string;
    callbacks: SurfaceCallbacks;
    capabilities: SurfaceCapabilities;
    registry: ContentToolRegistry;
    intent: SurfaceIntent;
    collaborationBinding?: ContentCollaborationBinding;
  }>();
  const emit = defineEmits<{
    (event: 'ready'): void;
    (event: 'dirty-change', value: boolean): void;
    (event: 'save-request', value: RichTextDocumentValue): void;
    (event: 'error', code: string): void;
  }>();
  const value = ref(props.content.value as RichTextDocumentValue);
  const changed = ref(false);
  const valid = ref(false);
  const ready = () => {
    valid.value = true;
    emit('ready');
  };
  const invalid = (code: string) => {
    // Rejected link/media input leaves the last validated document unchanged.
    // Never turn an already invalid document valid on an operation error.
    if (!['RICH_TEXT_LINK_INVALID', 'RICH_TEXT_MEDIA_INVALID'].includes(code))
      valid.value = false;
    emit('error', code);
  };
  watch(
    () => props.content.value,
    (next) => {
      value.value = next as RichTextDocumentValue;
      changed.value = false;
    }
  );
  const dirty = (state: boolean) => {
    changed.value = state;
    emit('dirty-change', state);
  };
</script>
