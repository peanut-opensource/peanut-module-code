<template>
  <div :class="{ 'file-content-thumbnail': compact }">
    <p v-if="unavailableMessage" role="status">{{ unavailableMessage }}</p>
    <ContentViewer
      v-if="surface"
      :content="surface.content"
      :capabilities="surface.capabilities"
      :callbacks="callbacks"
      :registry="registry"
      :context-key="contextKey"
      :allowed-operations="operations"
      @error="failed = true"
    />
    <p v-else role="status">文件预览元数据不可用，请刷新列表。</p>
    <p v-if="failed" role="status">受控文件访问失败，请刷新权限后重试。</p>
  </div>
</template>

<script setup lang="ts">
  import { computed, ref, watch } from 'vue';
  import ContentViewer from '@/modules/peanut-content-tools/src/ContentViewer.vue';
  import parseSurfaceCapabilities from '@/modules/peanut-content-tools/src/capabilities';
  import type ContentToolRegistry from '@/modules/peanut-content-tools/src/registry';
  import type {
    FileResourceContent,
    SurfaceCapabilities,
  } from '@/modules/peanut-content-tools/src/contracts';
  import { createFileContentCallbacks } from '../optional-runtime/src/contentCallbacks';
  import type { FileRecord } from '../api';

  const props = defineProps<{
    item: FileRecord;
    contextKey: string;
    registry: ContentToolRegistry;
    compact?: boolean;
  }>();
  const failed = ref(false);
  const surface = computed(
    (): {
      content: FileResourceContent;
      capabilities: SurfaceCapabilities;
    } | null => {
      try {
        const capabilities = parseSurfaceCapabilities(props.item.capabilities);
        const mime =
          capabilities.verifiedMimeType ?? props.item.verified_mime_type;
        if (
          capabilities.contentKind !== 'file_resource' ||
          typeof props.item.file_key !== 'string' ||
          !/^file_[0-9a-f]{32}$/.test(props.item.file_key) ||
          typeof props.item.source_sha256 !== 'string' ||
          !/^[0-9a-f]{64}$/.test(props.item.source_sha256) ||
          typeof mime !== 'string'
        )
          return null;
        return {
          content: {
            kind: 'file_resource',
            identity: props.item.file_key,
            fileKey: props.item.file_key,
            verifiedMimeType: mime,
            sourceSha256: props.item.source_sha256,
            displayName: props.item.name,
          },
          capabilities,
        };
      } catch {
        return null;
      }
    }
  );
  const operations = computed<readonly ('view' | 'download_original')[]>(() => {
    // Listing has already resolved current File preview authorization. The
    // surface still requires its independent canView capability before rendering.
    const result: ('view' | 'download_original')[] = ['view'];
    if (
      surface.value?.capabilities.canDownloadOriginal &&
      props.item.can_download_original === true
    )
      result.push('download_original');
    return result;
  });
  const callbacks = computed(() =>
    createFileContentCallbacks({
      viewerAdapterId: surface.value?.capabilities.viewerAdapterId ?? '',
      canDownloadOriginal: () =>
        props.item.can_download_original === true &&
        surface.value?.capabilities.canDownloadOriginal === true,
    })
  );
  const unavailableMessage = computed(() => {
    if (props.item.preview_unavailable_reason === 'CONTENT_PROVIDER_DISABLED')
      return '内容工具未开通，预览不可用。';
    if (props.item.preview_state === 'service_unavailable')
      return '内容工具暂不可用。';
    if (props.item.preview_state === 'preview_unavailable')
      return '该文件暂无可用的受控预览。';
    if (props.item.preview_state === 'permission_denied')
      return '当前身份未获预览授权。';
    return '';
  });
  watch(
    () => [props.contextKey, props.item],
    () => {
      failed.value = false;
    }
  );
</script>

<style scoped>
  .file-content-thumbnail {
    overflow: hidden;
  }
  .file-content-thumbnail :deep(.content-tools-surface > button) {
    display: none;
  }
  .file-content-thumbnail :deep(object) {
    height: 100px;
    min-height: 0;
  }
  .file-content-thumbnail :deep(img),
  .file-content-thumbnail :deep(video) {
    max-height: 100px;
    object-fit: contain;
  }
  .file-content-thumbnail p {
    font-size: 12px;
  }
</style>
