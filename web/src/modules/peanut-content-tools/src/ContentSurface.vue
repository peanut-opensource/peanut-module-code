<template>
  <section class="content-tools-surface" :aria-busy="downloading">
    <p v-if="!adapter" role="status">{{ message }}</p>
    <component
      :is="adapter.component"
      v-else
      :key="sessionKey"
      :content="content"
      :capabilities="effectiveCapabilities"
      :callbacks="callbacks"
      :registry="registry"
      :context-key="contextKey"
      :intent="intent"
      :collaboration-binding="collaborationBinding"
      @ready="emit('ready')"
      @dirty-change="emit('dirty-change', $event)"
      @save-request="requestSave"
      @error="emit('error', $event)"
    />
    <button v-if="canDownload" :disabled="downloading" @click="download">
      下载原件
    </button>
  </section>
</template>

<script setup lang="ts">
  import { computed, onBeforeUnmount, ref, watch } from 'vue';
  import type { ContentSurfaceProps, SurfaceIntent } from './contracts';

  const props = defineProps<ContentSurfaceProps & { intent: SurfaceIntent }>();
  const emit = defineEmits<{
    (event: 'ready'): void;
    (
      event: 'capabilities-changed',
      value: ContentSurfaceProps['capabilities']
    ): void;
    (event: 'dirty-change', value: boolean): void;
    (event: 'save-request', value: unknown): void;
    (event: 'error', code: string): void;
  }>();
  const effectiveCapabilities = computed(() => ({
    ...props.capabilities,
    canView:
      props.capabilities.canView && props.allowedOperations.includes('view'),
    canEdit:
      props.capabilities.canEdit && props.allowedOperations.includes('edit'),
    canDownloadOriginal:
      props.capabilities.canDownloadOriginal &&
      props.allowedOperations.includes('download_original'),
  }));
  const permitted = computed(() =>
    props.intent === 'view'
      ? effectiveCapabilities.value.canView
      : effectiveCapabilities.value.canEdit
  );
  const adapter = computed(() => {
    if (
      !permitted.value ||
      !['available', 'read_only'].includes(props.capabilities.state) ||
      props.content.kind !== props.capabilities.contentKind ||
      (props.content.kind === 'file_resource' &&
        props.content.verifiedMimeType !== props.capabilities.verifiedMimeType)
    )
      return null;
    return props.registry.selected(
      props.intent === 'view'
        ? props.capabilities.viewerAdapterId
        : props.capabilities.editorAdapterId,
      props.content,
      props.intent
    );
  });
  const sessionKey = computed(() =>
    JSON.stringify([
      props.contextKey,
      props.content.identity,
      props.capabilities.capabilityRevision,
      props.allowedOperations,
      props.intent,
      adapter.value?.id,
      props.collaborationBinding?.generation,
    ])
  );
  const message = computed(() => {
    if (
      !props.allowedOperations.includes(props.intent) ||
      props.capabilities.state === 'permission_denied'
    )
      return '当前内容操作未获授权。';
    if (props.capabilities.state === 'service_unavailable')
      return '内容工具服务暂不可用。';
    return props.intent === 'edit'
      ? '该格式尚无可用编辑器。'
      : '该格式尚无可用预览器。';
  });
  const canDownload = computed(
    () =>
      props.content.kind === 'file_resource' &&
      effectiveCapabilities.value.canDownloadOriginal &&
      !!props.callbacks.downloadOriginal
  );
  const downloading = ref(false);
  let downloadController: AbortController | undefined;
  const cancel = () => {
    downloadController?.abort();
    downloading.value = false;
  };
  const download = async () => {
    if (
      downloading.value ||
      !canDownload.value ||
      props.content.kind !== 'file_resource' ||
      !props.callbacks.downloadOriginal
    )
      return;
    const current = new AbortController();
    downloadController = current;
    downloading.value = true;
    try {
      await props.callbacks.downloadOriginal(props.content, current.signal);
    } catch {
      if (!current.signal.aborted) emit('error', 'CONTENT_DOWNLOAD_FAILED');
    } finally {
      if (downloadController === current) downloading.value = false;
    }
  };
  const requestSave = (value: unknown) => {
    if (props.intent !== 'edit' || !permitted.value || !adapter.value) return;
    emit('save-request', value);
  };
  watch(sessionKey, cancel);
  watch(effectiveCapabilities, (value) => emit('capabilities-changed', value), {
    immediate: true,
  });
  onBeforeUnmount(cancel);
</script>
