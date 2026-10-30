<template
  ><RichTextEditor
    v-bind="$attrs"
    :model-value="modelValue"
    :context-key="contextKey"
    :callbacks="callbacks"
    :registry="registry"
    read-only
/></template>

<script setup lang="ts">
  import type {
    ContentToolRegistry,
    SurfaceCallbacks,
  } from '@peanut-admin/peanut-content-tools/surface';
  import RichTextEditor from './RichTextEditor.vue';
  import type { RichTextDocumentValue } from '../src/document';

  defineProps<{
    modelValue: RichTextDocumentValue;
    contextKey?: string;
    callbacks?: SurfaceCallbacks;
    registry?: ContentToolRegistry;
  }>();
</script>
