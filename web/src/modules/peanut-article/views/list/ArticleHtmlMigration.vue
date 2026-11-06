<template>
  <section :aria-busy="busy">
    <p role="status"
      >旧 HTML
      仅供读取。预览并明确确认后，将创建未发布的正文草稿；不会执行业务保存或发布。</p
    >
    <p v-if="message" role="status">{{ message }}</p>
    <button
      data-preview
      type="button"
      :disabled="busy || !!attempt"
      @click="preview"
      >预览 HTML 迁移</button
    >
    <template v-if="source">
      <h4>原始 HTML</h4>
      <pre>{{ source.source_html }}</pre>
      <ul v-if="limitations.length" role="alert">
        <li v-for="item in limitations" :key="item.code + item.message"
          >{{ item.code }}：{{ item.message }}</li
        >
      </ul>
      <template v-if="converted">
        <h4>转换预览</h4>
        <RichTextViewer :model-value="converted" :context-key="context" />
        <label
          ><input
            v-model="confirmed"
            type="checkbox"
            :disabled="busy || !!attempt"
          />我已核对原文和转换预览，确认迁移为未发布的结构化草稿。</label
        >
        <button
          v-if="!attempt"
          data-migrate
          type="button"
          :disabled="busy || !confirmed"
          @click="migrate"
          >确认迁移</button
        >
      </template>
    </template>
    <button
      v-if="attempt"
      data-retry
      type="button"
      :disabled="busy"
      @click="submitOriginal"
      >继续原迁移请求</button
    >
  </section>
</template>

<script setup lang="ts">
  import { computed, onBeforeUnmount, ref, shallowRef, watch } from 'vue';
  import { RichTextViewer } from '@peanut-admin/peanut-rich-text/viewer';
  import { previewRichTextHtml } from '@peanut-admin/peanut-rich-text/editor';
  import type { RichTextDocumentValue } from '@peanut-admin/peanut-rich-text/document';
  import { useUserStore } from '@/store';
  import { getSessionSnapshot } from '@/utils/auth';
  import randomUuid from '@/utils/random-uuid';
  import {
    previewArticleHtml,
    migrateArticleHtml,
    ArticleHtmlMigrationRejected,
    type ArticleHtmlPreview,
    type ArticleHtmlMigrationInput,
    type ArticleHtmlMigrated,
  } from '../../api';

  const props = defineProps<{ articleId: number; contextKey: string }>();
  const emit = defineEmits<{
    (event: 'locked', value: boolean): void;
    (event: 'completed', value: ArticleHtmlMigrated): void;
  }>();
  const user = useUserStore();
  const generation = ref(getSessionSnapshot().generation);
  const unsubscribe = user.$subscribe(
    () => {
      generation.value = getSessionSnapshot().generation;
    },
    { flush: 'sync' }
  );
  const context = computed(() =>
    JSON.stringify([
      props.articleId,
      props.contextKey,
      generation.value,
      user.id,
      user.accountId,
      user.tenantName,
      user.role,
      user.permissions,
    ])
  );
  const source = shallowRef<ArticleHtmlPreview>();
  const converted = shallowRef<RichTextDocumentValue>();
  const limitations = ref<Array<{ code: string; message: string }>>([]);
  const confirmed = ref(false);
  const busy = ref(false);
  const message = ref('');
  const attempt = shallowRef<ArticleHtmlMigrationInput>();
  let request: AbortController | undefined;
  let disposed = false;
  const current = (
    controller: AbortController,
    key: string,
    session: string | number
  ) =>
    !disposed &&
    !controller.signal.aborted &&
    key === context.value &&
    session === getSessionSnapshot().generation;
  watch(
    () => busy.value || !!attempt.value,
    (locked) => emit('locked', locked),
    { flush: 'sync' }
  );
  const preview = async () => {
    if (busy.value || attempt.value) return;
    source.value = undefined;
    converted.value = undefined;
    limitations.value = [];
    confirmed.value = false;
    message.value = '';
    const controller = new AbortController();
    request = controller;
    const key = context.value;
    const session = getSessionSnapshot().generation;
    busy.value = true;
    try {
      const { data } = await previewArticleHtml(
        props.articleId,
        controller.signal
      );
      if (!current(controller, key, session)) return;
      if (data.article_id !== props.articleId)
        throw new Error('ARTICLE_HTML_OWNER_MISMATCH');
      source.value = data;
      const result = previewRichTextHtml(data.source_html);
      limitations.value = result.limitations;
      if (result.ok) converted.value = result.document;
      else message.value = '原文包含无法无损转换的内容，迁移已停止。';
    } catch {
      if (current(controller, key, session))
        message.value = '迁移预览失败，请检查授权或原文状态后重试。';
    } finally {
      if (request === controller) {
        busy.value = false;
        request = undefined;
      }
    }
  };
  const submitOriginal = async () => {
    if (generation.value !== getSessionSnapshot().generation) {
      generation.value = getSessionSnapshot().generation;
      return;
    }
    const original = attempt.value;
    if (busy.value || !original) return;
    const controller = new AbortController();
    request = controller;
    const key = context.value;
    const session = getSessionSnapshot().generation;
    busy.value = true;
    try {
      const { data } = await migrateArticleHtml(original, controller.signal);
      if (!current(controller, key, session)) return;
      if (
        data.article_id !== original.id ||
        data.request_id !== original.request_id ||
        data.source_sha256 !== original.source_sha256
      )
        throw new Error('ARTICLE_HTML_RESULT_MISMATCH');
      attempt.value = undefined;
      emit('completed', data);
    } catch (error) {
      if (!current(controller, key, session)) return;
      if (error instanceof ArticleHtmlMigrationRejected) {
        attempt.value = undefined;
        source.value = undefined;
        converted.value = undefined;
        confirmed.value = false;
        message.value = error.message;
      } else {
        message.value =
          '迁移结果尚未确认。请继续原迁移请求以确认结果；当前请求和转换内容已保留。';
      }
    } finally {
      if (request === controller) {
        busy.value = false;
        request = undefined;
      }
    }
  };
  const migrate = () => {
    if (
      busy.value ||
      attempt.value ||
      !confirmed.value ||
      !source.value ||
      !converted.value
    )
      return;
    // Store a detached fixed snapshot. Unknown outcomes always replay these same bytes and request ID.
    attempt.value = JSON.parse(
      JSON.stringify({
        id: props.articleId,
        request_id: randomUuid(),
        confirmed: true,
        source_sha256: source.value.source_sha256,
        owner_revision: source.value.owner_revision,
        structured_content: converted.value,
      })
    ) as ArticleHtmlMigrationInput;
    submitOriginal();
  };
  const reset = () => {
    request?.abort();
    request = undefined;
    busy.value = false;
    attempt.value = undefined;
    source.value = undefined;
    converted.value = undefined;
    limitations.value = [];
    confirmed.value = false;
    message.value = '';
  };
  watch(context, reset, { flush: 'sync' });
  const storage = (event: StorageEvent) => {
    if (event.storageArea !== null && event.storageArea !== localStorage)
      return;
    generation.value = getSessionSnapshot().generation;
  };
  window.addEventListener('storage', storage);
  onBeforeUnmount(() => {
    disposed = true;
    reset();
    unsubscribe();
    window.removeEventListener('storage', storage);
  });
</script>

<style scoped>
  pre {
    max-height: 240px;
    overflow: auto;
    white-space: pre-wrap;
    overflow-wrap: anywhere;
  }
  button {
    margin: 8px 8px 8px 0;
  }
  label {
    display: block;
    margin-top: 12px;
  }
</style>
