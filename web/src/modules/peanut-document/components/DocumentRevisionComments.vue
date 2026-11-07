<template>
  <section class="document-comments" aria-label="固定版本评论">
    <header class="document-comments__header">
      <h3>固定版本评论</h3>
      <button
        type="button"
        :disabled="session.loading || sending"
        @click="refresh"
        >刷新评论</button
      >
    </header>
    <p v-if="session.loading" role="status">正在读取固定版本与当前权限…</p>
    <p
      v-if="session.error || localError"
      class="document-comments__notice"
      role="alert"
    >
      {{ localError || session.error }}。请确认当前版本与权限后重试。
    </p>
    <div v-if="session.pending" class="document-comments__notice" role="status">
      <p>{{ pendingLabel }}。请求始终保留原文档、版本、线程修订与输入。</p>
      <details>
        <summary>查看原请求</summary>
        <code>{{ session.pending.command.input.request_id }}</code>
        <p
          >文档 {{ session.pending.command.input.document_id }} · 修订
          {{ session.pending.command.input.revision_id }}</p
        >
      </details>
      <button type="button" :disabled="sending" @click="retry"
        >重试原请求</button
      >
      <button
        v-if="
          session.pending.state === 'rejected' && !session.pending.uncertain
        "
        type="button"
        @click="restart"
        >重新编辑</button
      >
    </div>

    <template v-if="session.source">
      <p>
        文档 {{ session.source.document_id }} · 固定版本 v{{
          session.source.revision_no
        }}
        （修订 {{ session.source.revision_id }}）
      </p>
      <p class="document-comments__hint"
        >在固定版本文本中选择引用，再建立评论。正文换版后，评论仍保留在此修订。</p
      >
      <label class="document-comments__field">
        <span>固定版本文本</span>
        <textarea
          :value="session.source.text"
          readonly
          rows="8"
          aria-label="选择固定版本的引用文本"
          @select="selectQuote"
          @keyup="selectQuote"
          @mouseup="selectQuote"
        />
      </label>
      <p v-if="!session.canWrite" class="document-comments__hint"
        >当前可查看评论；新增、回复与状态变更需要业务编辑权限。</p
      >
      <div v-if="session.canWrite" class="document-comments__compose">
        <blockquote v-if="anchor">{{ anchor.quote }}</blockquote>
        <p v-else class="document-comments__hint">请先在上方选择一段文本。</p>
        <label class="document-comments__field">
          <span>新评论</span>
          <textarea
            v-model="body"
            :disabled="!!session.pending"
            rows="3"
            placeholder="输入对所选文字的评论"
          />
        </label>
        <span>{{ bodyLength }} / 4000</span>
        <button type="button" :disabled="!canCreate" @click="create"
          >建立评论线程</button
        >
      </div>

      <div class="document-comments__workspace">
        <section aria-label="评论线程">
          <p v-if="session.threads.length === 0">此固定版本暂无评论。</p>
          <ul class="document-comments__threads">
            <li v-for="thread in session.threads" :key="thread.thread_id">
              <button
                type="button"
                :disabled="!!session.pending"
                :aria-pressed="session.active?.thread_id === thread.thread_id"
                @click="session.select(thread.thread_id)"
              >
                <span class="document-comments__quote">{{ thread.quote }}</span>
                <span
                  >{{ thread.status === 'open' ? '未解决' : '已解决' }} · 成员
                  #{{ thread.created_by_member_id }}</span
                >
              </button>
            </li>
          </ul>
          <button
            v-if="session.nextThread !== null"
            type="button"
            :disabled="session.loading || !!session.pending"
            @click="session.load(true)"
            >更多线程</button
          >
        </section>
        <section
          v-if="session.loadingThread || session.active"
          aria-label="线程详情"
        >
          <p v-if="session.loadingThread" role="status">正在读取线程…</p>
          <template v-if="session.active">
            <h4
              >线程 #{{ session.active.thread_id }} · 修订
              {{ session.active.thread_revision }}</h4
            >
            <blockquote>{{ session.active.quote }}</blockquote>
            <p>{{ session.active.status === 'open' ? '未解决' : '已解决' }}</p>
            <ol class="document-comments__messages">
              <li v-for="message in session.messages" :key="message.message_id">
                <p
                  >成员 #{{ message.actor_member_id }} ·
                  {{ actionLabel(message.action) }} ·
                  {{ message.created_at }}</p
                >
                <p
                  v-if="message.body !== null"
                  class="document-comments__quote"
                  >{{ message.body }}</p
                >
              </li>
            </ol>
            <button
              v-if="session.nextMessage !== null"
              type="button"
              :disabled="session.loadingThread || !!session.pending"
              @click="session.select(session.active.thread_id, true)"
              >更多活动</button
            >
            <div v-if="session.canWrite">
              <template v-if="session.active.status === 'open'">
                <label class="document-comments__field">
                  <span>回复</span>
                  <textarea
                    v-model="replyBody"
                    :disabled="!!session.pending"
                    rows="3"
                  />
                </label>
                <button
                  type="button"
                  :disabled="
                    !!session.pending || !replyBody.trim() || replyLength > 4000
                  "
                  @click="reply"
                  >提交回复</button
                >
                <button
                  type="button"
                  :disabled="!!session.pending"
                  @click="transition('resolve')"
                  >标记已解决</button
                >
              </template>
              <button
                v-else
                type="button"
                :disabled="!!session.pending"
                @click="transition('reopen')"
                >重新打开</button
              >
            </div>
          </template>
        </section>
      </div>
    </template>
  </section>
</template>

<script setup lang="ts">
  import { computed, onBeforeUnmount, ref, shallowReactive, watch } from 'vue';
  import type { DocumentOwner } from '../src/contracts';
  import type {
    DocumentCommentAction,
    DocumentCommentAnchor,
    DocumentCommentReceipt,
  } from '../src/comment-contracts';
  import { commentAnchor, RevisionCommentSession } from '../src/comments';
  import { documentCommentTransport } from '../src/comment-api';

  const props = defineProps<{
    documentId: number;
    revisionId: number;
    contextKey: string;
    owner?: DocumentOwner;
  }>();
  const emit = defineEmits<{
    (event: 'change', receipt: DocumentCommentReceipt): void;
    (event: 'error', code: string): void;
  }>();
  const session = shallowReactive(
    new RevisionCommentSession(documentCommentTransport)
  );
  const body = ref('');
  const replyBody = ref('');
  const anchor = ref<DocumentCommentAnchor>();
  const localError = ref<string>();
  let scopeEpoch = 0;
  const bodyLength = computed(() => Array.from(body.value).length);
  const replyLength = computed(() => Array.from(replyBody.value).length);
  const sending = computed(() => session.pending?.state === 'sending');
  const canCreate = computed(
    () =>
      !!anchor.value &&
      !!body.value.trim() &&
      bodyLength.value <= 4000 &&
      session.canWrite &&
      !session.pending &&
      !session.loading &&
      !session.loadingThread
  );
  const pendingLabel = computed(() =>
    sending.value
      ? '正在提交'
      : session.pending?.uncertain
      ? '结果尚未确认'
      : '操作已被拒绝'
  );
  const actionLabel = (action: DocumentCommentAction) =>
    ({
      create: '建立评论',
      reply: '回复',
      resolve: '标记已解决',
      reopen: '重新打开',
    }[action]);

  async function perform(operation: () => Promise<void>) {
    const epoch = scopeEpoch;
    localError.value = undefined;
    try {
      await operation();
    } catch (error) {
      if (epoch !== scopeEpoch) return;
      localError.value =
        error instanceof Error
          ? error.message
          : 'DOCUMENT_COMMENT_OPERATION_FAILED';
      emit('error', localError.value);
    }
  }
  function selectQuote(event: Event) {
    const input = event.target as HTMLTextAreaElement;
    if (
      !session.source ||
      input.value !== session.source.text ||
      input.selectionStart === input.selectionEnd
    ) {
      anchor.value = undefined;
      return;
    }
    try {
      anchor.value = commentAnchor(
        session.source.text,
        input.selectionStart,
        input.selectionEnd
      );
      localError.value = undefined;
    } catch (error) {
      anchor.value = undefined;
      localError.value =
        error instanceof Error
          ? error.message
          : 'DOCUMENT_COMMENT_RANGE_INVALID';
    }
  }
  const refresh = () => perform(() => session.load());
  const retry = () => perform(() => session.retry());
  const restart = () => {
    session.dismissRejected();
    void refresh();
  };
  const create = () =>
    perform(async () => {
      if (!anchor.value) return;
      const epoch = scopeEpoch;
      await session.create(anchor.value, body.value);
      if (
        epoch === scopeEpoch &&
        !session.pending &&
        session.lastReceipt?.action === 'create'
      ) {
        body.value = '';
        anchor.value = undefined;
      }
    });
  const reply = () =>
    perform(async () => {
      const epoch = scopeEpoch;
      await session.reply(replyBody.value);
      if (
        epoch === scopeEpoch &&
        !session.pending &&
        session.lastReceipt?.action === 'reply'
      )
        replyBody.value = '';
    });
  const transition = (action: 'resolve' | 'reopen') =>
    perform(() => session.transition(action));
  watch(
    () => [
      props.documentId,
      props.revisionId,
      props.contextKey,
      props.owner?.module_key,
      props.owner?.object_type,
      props.owner?.object_id,
      props.owner?.slot,
    ],
    () => {
      scopeEpoch += 1;
      body.value = '';
      replyBody.value = '';
      anchor.value = undefined;
      localError.value = undefined;
      try {
        session.setScope({
          documentId: props.documentId,
          revisionId: props.revisionId,
          contextKey: props.contextKey,
          owner: props.owner,
        });
        void session.load();
      } catch (error) {
        localError.value =
          error instanceof Error
            ? error.message
            : 'DOCUMENT_COMMENT_CONTEXT_REQUIRED';
      }
    },
    { immediate: true }
  );
  watch(
    () => session.active?.thread_id,
    () => {
      replyBody.value = '';
    }
  );
  watch(
    () => session.error,
    (error) => {
      if (error) emit('error', error);
    }
  );
  watch(
    () => session.lastReceipt,
    (receipt) => {
      if (receipt) emit('change', receipt);
    }
  );
  onBeforeUnmount(() => session.dispose());
  defineExpose({ refresh, retry });
</script>

<style scoped>
  .document-comments {
    border: 1px solid #d8dce3;
    border-radius: 8px;
    padding: 16px;
    color: inherit;
  }
  .document-comments__header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
  }
  .document-comments__header h3 {
    margin: 0;
  }
  .document-comments__hint {
    color: #596273;
    font-size: 0.875rem;
  }
  .document-comments__notice {
    padding: 12px;
    border: 1px solid #d6b66a;
    border-radius: 4px;
  }
  .document-comments__field {
    display: grid;
    gap: 6px;
    margin: 12px 0;
  }
  .document-comments textarea {
    box-sizing: border-box;
    width: 100%;
    padding: 10px;
    border: 1px solid #abb3c1;
    border-radius: 4px;
    font: inherit;
    line-height: 1.6;
  }
  .document-comments button {
    cursor: pointer;
    margin: 4px 8px 4px 0;
    padding: 7px 10px;
    color: inherit;
    border: 1px solid #abb3c1;
    border-radius: 4px;
    background: transparent;
  }
  .document-comments button:disabled {
    cursor: default;
    opacity: 0.55;
  }
  .document-comments button:focus-visible,
  .document-comments textarea:focus-visible {
    outline: 2px solid #3467c9;
    outline-offset: 2px;
  }
  .document-comments blockquote {
    margin: 12px 0;
    border-left: 3px solid #8ca7d6;
    padding: 8px 12px;
    white-space: pre-wrap;
    overflow-wrap: anywhere;
  }
  .document-comments__workspace {
    display: grid;
    grid-template-columns: minmax(220px, 1fr) minmax(280px, 2fr);
    gap: 24px;
    margin-top: 20px;
  }
  .document-comments__threads {
    list-style: none;
    margin: 0;
    padding: 0;
  }
  .document-comments__threads button {
    display: grid;
    gap: 8px;
    width: 100%;
    text-align: left;
  }
  .document-comments__threads button[aria-pressed='true'] {
    border-color: #3467c9;
  }
  .document-comments__messages {
    padding-left: 24px;
  }
  .document-comments__quote {
    white-space: pre-wrap;
    overflow-wrap: anywhere;
  }
  @media (max-width: 720px) {
    .document-comments__workspace {
      grid-template-columns: 1fr;
    }
  }
</style>
