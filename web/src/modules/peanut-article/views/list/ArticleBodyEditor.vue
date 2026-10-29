<template>
  <section :aria-busy="busy">
    <p role="status">{{ message }}</p>
    <p v-if="saveState" role="status"
      >正文草稿：{{ saveState }}（{{ saveStateText[saveState] }}）</p
    >
    <el-button
      v-if="canEnableCollaboration"
      :disabled="busy || saveState !== 'saved'"
      @click="enableCollaboration"
    >
      开启多人协作
    </el-button>
    <fieldset
      v-if="editor && mountedEditor"
      class="article-document-editor"
      :disabled="busy || enablingCollaboration || !!targetAttempt || !!attempt"
    >
      <DocumentEditor
        ref="documentEditor"
        :document-id="editor.document_id"
        :owner="editor.owner"
        :context-key="context"
        :metadata-resolver="resolveMetadata"
        :resolve-transport="resolveTransport"
        :business-actions="documentBusinessActions"
        @load="onDocumentLoad"
        @save-state="saveState = $event"
        @operation-state="onDocumentOperation"
        @error="onEditorError"
      />
    </fieldset>
    <ArticleBodyHistory
      v-if="editor"
      :article-id="articleId"
      :document-id="editor.document_id"
      :context-key="context"
    />
    <el-button
      v-if="collaborationBaseline && !mountedEditor"
      :disabled="busy"
      @click="open"
    >
      观察多人协作状态
    </el-button>
    <el-button
      v-if="collaborationConflict || (targetAttempt && !attempt)"
      :disabled="busy"
      @click="discardMetadataAndReload"
    >
      {{
        targetAttempt
          ? '取消原保存目标，放弃本地未确认内容并重新加载'
          : '放弃未保存的资讯元数据并重新加载'
      }}
    </el-button>
    <el-button v-if="!editor && !busy && !collaborationBaseline" @click="open"
      >重新接入正文</el-button
    >
    <template v-if="attempt && phase !== 'completed' && phase !== 'conflicted'">
      <el-button :disabled="busy" @click="recover">查询原保存请求</el-button>
      <el-button :disabled="busy" @click="submitOriginal"
        >继续原保存请求</el-button
      >
    </template>
    <el-button
      v-if="attempt && phase === 'conflicted'"
      :disabled="busy"
      @click="reloadAfterConflict"
      >放弃未保存的资讯元数据并重新加载</el-button
    >
    <el-button
      v-if="targetAttempt && !attempt"
      :disabled="busy"
      @click="observeSaveTarget"
    >
      查询原保存目标并继续
    </el-button>
    <el-button
      v-if="
        !collaborationBaseline &&
        (phase === 'completed' ||
          ((reloadRequired || saveState === 'error') && !attempt))
      "
      :disabled="busy"
      @click="open"
    >
      重新加载资讯元数据和编辑授权
    </el-button>
    <p v-if="saved" role="status">
      已保存业务版本 {{ saved.binding.revision_id }}，正文水位
      {{ saved.binding.source_state_seq }}。资讯仍未发布。
    </p>
  </section>
</template>

<script setup lang="ts">
  import {
    computed,
    nextTick,
    onBeforeUnmount,
    ref,
    shallowRef,
    watch,
  } from 'vue';
  import { useUserStore } from '@/store';
  import { getSessionSnapshot } from '@/utils/auth';
  import randomUuid from '@/utils/random-uuid';
  import DocumentEditor from '@/modules/peanut-document/components/DocumentEditor.vue';
  import parseCapabilities from '@/modules/peanut-content-tools/src/capabilities';
  import {
    queryDocumentSaveTarget,
    cancelDocumentSaveTarget,
  } from '@/modules/peanut-document/api';
  import { parseSaveTarget } from '@/modules/peanut-document/src/operations';
  import type {
    DocumentDetail,
    DocumentCapabilities,
    DocumentSaveState,
    AuthorizedDocumentTransport,
    DocumentSurfaceOptions,
    DocumentOperationFeedback,
  } from '@/modules/peanut-document/src/contracts';
  import {
    ensureArticleBody,
    getArticleBodyEditor,
    getArticleBodyTransport,
    saveArticleBody,
    getArticleBodyOperation,
  } from '../../api';

  import ArticleBodyHistory from './ArticleBodyHistory.vue';
  import type {
    ArticleBodyEditor,
    ArticleBodyOperation,
    ArticleBodySaveInput,
    ArticleBodySaved,
  } from '../../api';

  type ArticleMetadataInput = Omit<
    ArticleBodySaveInput,
    | 'id'
    | 'document_id'
    | 'expected_state_seq'
    | 'owner_revision'
    | 'request_id'
  >;

  const props = defineProps<{ articleId: number; contextKey: string }>();
  const emit = defineEmits<{
    (event: 'saved', value: ArticleBodySaved): void;
    (event: 'locked', value: boolean): void;
    (event: 'metadataReady', value: boolean): void;
    (event: 'metadata', value: ArticleBodyEditor): void;
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
  const editor = shallowRef<ArticleBodyEditor>();
  const ownerBaseline = ref<string>();
  const reloadRequired = ref(false);
  const detail = shallowRef<DocumentDetail>();
  const saveState = ref<DocumentSaveState>('idle');
  const documentEditor = ref<InstanceType<typeof DocumentEditor>>();
  const documentBusinessActions: NonNullable<
    DocumentSurfaceOptions['businessActions']
  > = ['version.restore'];
  const documentOperation = shallowRef<DocumentOperationFeedback>();
  const documentOperationLocked = computed(
    () =>
      !!documentOperation.value &&
      (documentOperation.value.state !== 'completed' ||
        !documentOperation.value.released)
  );
  const saveStateText: Record<DocumentSaveState, string> = {
    idle: '有修改尚未保存',
    syncing: '正在连接并同步正文',
    persisting: '正在写入数据库',
    saved: '数据库已确认保存',
    error: '保存或连接出错，结果未确认',
    readonly: '只读，不能编辑',
  };
  const mountedEditor = ref(false);
  const enablingCollaboration = ref(false);
  const collaborationBaseline = shallowRef<{
    documentId: number;
    ownerRevision: string;
  }>();
  const collaborationUnknown = ref(false);
  const collaborationConflict = ref(false);
  const busy = ref(false);
  const message = ref('');
  const attempt = shallowRef<ArticleBodySaveInput>();
  const targetAttempt = shallowRef<{
    requestId: string;
    documentId: number;
    ownerRevision: string;
    collaborative: boolean;
    metadata: ArticleMetadataInput;
  }>();
  const saved = shallowRef<ArticleBodySaved>();
  const phase = ref<
    'idle' | 'pending' | 'unknown' | 'completed' | 'conflicted'
  >('idle');
  const canEnableCollaboration = computed(
    () =>
      mountedEditor.value &&
      !enablingCollaboration.value &&
      !documentOperationLocked.value &&
      !attempt.value &&
      !reloadRequired.value &&
      editor.value?.can_edit === true &&
      editor.value.content_kind === 'rich_text' &&
      editor.value.collaboration_enabled === false
  );
  watch(
    [attempt, phase, enablingCollaboration, busy, documentOperationLocked],
    () =>
      emit(
        'locked',
        busy.value ||
          documentOperationLocked.value ||
          enablingCollaboration.value ||
          (!!attempt.value && phase.value !== 'completed')
      ),
    { flush: 'sync' }
  );
  let disposed = false;
  let epoch = 0;
  let request: AbortController | undefined;
  let initialMetadata:
    | {
        data: ArticleBodyEditor;
        controller: AbortController;
        key: string;
        token: number;
      }
    | undefined;
  let ensureId = randomUuid();
  const current = (controller: AbortController, key: string, token: number) =>
    !disposed &&
    !controller.signal.aborted &&
    key === context.value &&
    token === epoch;
  const start = () => {
    initialMetadata = undefined;
    request?.abort();
    const controller = new AbortController();
    request = controller;
    busy.value = true;
    epoch += 1;
    return { controller, key: context.value, token: epoch };
  };
  const validEditor = (value: ArticleBodyEditor) => {
    const metadata = value.article_metadata;
    if (
      value.article_id !== props.articleId ||
      !Number.isSafeInteger(value.document_id) ||
      value.document_id < 1 ||
      value.owner.module_key !== 'peanut.article' ||
      value.owner.object_type !== 'article' ||
      value.owner.object_id !== String(props.articleId) ||
      value.owner.slot !== 'body' ||
      !/^[a-f0-9]{64}$/.test(value.owner_revision) ||
      !/^[a-f0-9]{64}$/.test(value.authorization_revision) ||
      value.can_edit !== true ||
      value.can_download_original !== false ||
      typeof value.collaboration_enabled !== 'boolean' ||
      !Number.isSafeInteger(value.state_seq) ||
      value.state_seq < 0 ||
      value.status !== 'active' ||
      value.content_kind !== 'rich_text' ||
      !metadata ||
      !Number.isSafeInteger(metadata.cid) ||
      metadata.cid < 1 ||
      !Number.isSafeInteger(metadata.click_virtual) ||
      metadata.click_virtual < 0 ||
      !Number.isSafeInteger(metadata.sort) ||
      metadata.sort < 0 ||
      ![0, 1].includes(metadata.is_show) ||
      !/^(|file_[0-9a-f]{32})$/.test(metadata.image) ||
      ['title', 'desc', 'abstract', 'image', 'image_url', 'author'].some(
        (field) => typeof metadata[field as keyof typeof metadata] !== 'string'
      )
    )
      throw new Error('ARTICLE_EDITOR_RESPONSE_INVALID');
    parseCapabilities(value.capabilities);
    return value;
  };
  const verifyBaseline = (value: ArticleBodyEditor) => {
    if (!ownerBaseline.value || value.owner_revision !== ownerBaseline.value) {
      reloadRequired.value = true;
      emit('metadataReady', false);
      throw new Error('ARTICLE_METADATA_BASELINE_CHANGED');
    }
  };
  const resolveMetadata = async (signal: AbortSignal) => {
    const key = context.value;
    const token = epoch;
    // Hand the current load response to its first mount only, never a later authorization.
    const initial = initialMetadata;
    initialMetadata = undefined;
    if (disposed || signal.aborted) throw new Error('ARTICLE_CONTEXT_CHANGED');
    const data =
      initial &&
      initial.key === key &&
      current(initial.controller, initial.key, initial.token) &&
      editor.value === initial.data
        ? initial.data
        : (await getArticleBodyEditor(props.articleId, signal)).data;
    if (disposed || signal.aborted || key !== context.value || token !== epoch)
      throw new Error('ARTICLE_CONTEXT_CHANGED');
    validEditor(data);
    verifyBaseline(data);
    if (editor.value && editor.value.document_id !== data.document_id)
      throw new Error('ARTICLE_DOCUMENT_BINDING_CHANGED');
    editor.value = data;
    return {
      detail: data,
      capabilities: {
        document_id: data.document_id,
        can_edit: data.can_edit,
        can_download_original: data.can_download_original,
        collaboration_enabled: data.collaboration_enabled,
        capabilities: data.capabilities,
      },
    };
  };
  const resolveTransport = async (
    metadata: DocumentDetail,
    capabilities: DocumentCapabilities,
    signal: AbortSignal
  ): Promise<AuthorizedDocumentTransport> => {
    const key = context.value;
    const { data } = await getArticleBodyTransport(props.articleId, signal);
    if (disposed || signal.aborted || key !== context.value)
      throw new Error('ARTICLE_CONTEXT_CHANGED');
    const selected = parseCapabilities(capabilities.capabilities);
    const transportCapabilities = parseCapabilities(data.capabilities);
    if (
      data.article_id !== props.articleId ||
      data.document_id !== metadata.document_id ||
      data.owner.object_id !== String(props.articleId) ||
      data.owner.module_key !== 'peanut.article' ||
      data.owner.object_type !== 'article' ||
      data.owner.slot !== 'body' ||
      data.owner_revision !== ownerBaseline.value ||
      data.authorization_revision !== metadata.authorization_revision ||
      transportCapabilities.capabilityRevision !==
        selected.capabilityRevision ||
      data.mode !== 'write' ||
      data.protocol !== 2 ||
      !data.document_name ||
      !/^wss?:\/\/[^/?#@]+:[0-9]+\/collaboration$/.test(data.wss_url)
    )
      throw new Error('ARTICLE_TRANSPORT_INVALID');
    return {
      documentId: data.document_id,
      owner: data.owner,
      authorizationRevision: data.authorization_revision,
      capabilityRevision: selected.capabilityRevision,
      mode: data.mode,
      url: data.wss_url,
      documentName: data.document_name,
    };
  };
  const loadMetadata = async (discardMetadata: boolean) => {
    if (documentOperationLocked.value) return false;
    if (
      attempt.value &&
      phase.value !== 'completed' &&
      phase.value !== 'conflicted'
    )
      return false;
    if (
      targetAttempt.value &&
      !attempt.value &&
      targetAttempt.value.collaborative
    ) {
      message.value =
        '原保存目标尚未处理；本地内容与原请求编号已保留，请先查询原目标，不能通过重新加载另建请求。';
      return false;
    }
    const baseline = collaborationBaseline.value;
    const { controller, key, token } = start();
    emit('metadataReady', false);
    if (!baseline) ownerBaseline.value = undefined;
    mountedEditor.value = false;
    editor.value = undefined;
    detail.value = undefined;
    saveState.value = baseline ? 'readonly' : 'idle';
    try {
      // Once initialization was attempted, observation only reads the existing owner slot.
      if (!baseline)
        await ensureArticleBody(props.articleId, ensureId, controller.signal);
      const { data } = await getArticleBodyEditor(
        props.articleId,
        controller.signal
      );
      if (!current(controller, key, token)) return false;
      validEditor(data);
      if (baseline && data.document_id !== baseline.documentId)
        throw new Error('ARTICLE_DOCUMENT_BINDING_CHANGED');
      if (
        baseline &&
        data.owner_revision !== baseline.ownerRevision &&
        !discardMetadata
      ) {
        collaborationConflict.value = true;
        reloadRequired.value = true;
        message.value =
          '资讯元数据已改变；未保存的修改仍保留，正文保持关闭。请先检查修改，或明确放弃未保存的资讯元数据并重新加载。';
        return false;
      }
      collaborationConflict.value = false;
      if (baseline && discardMetadata) {
        collaborationBaseline.value = {
          documentId: data.document_id,
          ownerRevision: data.owner_revision,
        };
        ownerBaseline.value = data.owner_revision;
        emit('metadata', data);
      } else if (!baseline) {
        ownerBaseline.value = data.owner_revision;
        emit('metadata', data);
      }
      // A false flag cannot prove that an earlier timed-out POST will not commit later.
      if (collaborationUnknown.value && data.collaboration_enabled !== true) {
        reloadRequired.value = true;
        message.value =
          '开启多人协作的结果仍未知，正文保持关闭。只能继续观察状态，不会重复开启请求或恢复单人编辑。';
        return false;
      }
      if (data.collaboration_enabled) collaborationUnknown.value = false;
      editor.value = data;
      collaborationBaseline.value = undefined;
      reloadRequired.value = false;
      emit('metadataReady', true);
      attempt.value = undefined;
      targetAttempt.value = undefined;
      phase.value = 'idle';
      initialMetadata = { data, controller, key, token };
      mountedEditor.value = true;
      message.value = '正文自动保存为草稿；业务保存才会绑定不可变版本。';
      return true;
    } catch {
      if (current(controller, key, token)) {
        reloadRequired.value = !!baseline;
        message.value = baseline
          ? '多人协作及正文状态尚未确认，编辑保持关闭，请继续观察当前授权和正文状态。'
          : '正文接入未确认，请检查编辑权限、分类和正文状态后继续原接入请求。';
      }
      return false;
    } finally {
      if (request === controller) busy.value = false;
    }
  };
  const open = () => loadMetadata(false);
  const reloadAfterConflict = async () => {
    const input = attempt.value;
    if (busy.value || phase.value !== 'conflicted' || !input) return;
    // The rejected Article request is terminal; its Document version/target is retained.
    // A known owner slot makes this an authorized read, never ensure/create or cancel.
    collaborationBaseline.value = {
      documentId: Number(input.document_id),
      ownerRevision: input.owner_revision,
    };
    await loadMetadata(true);
  };
  const discardMetadataAndReload = async () => {
    if (
      busy.value ||
      attempt.value ||
      (!collaborationConflict.value && !targetAttempt.value)
    )
      return;
    const original = targetAttempt.value;
    if (original?.collaborative) {
      const { documentId } = original;
      const { controller, key, token } = start();
      try {
        const { data } = await cancelDocumentSaveTarget(
          documentId,
          original.requestId,
          controller.signal
        );
        if (
          !current(controller, key, token) ||
          targetAttempt.value !== original
        )
          return;
        if (
          !data ||
          typeof data !== 'object' ||
          !('document_id' in data) ||
          data.document_id !== documentId ||
          !('request_id' in data) ||
          data.request_id !== original.requestId ||
          !('phase' in data) ||
          data.phase !== 'canceled'
        )
          throw new Error('DOCUMENT_TARGET_CANCEL_UNCONFIRMED');
        targetAttempt.value = undefined;
      } catch {
        if (current(controller, key, token))
          message.value =
            '取消结果未确认，保留原目标和本地内容；不能重新加载另建请求。';
        return;
      } finally {
        if (request === controller) busy.value = false;
      }
    }
    if (original && !original.collaborative) targetAttempt.value = undefined;
    await loadMetadata(true);
  };
  const enableCollaboration = async () => {
    if (
      !canEnableCollaboration.value ||
      busy.value ||
      saveState.value !== 'saved' ||
      !ownerBaseline.value ||
      !editor.value
    )
      return;
    const { controller, key, token } = start();
    collaborationBaseline.value = {
      documentId: editor.value.document_id,
      ownerRevision: ownerBaseline.value,
    };
    enablingCollaboration.value = true;
    emit('metadataReady', false);
    mountedEditor.value = false;
    detail.value = undefined;
    saveState.value = 'readonly';
    reloadRequired.value = true;
    message.value = '正在开启多人协作，正文编辑已暂停。';
    try {
      // Finish unmounting/aborting the solo editor before the server can enable collaboration.
      await nextTick();
      if (!current(controller, key, token)) return;
      try {
        // This owner-authorized command initializes PHP state; only a fresh later transport is used.
        await getArticleBodyTransport(props.articleId, controller.signal);
      } catch {
        // A failed response does not establish whether the server committed the mode change.
        if (current(controller, key, token)) collaborationUnknown.value = true;
      }
      if (!current(controller, key, token)) return;
      const reload = open();
      const reloadController = request;
      const loaded = await reload;
      if (!reloadController || !current(reloadController, key, token + 1))
        return;
      if (!loaded) return;
      if (editor.value?.collaboration_enabled === true) {
        message.value = '当前正文已开启多人协作，正在取得新的编辑连接。';
      } else {
        message.value = '多人协作尚未确认开启，当前正文仍为单人模式。';
      }
    } finally {
      if (!disposed && key === context.value && epoch <= token + 1) {
        enablingCollaboration.value = false;
        if (request === controller) busy.value = false;
      }
    }
  };
  const accept = (value: ArticleBodySaved) => {
    const input = attempt.value;
    if (
      !input ||
      value.article_id !== props.articleId ||
      value.request_id !== input.request_id ||
      value.phase !== 'completed' ||
      value.published !== false ||
      value.binding.document_id !== input.document_id ||
      !Number.isSafeInteger(value.binding.revision_id) ||
      value.binding.revision_id < 1 ||
      Number(value.binding.source_state_seq) !==
        Number(input.expected_state_seq) ||
      !/^[a-f0-9]{64}$/.test(value.binding.snapshot_sha256)
    )
      throw new Error('ARTICLE_SAVE_RESPONSE_INVALID');
    saved.value = value;
    phase.value = 'completed';
    message.value =
      '数据库已确认正文业务绑定；旧编辑连接已关闭。重新编辑需取得新授权。';
    emit('saved', value);
  };
  const acceptOperation = (data: ArticleBodyOperation) => {
    const input = attempt.value;
    if (
      !input ||
      data.article_id !== props.articleId ||
      data.request_id !== input.request_id ||
      data.document_id !== input.document_id
    )
      throw new Error('ARTICLE_SAVE_RESPONSE_INVALID');
    if (data.phase === 'completed' && data.result) accept(data.result);
    else if (
      data.phase === 'conflicted' &&
      data.error_code === 'ARTICLE_BODY_SAVE_OWNER_CHANGED' &&
      data.continuation === null &&
      data.result === null
    ) {
      phase.value = 'conflicted';
      message.value =
        '业务保存已因资讯归属版本冲突被拒绝，未覆盖他人保存。未保存的资讯元数据仍保留；不能继续旧请求，可检查修改后明确放弃并重新加载。';
    } else if (
      data.phase === 'prepared' &&
      data.continuation === 'same_request_same_payload' &&
      data.error_code === null &&
      data.result === null
    ) {
      phase.value = 'pending';
      message.value =
        '原请求已准备，尚未确认业务提交；这不表示仍在执行。请保留相同请求和输入，查询或明确继续原请求。';
    } else throw new Error('ARTICLE_SAVE_RESPONSE_INVALID');
  };
  const submitOriginal = async () => {
    const input = attempt.value;
    if (
      !input ||
      busy.value ||
      phase.value === 'completed' ||
      phase.value === 'conflicted'
    )
      return;
    const { controller, key, token } = start();
    phase.value = 'pending';
    try {
      const { data } = await saveArticleBody(input, controller.signal);
      if (current(controller, key, token)) accept(data);
    } catch {
      if (current(controller, key, token)) {
        phase.value = 'unknown';
        message.value =
          '业务保存未确认。保留原请求与输入，可查询或继续原请求；不能显示已保存。';
        // Reconcile once by the original read-only query; never repeat the POST.
        try {
          const { data } = await getArticleBodyOperation(
            props.articleId,
            input.request_id,
            controller.signal
          );
          if (current(controller, key, token)) acceptOperation(data);
        } catch {
          // An unavailable status remains unknown, with the original input retained.
        }
      }
    } finally {
      if (request === controller) busy.value = false;
    }
  };
  const recover = async () => {
    const input = attempt.value;
    if (!input || busy.value) return;
    const { controller, key, token } = start();
    try {
      const { data } = await getArticleBodyOperation(
        props.articleId,
        input.request_id,
        controller.signal
      );
      if (!current(controller, key, token)) return;
      acceptOperation(data);
    } catch {
      if (current(controller, key, token)) {
        phase.value = 'unknown';
        message.value = '原业务保存状态仍未确认。';
      }
    } finally {
      if (request === controller) busy.value = false;
    }
  };
  const save = async (metadata: ArticleMetadataInput) => {
    const baseline = ownerBaseline.value;
    if (
      busy.value ||
      documentOperationLocked.value ||
      attempt.value ||
      (saveState.value !== 'saved' && saveState.value !== 'persisting') ||
      !editor.value ||
      !baseline ||
      reloadRequired.value ||
      !detail.value
    )
      return false;
    const { controller, key, token } = start();
    const requestId = targetAttempt.value?.requestId ?? randomUuid();
    try {
      targetAttempt.value ??= {
        requestId,
        documentId: editor.value.document_id,
        ownerRevision: baseline,
        collaborative: editor.value.collaboration_enabled,
        metadata: JSON.parse(JSON.stringify(metadata)) as ArticleMetadataInput,
      };
      if (
        targetAttempt.value.documentId !== editor.value.document_id ||
        targetAttempt.value.ownerRevision !== baseline ||
        targetAttempt.value.collaborative !== editor.value.collaboration_enabled
      )
        throw new Error('ARTICLE_SAVE_TARGET_BINDING_CHANGED');
      const target = editor.value.collaboration_enabled
        ? await documentEditor.value?.prepareSaveTarget(requestId)
        : undefined;
      if (editor.value.collaboration_enabled && !target)
        throw new Error('DOCUMENT_SAVE_TARGET_UNAVAILABLE');
      if (!current(controller, key, token)) return false;
      const { data } = await getArticleBodyEditor(
        props.articleId,
        controller.signal
      );
      if (!current(controller, key, token)) return false;
      validEditor(data);
      verifyBaseline(data);
      if (
        data.document_id !== detail.value.document_id ||
        (!target &&
          (data.state_seq !== detail.value.state_seq ||
            saveState.value !== 'saved'))
      )
        throw new Error('ARTICLE_DRAFT_NOT_DURABLE');
      // A collaborative save consumes the immutable target registered by this request.
      attempt.value = {
        ...targetAttempt.value.metadata,
        id: props.articleId,
        document_id: data.document_id,
        expected_state_seq: target?.stateSeq ?? data.state_seq,
        owner_revision: baseline,
        request_id: requestId,
      };
      mountedEditor.value = false;
      saveState.value = 'readonly';
    } catch {
      if (current(controller, key, token))
        message.value = reloadRequired.value
          ? '资讯元数据已被其他人修改，请显式重新加载后再发起新的保存。'
          : '草稿或归属已改变，请取得新授权后保存。';
      return false;
    } finally {
      if (!attempt.value && current(controller, key, token))
        documentEditor.value?.releaseSaveTargetHold();
      if (request === controller) busy.value = false;
    }
    await submitOriginal();
    return phase.value === 'completed';
  };
  const observeSaveTarget = async () => {
    const original = targetAttempt.value;
    const currentEditor = editor.value;
    const baseline = ownerBaseline.value;
    if (
      !original ||
      !original.collaborative ||
      !currentEditor ||
      !baseline ||
      busy.value ||
      attempt.value
    )
      return;
    if (
      currentEditor.document_id !== original.documentId ||
      baseline !== original.ownerRevision
    ) {
      message.value = '原保存目标的文档或归属已改变，原请求仍保留。';
      return;
    }
    const { controller, key, token } = start();
    try {
      const { data } = await queryDocumentSaveTarget(
        original.documentId,
        original.requestId,
        controller.signal
      );
      if (!current(controller, key, token) || targetAttempt.value !== original)
        return;
      const target = parseSaveTarget(
        data,
        original.documentId,
        original.requestId
      );
      if (target.phase === 'not_prepared') {
        message.value =
          '原保存目标尚未准备成功，未生成业务版本。原请求和本地内容已保留，可明确取消原目标后重新加载。';
        return;
      }
      if (target.phase === 'canceled') {
        message.value =
          '原保存目标已取消，未另建正式版本。重新加载会放弃本地未确认内容，请使用明确的取消并重新加载操作。';
        return;
      }
      if (target.phase === 'prepared') {
        message.value =
          '原目标已准备，实际保存仍未确认。本地内容和原请求编号继续保留。';
        return;
      }
      attempt.value = {
        ...original.metadata,
        id: props.articleId,
        document_id: original.documentId,
        expected_state_seq: Number(target.state_seq),
        owner_revision: original.ownerRevision,
        request_id: original.requestId,
      };
      mountedEditor.value = false;
      saveState.value = 'readonly';
    } catch {
      if (current(controller, key, token))
        message.value = '原目标仍未确认；保留本地副本与原请求，不另建保存。';
      return;
    } finally {
      if (request === controller) busy.value = false;
    }
    await submitOriginal();
  };
  const onDocumentLoad = (value: DocumentDetail) => {
    detail.value = value;
    // Surface reload occurs only after release or an explicit baseline restart.
    documentOperation.value = undefined;
  };
  const onDocumentOperation = (value: DocumentOperationFeedback) => {
    if (disposed || value.documentId !== editor.value?.document_id) return;
    documentOperation.value = value;
    if (value.state === 'completed') {
      message.value = value.released
        ? '正文已恢复为新版本，原历史仍保留。资讯正式绑定未改变，请正常业务保存；发布状态未改变。'
        : '正文恢复已提交，连接释放尚未确认；保留原操作编号继续查询。';
    } else {
      message.value =
        '正文恢复尚未确认，业务保存和开启多人协作已暂停；请查询或继续原操作。';
    }
  };
  const onEditorError = () => {
    saveState.value = 'error';
    message.value = '正文编辑不可用，请检查当前授权和服务状态。';
  };
  watch(
    context,
    () => {
      initialMetadata = undefined;
      epoch += 1;
      request?.abort();
      request = undefined;
      busy.value = false;
      enablingCollaboration.value = false;
      collaborationBaseline.value = undefined;
      collaborationUnknown.value = false;
      collaborationConflict.value = false;
      mountedEditor.value = false;
      editor.value = undefined;
      ownerBaseline.value = undefined;
      reloadRequired.value = false;
      emit('metadataReady', false);
      detail.value = undefined;
      documentOperation.value = undefined;
      attempt.value = undefined;
      targetAttempt.value = undefined;
      saved.value = undefined;
      phase.value = 'idle';
      ensureId = randomUuid();
      open();
    },
    { immediate: true, flush: 'sync' }
  );
  onBeforeUnmount(() => {
    disposed = true;
    initialMetadata = undefined;
    epoch += 1;
    request?.abort();
    unsubscribe();
  });
  defineExpose({ save });
</script>

<style scoped>
  .article-document-editor {
    margin: 0;
    padding: 0;
    border: 0;
    min-width: 0;
  }
</style>
