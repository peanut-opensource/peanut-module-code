<template>
  <div class="content-tools-file-viewer">
    <p v-if="status !== 'ready'" role="status">{{ message }}</p>
    <img
      v-else-if="mediaKind === 'image'"
      :src="access?.url"
      :alt="content.displayName"
      @error="failed"
    />
    <object
      v-else-if="mediaKind === 'pdf'"
      :data="pdfUrl"
      type="application/pdf"
      :aria-label="content.displayName"
      @error="failed"
    >
      <p role="status">当前浏览器无法查看 PDF，请使用已授权的其他查看器。</p>
    </object>
    <audio
      v-else-if="mediaKind === 'audio'"
      :src="access?.url"
      controls
      controlslist="nodownload"
      preload="metadata"
      @error="failed"
    />
    <video
      v-else-if="mediaKind === 'video'"
      :src="access?.url"
      controls
      controlslist="nodownload"
      preload="metadata"
      @error="failed"
    />
  </div>
</template>

<script setup lang="ts">
  import { computed, onBeforeUnmount, shallowRef, watch } from 'vue';
  import type {
    AuthorizedPreviewAccess,
    FileResourceContent,
    SurfaceCallbacks,
    SurfaceCapabilities,
  } from './contracts';

  const props = defineProps<{
    content: FileResourceContent;
    capabilities: SurfaceCapabilities;
    callbacks: SurfaceCallbacks;
  }>();
  const emit = defineEmits<{
    (event: 'ready'): void;
    (event: 'error', code: string): void;
  }>();
  const access = shallowRef<AuthorizedPreviewAccess>();
  const pdfUrl = shallowRef<string>();
  const status = shallowRef<'loading' | 'ready' | 'failed'>('loading');
  const message = computed(() =>
    status.value === 'failed' ? '受控预览暂不可用。' : '正在获取预览权限…'
  );
  const mediaKind = computed(() => {
    const mime = access.value?.mimeType ?? '';
    if (mime === 'application/pdf') return 'pdf';
    return mime.split('/')[0];
  });
  let controller: AbortController | undefined;
  let expiryTimer: ReturnType<typeof setTimeout> | undefined;
  const release = () => {
    controller?.abort();
    if (expiryTimer !== undefined) clearTimeout(expiryTimer);
    access.value?.dispose();
    access.value = undefined;
    if (pdfUrl.value) URL.revokeObjectURL(pdfUrl.value);
    pdfUrl.value = undefined;
  };
  const failed = () => {
    release();
    status.value = 'failed';
    emit('error', 'CONTENT_PREVIEW_UNAVAILABLE');
  };
  const permittedUrl = (url: string): boolean => {
    if (/\s|\\/.test(url)) return false;
    if (url.startsWith('/')) return !url.startsWith('//');
    try {
      const parsed = new URL(url);
      return (
        ['https:', 'http:'].includes(parsed.protocol) &&
        parsed.username === '' &&
        parsed.password === ''
      );
    } catch {
      return false;
    }
  };
  watch(
    () => [props.content, props.capabilities, props.callbacks],
    async () => {
      release();
      const current = new AbortController();
      controller = current;
      status.value = 'loading';
      if (!props.capabilities.canView || !props.callbacks.resolvePreview) {
        failed();
        return;
      }
      try {
        const preview = await props.callbacks.resolvePreview(
          props.content,
          current.signal
        );
        if (current.signal.aborted) {
          preview.dispose();
          return;
        }
        if (
          preview.purpose !== 'preview' ||
          preview.fileKey !== props.content.fileKey ||
          preview.sourceSha256 !== props.content.sourceSha256 ||
          !Number.isFinite(preview.expiresAt) ||
          preview.expiresAt <= Date.now() ||
          !permittedUrl(preview.url) ||
          (!props.capabilities.canDownloadOriginal &&
            preview.bytes !== 'derivative') ||
          ![
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp',
            'image/avif',
            'application/pdf',
            'audio/mpeg',
            'audio/ogg',
            'audio/wav',
            'audio/webm',
            'audio/mp4',
            'video/mp4',
            'video/webm',
            'video/ogg',
          ].includes(preview.mimeType) ||
          (preview.mimeType !== props.content.verifiedMimeType &&
            preview.bytes !== 'derivative')
        ) {
          preview.dispose();
          failed();
          return;
        }
        access.value = preview;
        if (preview.mimeType === 'application/pdf') {
          // File deliberately serves PDF as attachment. Consume only the authorized
          // preview bytes; never alter File disposition or request an original URL.
          const url = new URL(preview.url, window.location.origin);
          if (url.origin !== window.location.origin)
            throw new Error('CONTENT_PDF_ORIGIN_INVALID');
          const response = await fetch(url.href, {
            signal: current.signal,
            credentials: 'same-origin',
            redirect: 'error',
          });
          if (
            !response.ok ||
            response.headers.get('content-type')?.split(';')[0] !==
              'application/pdf' ||
            !response.body
          )
            throw new Error('CONTENT_PDF_RESPONSE_INVALID');
          const reader = response.body.getReader();
          const chunks: Uint8Array<ArrayBuffer>[] = [];
          let size = 0;
          try {
            const read = async (): Promise<void> => {
              const { value, done } = await reader.read();
              if (done) return;
              size += value.byteLength;
              if (size > 32 * 1024 * 1024)
                throw new Error('CONTENT_PDF_TOO_LARGE');
              chunks.push(new Uint8Array(value));
              await read();
            };
            await read();
          } finally {
            await reader.cancel();
          }
          if (current.signal.aborted) return;
          if (size === 0 || preview.expiresAt <= Date.now())
            throw new Error('CONTENT_PDF_EXPIRED');
          pdfUrl.value = URL.createObjectURL(
            new Blob(chunks, { type: 'application/pdf' })
          );
        }
        status.value = 'ready';
        expiryTimer = setTimeout(
          failed,
          Math.min(preview.expiresAt - Date.now(), 2147483647)
        );
        emit('ready');
      } catch {
        if (!current.signal.aborted) failed();
      }
    },
    { immediate: true }
  );
  onBeforeUnmount(release);
</script>

<style scoped>
  .content-tools-file-viewer img,
  .content-tools-file-viewer video {
    max-width: 100%;
  }
  .content-tools-file-viewer object {
    width: 100%;
    min-height: 32rem;
  }
</style>
