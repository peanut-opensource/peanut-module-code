<template>
  <section aria-label="正文历史" :aria-busy="loading">
    <el-button v-if="!opened" :disabled="loading" @click="load(false)"
      >打开正文历史</el-button
    >
    <template v-else>
      <el-button :disabled="loading" @click="load(false)">刷新历史</el-button>
      <el-button @click="close">关闭历史</el-button>
      <p v-if="error" role="alert">{{ error }}</p>
      <p v-else-if="!loading && !items.length" role="status"
        >当前正文没有已提交的历史版本。</p
      >
      <ul v-if="items.length">
        <li v-for="item in items" :key="item.revision_id">
          版本 {{ item.revision_no }}（ID {{ item.revision_id }}） ·
          {{ item.created_at }} · 正文水位 {{ item.source_state_seq }}
          <el-button @click="selectRevision(item.revision_id)"
            >只读查看</el-button
          >
        </li>
      </ul>
      <el-button
        v-if="nextRevisionNo !== null"
        :disabled="loading"
        @click="load(true)"
        >加载更早版本</el-button
      >
      <p role="status"
        >列表仅含版本摘要；查看正文会重新核验权限，恢复和业务保存仍使用原入口。</p
      >
      <p v-if="selectedRevision" role="status">
        {{ viewerLoaded ? '已核验' : '正在加载并核验' }}版本
        {{ selectedRevision.revision_no }}（ID
        {{ selectedRevision.revision_id }}）的只读正文。
      </p>
      <DocumentViewer
        v-if="selectedRevisionId"
        :key="selectedRevisionId"
        :document-id="documentId"
        :owner="owner"
        :revision-id="selectedRevisionId"
        :context-key="contextKey"
        :allow-downloads="false"
        @load="onViewerLoad"
        @error="deny"
      />
    </template>
  </section>
</template>

<script setup lang="ts">
  import { computed, onBeforeUnmount, ref, shallowRef, watch } from 'vue';
  import { getSessionSnapshot } from '@/utils/auth';
  import DocumentViewer from '@/modules/peanut-document/components/DocumentViewer.vue';
  import { getDocumentVersions } from '@/modules/peanut-document/api';
  import type {
    DocumentDetail,
    DocumentVersionsPage,
  } from '@/modules/peanut-document/src/contracts';

  const props = defineProps<{
    articleId: number;
    documentId: number;
    contextKey: string;
  }>();
  const owner = computed(() => ({
    module_key: 'peanut.article',
    object_type: 'article',
    object_id: String(props.articleId),
    slot: 'body',
  }));
  const opened = ref(false);
  const loading = ref(false);
  const error = ref('');
  const items = shallowRef<DocumentVersionsPage['items']>([]);
  const nextRevisionNo = ref<number | null>(null);
  const selectedRevisionId = ref<number>();
  const selectedRevision = computed(() =>
    items.value.find((item) => item.revision_id === selectedRevisionId.value)
  );
  const viewerLoaded = ref(false);
  const selectRevision = (revisionId: number) => {
    if (selectedRevisionId.value === revisionId) return;
    viewerLoaded.value = false;
    selectedRevisionId.value = revisionId;
  };
  const onViewerLoad = (detail: DocumentDetail) => {
    if (detail.revision_id === selectedRevisionId.value)
      viewerLoaded.value = true;
  };
  let request: AbortController | undefined;
  let epoch = 0;
  let disposed = false;
  let { generation } = getSessionSnapshot();
  const clear = () => {
    epoch += 1;
    request?.abort();
    request = undefined;
    items.value = [];
    nextRevisionNo.value = null;
    selectedRevisionId.value = undefined;
    viewerLoaded.value = false;
    loading.value = false;
    error.value = '';
  };
  const close = () => {
    clear();
    opened.value = false;
  };
  const deny = () => {
    clear();
    error.value = '历史内容不可用，请重新核验当前查看权限和文档归属。';
  };
  const validatePage = (
    value: DocumentVersionsPage,
    before: number | undefined
  ) => {
    const positive = (v: unknown) =>
      typeof v === 'number' && Number.isSafeInteger(v) && v > 0;
    if (
      !value ||
      typeof value !== 'object' ||
      Object.keys(value).sort().join(',') !==
        'document_id,items,next_before_revision_no,owner' ||
      value.document_id !== props.documentId ||
      !value.owner ||
      Object.keys(value.owner).sort().join(',') !==
        'module_key,object_id,object_type,slot' ||
      Object.entries(owner.value).some(
        ([key, v]) => value.owner[key as keyof typeof value.owner] !== v
      ) ||
      !Array.isArray(value.items) ||
      value.items.length > 20 ||
      (value.next_before_revision_no !== null &&
        !positive(value.next_before_revision_no))
    )
      throw new Error('DOCUMENT_HISTORY_RESPONSE_INVALID');
    let previous = before ?? Number.POSITIVE_INFINITY;
    const ids = new Set<number>();
    value.items.forEach((item) => {
      if (
        !item ||
        typeof item !== 'object' ||
        Object.keys(item).sort().join(',') !==
          'content_kind,created_at,revision_id,revision_no,source_state_seq' ||
        !positive(item.revision_id) ||
        !positive(item.revision_no) ||
        item.revision_no >= previous ||
        ids.has(item.revision_id) ||
        typeof item.created_at !== 'string' ||
        !item.created_at ||
        !Number.isSafeInteger(item.source_state_seq) ||
        item.source_state_seq < 0 ||
        !['rich_text', 'file_resource'].includes(item.content_kind)
      )
        throw new Error('DOCUMENT_HISTORY_RESPONSE_INVALID');
      previous = item.revision_no;
      ids.add(item.revision_id);
    });
    if (
      value.next_before_revision_no !== null &&
      (value.items.length !== 20 || value.next_before_revision_no !== previous)
    )
      throw new Error('DOCUMENT_HISTORY_CURSOR_INVALID');
  };
  const load = async (more: boolean) => {
    if (disposed || loading.value || (more && nextRevisionNo.value === null))
      return;
    const before = more ? nextRevisionNo.value ?? undefined : undefined;
    if (!more) clear();
    opened.value = true;
    loading.value = true;
    error.value = '';
    const controller = new AbortController();
    request = controller;
    const current = epoch;
    const sessionGeneration = getSessionSnapshot().generation;
    const binding = `${props.contextKey}:${props.articleId}:${props.documentId}`;
    const live = () => {
      if (
        disposed ||
        controller.signal.aborted ||
        epoch !== current ||
        binding !== `${props.contextKey}:${props.articleId}:${props.documentId}`
      )
        return false;
      if (sessionGeneration !== getSessionSnapshot().generation) {
        close();
        return false;
      }
      return true;
    };
    try {
      const { data } = await getDocumentVersions(
        props.documentId,
        before,
        controller.signal
      );
      if (!live()) return;
      validatePage(data, before);
      if (
        more &&
        data.items.some((item) =>
          items.value.some((old) => old.revision_id === item.revision_id)
        )
      )
        throw new Error('DOCUMENT_HISTORY_RESPONSE_INVALID');
      items.value = more ? [...items.value, ...data.items] : data.items;
      nextRevisionNo.value = data.next_before_revision_no;
    } catch {
      if (live()) {
        clear();
        error.value = '历史查询失败；请检查当前权限和服务状态后显式重试。';
      }
    } finally {
      if (request === controller) {
        loading.value = false;
        request = undefined;
      }
    }
  };
  watch(() => [props.contextKey, props.articleId, props.documentId], close, {
    flush: 'sync',
  });
  const storage = (event: StorageEvent) => {
    if (event.storageArea !== null && event.storageArea !== localStorage)
      return;
    const current = getSessionSnapshot().generation;
    if (current !== generation) {
      generation = current;
      close();
    }
  };
  window.addEventListener('storage', storage);
  onBeforeUnmount(() => {
    disposed = true;
    window.removeEventListener('storage', storage);
    close();
  });
</script>
