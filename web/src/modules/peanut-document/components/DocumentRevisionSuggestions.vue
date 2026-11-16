<template>
  <section
    class="document-suggestions"
    aria-label="固定版本修改建议与真实变更来源"
  >
    <header class="document-suggestions__header">
      <h3>修改建议与变更来源</h3>
      <el-button
        :disabled="session.loading || sending || deciding"
        @click="refresh"
        >刷新建议</el-button
      >
    </header>
    <p
      >候选在独立副本中编辑。接受整项建议会形成新的文档修订；稿件绑定与审批仍须按业务流程办理。</p
    >
    <p v-if="session.loading || relatedLoading" role="status"
      >正在核对固定正文与当前权限…</p
    >
    <el-alert
      v-if="localError || session.error"
      :title="localError || session.error || ''"
      type="error"
      :closable="false"
    />
    <div v-if="session.pending" role="status">
      <p
        >{{
          sending
            ? '正在提交原请求'
            : session.pending.uncertain
            ? '原请求结果尚未确认'
            : '原请求已被拒绝'
        }}
        · {{ session.pending.command.input.request_id }}</p
      >
      <p>保留原版本、候选和参数；核对时继续同一请求。</p>
      <el-button :disabled="sending" @click="perform(() => session.retry())"
        >核对并继续原请求</el-button
      >
      <el-button
        v-if="
          session.pending.state === 'rejected' && !session.pending.uncertain
        "
        @click="restart"
        >重新编辑</el-button
      >
    </div>
    <p v-if="lastAccepted" role="status">
      已生成文档修订 #{{ lastAccepted.result_revision_id }} · 水位
      {{ lastAccepted.result_state_seq }}。
      此回执不代表稿件已绑定该修订或已通过审批，请通过现有“保存固定正文修订”操作更新稿件绑定。
    </p>
    <template v-if="session.source && baseline">
      <p
        >基于固定版本 v{{ session.source.revision_no }} · 修订 #{{
          session.source.revision_id
        }}</p
      >
      <p v-if="!session.canWrite"
        >当前仅可阅读；提出与决定建议需要业务编辑权限。</p
      >
      <p v-else-if="props.readOnly"
        >正文操作尚未完成，建议暂只读；已发起的原请求仍可继续核对。</p
      >
      <template v-if="session.canWrite && !composing">
        <el-button
          :disabled="
            !!session.pending ||
            deciding ||
            session.loading ||
            session.loadingThread ||
            props.readOnly
          "
          @click="beginCandidate"
          >提出整篇修改建议</el-button
        >
      </template>
      <section v-if="composing && candidate" aria-label="独立候选编辑">
        <h4>独立候选</h4>
        <p
          >使用原生编辑器修改文字、格式和链接，保留已有素材引用。此处不会改写原共享正文。</p
        >
        <p v-if="session.source.text_length === 0"
          >固定正文为空，本建议以整份空正文为依据。</p
        >
        <label v-else class="document-suggestions__field">
          <span>从固定版本文字中选择本建议的引用（最多 4096 字）</span>
          <textarea
            :value="session.source.text"
            readonly
            rows="5"
            @select="selectQuote"
            @mouseup="selectQuote"
            @keyup="selectQuote"
          />
        </label>
        <blockquote v-if="anchor">{{
          anchor.quote || '整份空正文'
        }}</blockquote>
        <p v-else>请先选取一段引用，再提交整篇候选。</p>
        <RichTextEditor
          :key="candidateKey"
          v-model="candidate"
          :context-key="candidateKey"
          :read-only="
            !!session.pending ||
            reviewing ||
            deciding ||
            !session.canWrite ||
            props.readOnly
          "
          :callbacks="resourceCallbacks"
          :registry="registry"
          @ready="candidateReady = true"
          @error="candidateError"
        />
        <label class="document-suggestions__field">
          <span>修改说明</span>
          <el-input
            v-model="body"
            type="textarea"
            :rows="3"
            :disabled="!!session.pending || reviewing || props.readOnly"
          />
        </label>
        <p>{{ Array.from(body).length }} / 4000</p>
        <el-button
          v-if="!reviewing"
          :disabled="!candidateReady || !candidateChanged || !!session.pending"
          @click="reviewing = true"
          >审阅前后差异</el-button
        >
        <el-button
          v-else
          :disabled="!!session.pending"
          @click="reviewing = false"
          >返回编辑</el-button
        >
        <el-button
          :disabled="!!session.pending || deciding"
          @click="clearCandidate"
          >取消候选</el-button
        >
        <section v-if="reviewing" aria-label="提交前差异审阅">
          <h4>提交前审阅</h4>
          <p>整篇候选将作为一项建议提交；格式与素材请核对下方并列正文。</p>
          <div v-if="draftDiff" class="document-suggestions__diff">
            <span>{{ draftDiff.prefix }}</span
            ><del v-if="draftDiff.removed">{{ draftDiff.removed }}</del
            ><ins v-if="draftDiff.added">{{ draftDiff.added }}</ins
            ><span>{{ draftDiff.suffix }}</span>
          </div>
          <p v-if="draftDiff && !draftDiff.removed && !draftDiff.added"
            >文字相同，请核对格式或素材变化。</p
          >
          <div class="document-suggestions__comparison">
            <section
              ><h5>修改前 · 固定版本</h5
              ><RichTextViewer
                :model-value="baseline"
                :callbacks="resourceCallbacks"
                :registry="registry"
                :context-key="scopeKey + ':draft-before'"
            /></section>
            <section
              ><h5>修改后 · 本地候选</h5
              ><RichTextViewer
                :model-value="candidate"
                :callbacks="resourceCallbacks"
                :registry="registry"
                :context-key="candidateKey + ':preview'"
            /></section>
          </div>
          <el-button
            type="primary"
            :disabled="!canSubmit"
            @click="submitCandidate"
            >提交整项修改建议</el-button
          >
        </section>
      </section>

      <section aria-label="已持久保存的修改建议">
        <h4>此固定版本的建议</h4>
        <p v-if="suggestionThreads.length === 0"
          >当前已加载的线程中没有修改建议。</p
        >
        <ul class="document-suggestions__threads">
          <li v-for="thread in suggestionThreads" :key="thread.thread_id">
            <el-button
              :disabled="!!session.pending || deciding"
              :aria-pressed="session.active?.thread_id === thread.thread_id"
              @click="session.select(thread.thread_id)"
            >
              建议 #{{ thread.thread_id }} ·
              {{ decisionLabel(thread.suggestion?.decision) }} · 提出者 #{{
                thread.created_by_member_id
              }}
            </el-button>
          </li>
        </ul>
        <el-button
          v-if="session.nextThread !== null"
          :disabled="session.loading || !!session.pending"
          @click="session.load(true)"
          >加载更多线程中的建议</el-button
        >
        <p v-if="session.loadingThread" role="status">正在读取已保存候选…</p>
        <section
          v-if="activeSuggestion && persistedCandidate"
          aria-label="持久建议审阅"
        >
          <h4
            >建议 #{{ session.active?.thread_id }} ·
            {{ decisionLabel(activeSuggestion.decision) }}</h4
          >
          <p
            >提出者 #{{ session.active?.created_by_member_id }} ·
            {{ session.active?.created_at }}</p
          >
          <blockquote>{{ session.active?.quote || '整份空正文' }}</blockquote>
          <p
            v-for="message in session.messages.filter(
              (item) => item.body !== null
            )"
            :key="message.message_id"
            >成员 #{{ message.actor_member_id }}：{{ message.body }}</p
          >
          <p v-if="activeSuggestion.decided_by_member_id"
            >决定者 #{{ activeSuggestion.decided_by_member_id }} ·
            {{ activeSuggestion.decided_at }}</p
          >
          <p v-if="activeSuggestion.result_revision_id"
            >接受结果：文档修订 #{{ activeSuggestion.result_revision_id }} ·
            水位
            {{
              activeSuggestion.result_state_seq
            }}。业务绑定与审批请以稿件当前记录为准。</p
          >
          <div v-if="persistedDiff" class="document-suggestions__diff"
            ><span>{{ persistedDiff.prefix }}</span
            ><del v-if="persistedDiff.removed">{{ persistedDiff.removed }}</del
            ><ins v-if="persistedDiff.added">{{ persistedDiff.added }}</ins
            ><span>{{ persistedDiff.suffix }}</span></div
          >
          <div class="document-suggestions__comparison">
            <section
              ><h5>修改前 · 固定版本</h5
              ><RichTextViewer
                :model-value="baseline"
                :callbacks="resourceCallbacks"
                :registry="registry"
                :context-key="scopeKey + ':before'"
            /></section>
            <section
              ><h5>修改后 · 已保存候选</h5
              ><RichTextViewer
                :model-value="persistedCandidate"
                :callbacks="resourceCallbacks"
                :registry="registry"
                :context-key="
                  scopeKey + ':candidate:' + session.active?.thread_id
                "
            /></section>
          </div>
          <p
            >文字差异只辅助定位；格式与素材以上方完整正文为准。接受前会核对当前文档水位；基线冲突时不会覆盖他人的后续修改。</p
          >
          <template
            v-if="session.canWrite && activeSuggestion.decision === 'pending'"
          >
            <el-button
              type="primary"
              :disabled="
                !!session.pending || deciding || composing || props.readOnly
              "
              :loading="deciding"
              @click="decide('accept')"
              >接受整项建议并生成修订</el-button
            >
            <el-button
              :disabled="
                !!session.pending || deciding || composing || props.readOnly
              "
              @click="decide('reject')"
              >拒绝整项建议</el-button
            >
          </template>
        </section>
        <el-alert
          v-else-if="activeSuggestion"
          title="已保存候选无法解析，不能办理接受；请刷新核对。"
          type="error"
          :closable="false"
        />
      </section>

      <section aria-label="正文变更真实来源">
        <h4>正文变更真实来源</h4>
        <p
          >记录实际提交账号和办理来源，不把在线参与者当作逐字作者。接入前的旧历史没有来源记录时，作者未知，不作追溯归因。</p
        >
        <p v-if="changesLoading" role="status">正在读取变更记录…</p>
        <p v-if="!changesLoading && changes.length === 0"
          >暂无可查询的持久变更记录。</p
        >
        <ol class="document-suggestions__changes">
          <li v-for="change in changes" :key="change.change_id">
            <p
              >{{ change.source_label }} ·
              {{
                change.actor_member_id === null
                  ? '提交账号未知'
                  : '实际提交成员 #' + change.actor_member_id
              }}
              · 水位 {{ change.state_seq }} · {{ change.created_at }}</p
            >
            <details
              ><summary>查看此笔持久变更的前后文字</summary>
              <p>操作 {{ change.operation_id }} · {{ change.kind }}</p>
              <div class="document-suggestions__comparison"
                ><section
                  ><h5>修改前</h5><pre>{{ change.before_text }}</pre></section
                ><section
                  ><h5>修改后</h5><pre>{{ change.after_text }}</pre>
                </section></div
              >
            </details>
          </li>
        </ol>
        <el-button
          v-if="nextChange !== null"
          :disabled="changesLoading"
          @click="loadChanges(true)"
          >更早变更记录</el-button
        >
      </section>
    </template>
  </section>
</template>

<script setup lang="ts">
  import {
    computed,
    onBeforeUnmount,
    ref,
    shallowReactive,
    shallowRef,
    watch,
  } from 'vue';
  import { RichTextEditor } from '@/modules/peanut-rich-text/src/editor';
  import { RichTextViewer } from '@/modules/peanut-rich-text/src/viewer';
  import {
    parseRichTextDocument,
    type RichTextDocumentValue,
  } from '@/modules/peanut-rich-text/src/document';
  import { createLockedContentToolRegistry } from '@/modules/peanut-content-tools/src';
  import type { SurfaceCallbacks } from '@/modules/peanut-content-tools/src';
  import { getDocumentDetail } from '../api';
  import type { DocumentOwner } from '../src/contracts';
  import type {
    DocumentCommentAnchor,
    DocumentCommentReceipt,
  } from '../src/comment-contracts';
  import { commentAnchor, RevisionCommentSession } from '../src/comments';
  import {
    documentCommentRequest,
    documentCommentTransport,
  } from '../src/comment-api';
  import {
    parseDocumentChanges,
    suggestionText,
    suggestionTextChange,
    type DocumentChange,
  } from '../src/suggestions';

  const props = defineProps<{
    documentId: number;
    revisionId: number;
    contextKey: string;
    owner?: DocumentOwner;
    callbacks?: SurfaceCallbacks;
    readOnly?: boolean;
  }>();
  const emit = defineEmits<{
    (event: 'changed', receipt: DocumentCommentReceipt): void;
    (event: 'error', code: string): void;
  }>();
  const session = shallowReactive(
    new RevisionCommentSession(documentCommentTransport)
  );
  const registry = createLockedContentToolRegistry();
  // Only resource access crosses into this isolated editor; never register the
  // original document's text appender or collaboration/autosave binding.
  const resourceCallbacks = computed<SurfaceCallbacks>(() => ({
    pickMedia: props.callbacks?.pickMedia,
    resolveMedia: props.callbacks?.resolveMedia,
    resolvePreview: props.callbacks?.resolvePreview,
    downloadOriginal: props.callbacks?.downloadOriginal,
  }));
  const baseline = shallowRef<RichTextDocumentValue>();
  const candidate = shallowRef<RichTextDocumentValue>();
  const body = ref('');
  const anchor = ref<DocumentCommentAnchor>();
  const composing = ref(false);
  const reviewing = ref(false);
  const candidateReady = ref(false);
  const candidateGeneration = ref(0);
  const deciding = ref(false);
  const localError = ref('');
  const relatedLoading = ref(false);
  const changesLoading = ref(false);
  const changes = shallowRef<DocumentChange[]>([]);
  const nextChange = ref<number | null>(null);
  const lastAccepted = shallowRef<DocumentCommentReceipt>();
  let epoch = 0;
  let disposed = false;
  let relatedRequest: AbortController | undefined;
  let changesRequest: AbortController | undefined;
  let decisionRequest: AbortController | undefined;
  const scopeKey = computed(() =>
    JSON.stringify([
      props.contextKey,
      props.documentId,
      props.revisionId,
      props.owner,
    ])
  );
  const candidateKey = computed(
    () => scopeKey.value + ':local-candidate:' + candidateGeneration.value
  );
  const sending = computed(() => session.pending?.state === 'sending');
  const suggestionThreads = computed(() =>
    session.threads.filter((item) => item.suggestion)
  );
  const activeSuggestion = computed(() => session.active?.suggestion);
  const persistedCandidate = computed(() => {
    if (!activeSuggestion.value) return undefined;
    try {
      return parseRichTextDocument(activeSuggestion.value.candidate_content);
    } catch {
      return undefined;
    }
  });
  const candidateChanged = computed(
    () =>
      !!baseline.value &&
      !!candidate.value &&
      JSON.stringify(candidate.value) !== JSON.stringify(baseline.value)
  );
  const canSubmit = computed(
    () =>
      session.canWrite &&
      !props.readOnly &&
      !session.pending &&
      !deciding.value &&
      !session.loading &&
      !session.loadingThread &&
      candidateReady.value &&
      candidateChanged.value &&
      reviewing.value &&
      !!anchor.value &&
      !!body.value.trim() &&
      Array.from(body.value).length <= 4000
  );
  const draftDiff = computed(() =>
    baseline.value && candidate.value
      ? suggestionTextChange(
          suggestionText(baseline.value),
          suggestionText(candidate.value)
        )
      : undefined
  );
  const persistedDiff = computed(() =>
    baseline.value && persistedCandidate.value
      ? suggestionTextChange(
          suggestionText(baseline.value),
          suggestionText(persistedCandidate.value)
        )
      : undefined
  );
  const decisionLabel = (decision?: string) =>
    decision === 'accepted'
      ? '已接受'
      : decision === 'rejected'
      ? '已拒绝'
      : '待决定';

  function clearCandidate() {
    composing.value = false;
    reviewing.value = false;
    candidate.value = undefined;
    body.value = '';
    anchor.value = undefined;
    candidateReady.value = false;
  }
  function report(cause: unknown) {
    localError.value =
      cause instanceof Error
        ? cause.message
        : 'DOCUMENT_SUGGESTION_OPERATION_FAILED';
    emit('error', localError.value);
  }
  async function perform(operation: () => Promise<void>) {
    const current = epoch;
    localError.value = '';
    try {
      await operation();
    } catch (cause) {
      if (current === epoch && !disposed) report(cause);
    }
  }
  function candidateError(code: string) {
    if (!['RICH_TEXT_LINK_INVALID', 'RICH_TEXT_MEDIA_INVALID'].includes(code))
      candidateReady.value = false;
    report(new Error(code));
  }
  function beginCandidate() {
    if (
      !baseline.value ||
      !session.canWrite ||
      session.pending ||
      props.readOnly
    )
      return;
    candidate.value = JSON.parse(JSON.stringify(baseline.value));
    candidateGeneration.value += 1;
    candidateReady.value = false;
    composing.value = true;
    reviewing.value = false;
    anchor.value =
      session.source?.text_length === 0
        ? { start_offset: 0, end_offset: 0, quote: '' }
        : undefined;
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
        input.value,
        input.selectionStart,
        input.selectionEnd
      );
    } catch (cause) {
      anchor.value = undefined;
      report(cause);
    }
  }
  const submitCandidate = () =>
    perform(async () => {
      if (!canSubmit.value || !candidate.value || !anchor.value) return;
      await session.suggest(
        anchor.value,
        body.value,
        parseRichTextDocument(candidate.value)
      );
      if (!session.pending) clearCandidate();
    });
  const refresh = () => perform(() => session.load());
  function restart() {
    session.dismissRejected();
    void refresh();
  }
  async function decide(action: 'accept' | 'reject') {
    if (
      deciding.value ||
      session.pending ||
      !session.canWrite ||
      props.readOnly ||
      activeSuggestion.value?.decision !== 'pending'
    )
      return;
    const current = epoch;
    const threadId = session.active?.thread_id;
    const request = new AbortController();
    decisionRequest = request;
    deciding.value = true;
    await perform(async () => {
      let stateSeq: number | undefined;
      if (action === 'accept') {
        const { data } = await getDocumentDetail(
          props.documentId,
          undefined,
          request.signal
        );
        if (disposed || current !== epoch || request.signal.aborted) return;
        if (
          data.document_id !== props.documentId ||
          !Number.isSafeInteger(data.state_seq) ||
          data.state_seq < 0 ||
          !sameOwner(data.owner)
        )
          throw new Error('DOCUMENT_SUGGESTION_CURRENT_STATE_INVALID');
        stateSeq = data.state_seq;
      }
      if (
        disposed ||
        current !== epoch ||
        request.signal.aborted ||
        session.active?.thread_id !== threadId
      )
        return;
      await session.decideSuggestion(action, stateSeq);
    });
    if (current === epoch) deciding.value = false;
    if (decisionRequest === request) decisionRequest = undefined;
  }
  function sameOwner(owner: DocumentOwner | null | undefined) {
    const expected = session.source?.owner;
    return (
      !!expected &&
      !!owner &&
      owner.module_key === expected.module_key &&
      owner.object_type === expected.object_type &&
      owner.object_id === expected.object_id &&
      owner.slot === expected.slot
    );
  }
  async function loadChanges(more = false) {
    if (!session.source || (more && nextChange.value === null)) return;
    const current = epoch;
    const before = more ? nextChange.value ?? undefined : undefined;
    const request = new AbortController();
    changesRequest?.abort();
    changesRequest = request;
    changesLoading.value = true;
    try {
      const page = parseDocumentChanges(
        await documentCommentRequest(
          {
            method: 'GET',
            url: '/adminapi/peanut.document.changes',
            params: {
              document_id: props.documentId,
              limit: 20,
              before_change_id: before,
            },
          },
          request.signal
        ),
        props.documentId
      );
      if (
        disposed ||
        current !== epoch ||
        request.signal.aborted ||
        changesRequest !== request
      )
        return;
      if (
        more &&
        page.items.some(
          (item) =>
            item.change_id >= (before ?? Infinity) ||
            changes.value.some(
              (existing) => existing.change_id === item.change_id
            )
        )
      )
        throw new Error('DOCUMENT_CHANGES_RESPONSE_INVALID');
      changes.value = more ? [...changes.value, ...page.items] : page.items;
      nextChange.value = page.next_before_change_id;
    } catch (cause) {
      if (current === epoch && !request.signal.aborted && !disposed) {
        clearReadContent();
        session.setScope({
          documentId: props.documentId,
          revisionId: props.revisionId,
          contextKey: props.contextKey,
          owner: props.owner,
        });
        report(cause);
      }
    } finally {
      if (changesRequest === request) changesLoading.value = false;
    }
  }
  function clearReadContent() {
    changesRequest?.abort();
    changesRequest = undefined;
    changesLoading.value = false;
    baseline.value = undefined;
    changes.value = [];
    nextChange.value = null;
    clearCandidate();
  }
  watch(
    scopeKey,
    () => {
      epoch += 1;
      relatedRequest?.abort();
      changesRequest?.abort();
      decisionRequest?.abort();
      relatedLoading.value = false;
      changesLoading.value = false;
      deciding.value = false;
      clearReadContent();
      lastAccepted.value = undefined;
      localError.value = '';
      try {
        session.setScope({
          documentId: props.documentId,
          revisionId: props.revisionId,
          contextKey: props.contextKey,
          owner: props.owner,
        });
        void session.load();
      } catch (cause) {
        report(cause);
      }
    },
    { immediate: true, flush: 'sync' }
  );
  watch(
    () => session.source,
    async (source) => {
      relatedRequest?.abort();
      if (!source) {
        clearReadContent();
        return;
      }
      const current = epoch;
      const request = new AbortController();
      relatedRequest = request;
      relatedLoading.value = true;
      try {
        const { data } = await getDocumentDetail(
          source.document_id,
          source.revision_id,
          request.signal
        );
        if (disposed || current !== epoch || request.signal.aborted) return;
        if (
          data.document_id !== source.document_id ||
          data.revision_id !== source.revision_id ||
          data.confirmed !== true ||
          data.digest !== source.version_digest ||
          data.source_state_seq !== source.source_state_seq ||
          data.content_kind !== 'rich_text' ||
          !sameOwner(data.owner)
        )
          throw new Error('DOCUMENT_SUGGESTION_BASELINE_INVALID');
        baseline.value = parseRichTextDocument(data.structured_content);
        await loadChanges();
      } catch (cause) {
        if (current === epoch && !request.signal.aborted && !disposed) {
          clearReadContent();
          session.setScope({
            documentId: props.documentId,
            revisionId: props.revisionId,
            contextKey: props.contextKey,
            owner: props.owner,
          });
          report(cause);
        }
      } finally {
        if (relatedRequest === request) relatedLoading.value = false;
      }
    },
    { flush: 'sync' }
  );
  watch(
    () => session.canWrite,
    (writable) => {
      if (!writable) clearCandidate();
    },
    { flush: 'sync' }
  );
  watch(
    () => session.lastReceipt,
    (receipt) => {
      if (!receipt) return;
      if (receipt.action === 'accept') lastAccepted.value = receipt;
      emit('changed', receipt);
    }
  );
  watch(
    () => session.error,
    (code) => {
      if (code) emit('error', code);
    }
  );
  onBeforeUnmount(() => {
    disposed = true;
    epoch += 1;
    relatedRequest?.abort();
    changesRequest?.abort();
    decisionRequest?.abort();
    session.dispose();
  });
  defineExpose({ refresh });
</script>

<style scoped>
  .document-suggestions {
    padding: 16px;
    border: 1px solid var(--el-border-color-lighter);
    border-radius: 8px;
    min-width: 0;
    color: var(--el-text-color-regular);
  }
  .document-suggestions__header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
  }
  .document-suggestions h3 {
    margin: 0;
  }
  .document-suggestions h4 {
    margin: 20px 0 12px;
  }
  .document-suggestions h5 {
    margin: 0 0 12px;
    font-size: inherit;
  }
  .document-suggestions__field {
    display: grid;
    gap: 8px;
    margin: 16px 0;
  }
  .document-suggestions textarea {
    box-sizing: border-box;
    width: 100%;
    padding: 10px;
    color: inherit;
    background: var(--el-bg-color);
    border: 1px solid var(--el-border-color);
    border-radius: 4px;
    font: inherit;
  }
  .document-suggestions__comparison {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 16px;
    margin: 16px 0;
  }
  .document-suggestions__comparison > section {
    min-width: 0;
    padding: 12px;
    border: 1px solid var(--el-border-color-lighter);
    border-radius: 4px;
  }
  .document-suggestions blockquote,
  .document-suggestions pre,
  .document-suggestions__diff {
    white-space: pre-wrap;
    overflow-wrap: anywhere;
  }
  .document-suggestions blockquote {
    margin: 12px 0;
    padding: 8px 12px;
    border-left: 3px solid var(--el-color-primary);
  }
  .document-suggestions pre {
    max-height: 320px;
    overflow: auto;
    font: inherit;
  }
  .document-suggestions__diff {
    padding: 12px;
    margin: 12px 0;
    background: var(--el-fill-color-light);
  }
  .document-suggestions__diff del {
    background: var(--el-color-danger-light-9);
    color: var(--el-color-danger);
  }
  .document-suggestions__diff ins {
    background: var(--el-color-success-light-9);
    color: var(--el-color-success);
  }
  .document-suggestions__threads {
    list-style: none;
    margin: 12px 0;
    padding: 0;
  }
  .document-suggestions__threads li {
    margin: 8px 0;
  }
  .document-suggestions__threads .el-button {
    white-space: normal;
    height: auto;
    text-align: left;
  }
  .document-suggestions__changes {
    padding-left: 24px;
  }
  .document-suggestions__changes > li {
    margin: 16px 0;
  }
  @media (max-width: 720px) {
    .document-suggestions__comparison {
      grid-template-columns: minmax(0, 1fr);
    }
  }
</style>
