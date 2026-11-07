<template>
  <DocumentSurface
    v-bind="{ ...$attrs, ...surfaceProps }"
    mode="read"
    :read-only="true"
    @ready="emit('ready')"
    @load="emit('load', $event)"
    @capabilities="emit('capabilities', $event)"
    @error="emit('error', $event)"
  />
  <DocumentRevisionComments
    v-if="props.comments && props.revisionId !== undefined && props.contextKey"
    :document-id="props.documentId"
    :revision-id="props.revisionId"
    :context-key="props.contextKey"
    :owner="props.owner"
    @change="emit('comment', $event)"
    @error="emit('error', $event)"
  />
  <p v-else-if="props.comments">评论需要明确的固定修订和当前身份上下文。</p>
</template>

<script setup lang="ts">
  import { computed } from 'vue';
  import type { SurfaceCapabilities } from '@/modules/peanut-content-tools/src/contracts';
  import DocumentSurface from './DocumentSurface.vue';
  import DocumentRevisionComments from './DocumentRevisionComments.vue';
  import type { DocumentViewerOptions, DocumentDetail } from '../src/contracts';
  import type { DocumentCommentReceipt } from '../src/comment-contracts';

  defineOptions({ inheritAttrs: false });
  const props = defineProps<DocumentViewerOptions>();
  const surfaceProps = computed(() => {
    const { comments: _comments, ...surface } = props;
    return surface;
  });
  const emit = defineEmits<{
    (event: 'ready'): void;
    (event: 'load', detail: DocumentDetail): void;
    (event: 'capabilities', value: SurfaceCapabilities): void;
    (event: 'error', code: string): void;
    (event: 'comment', receipt: DocumentCommentReceipt): void;
  }>();
</script>
