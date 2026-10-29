<template>
  <section :aria-busy="loading">
    <p v-if="loading" role="status">正在加载并核验文档内容。</p>
    <p v-if="message" role="status">{{ message }}</p>
    <p v-if="frozen" role="status">
      本地未确认内容已保留，不能当作已保存。
      <button @click="exportUnconfirmed">导出本地 Yjs 副本</button>
    </p>
    <ul v-if="online.length" aria-label="在线成员">
      <li v-for="member in online" :key="member.memberId">
        {{ member.nickname }}（成员 {{ member.memberId }}）
      </li>
    </ul>
    <p v-if="businessFeedback" role="status">
      业务操作 {{ businessFeedback.operationId }}：{{
        businessFeedback.state
      }}；
      {{
        businessFeedback.state === 'completed'
          ? '数据库已确认业务提交'
          : '业务提交未确认，不能显示已保存'
      }}。 {{ businessFeedback.released ? '连接已释放' : '连接释放未确认' }}。
    </p>
    <DocumentOperations
      v-if="detail && props.mode === 'edit' && props.revisionId === undefined"
      :document-id="props.documentId"
      :document="detail"
      :context-key="sessionKey"
      :allowed-actions="props.businessActions ?? []"
      :collaboration-enabled="grants?.collaboration_enabled === true"
      :prepare-save-target="prepareSaveTarget"
      :read-only="operationReadOnly"
      :draft-saved="
        !grants?.collaboration_enabled || !editable || binding?.saved === true
      "
      @lock="lockBusiness"
      @state="businessChanged"
      @released="load"
      @restart="restartBusiness"
      @denied="failed"
    />
    <component
      :is="editable ? ContentEditor : ContentViewer"
      v-if="surface && (!grants?.collaboration_enabled || !editable || binding)"
      :key="sessionKey"
      :content="surface.content"
      :capabilities="surface.capabilities"
      :allowed-operations="surface.operations"
      :context-key="sessionKey"
      :registry="registry"
      :callbacks="surface.callbacks"
      :collaboration-binding="binding"
      @ready="emit('ready')"
      @capabilities-changed="emit('capabilities', $event)"
      @dirty-change="dirty"
      @save-request="save"
      @error="failed"
    />
  </section>
</template>

<script setup lang="ts">
  import { computed, onBeforeUnmount, ref, shallowRef, watch } from 'vue';
  import { encodeStateAsUpdate } from 'yjs';
  import { useUserStore } from '@/store';
  import { getSessionSnapshot } from '@/utils/auth';
  import randomUuid from '@/utils/random-uuid';
  import ContentEditor from '@/modules/peanut-content-tools/src/ContentEditor.vue';
  import ContentViewer from '@/modules/peanut-content-tools/src/ContentViewer.vue';
  import createRegistry from '@/modules/peanut-content-tools/src/contributions';
  import parseCapabilities from '@/modules/peanut-content-tools/src/capabilities';
  import type {
    ContentDescriptor,
    SurfaceCapabilities,
    SurfaceCallbacks,
    ContentCollaborationBinding,
  } from '@/modules/peanut-content-tools/src/contracts';
  import { parseRichTextDocument } from '@/modules/peanut-rich-text/src/document';
  import {
    createCollaborationProvider,
    type AuthenticatedPresence,
  } from '@/modules/peanut-rich-text/src/collaboration/provider';
  import { createFileContentCallbacks } from '@/modules/peanut-file/optional-runtime/src/contentCallbacks';
  import DocumentOperations from './DocumentOperations.vue';
  import {
    getDocumentDetail,
    getDocumentCapabilities,
    saveSoloDocument,
  } from '../api';
  import type {
    DocumentSurfaceOptions,
    DocumentDetail,
    DocumentCapabilities,
    DocumentOwner,
    DocumentSaveState,
    DocumentOperationFeedback,
  } from '../src/contracts';

  const props = withDefaults(
    defineProps<
      DocumentSurfaceOptions & {
        mode: 'read' | 'edit';
        readOnly?: boolean;
        revisionId?: number;
      }
    >(),
    { contextKey: 'document', allowDownloads: false, readOnly: false }
  );
  const emit = defineEmits<{
    (event: 'ready'): void;
    (event: 'load', detail: DocumentDetail): void;
    (event: 'capabilities', value: SurfaceCapabilities): void;
    (event: 'saveState', state: DocumentSaveState): void;
    (event: 'error', code: string): void;
    (event: 'operationState', value: DocumentOperationFeedback): void;
  }>();
  const user = useUserStore();
  const generation = ref(getSessionSnapshot().generation);
  const unsubscribe = user.$subscribe(
    () => {
      generation.value = getSessionSnapshot().generation;
    },
    { flush: 'sync' }
  );
  const registry = createRegistry();
  const loading = ref(false);
  const message = ref('');
  const businessFeedback = shallowRef<DocumentOperationFeedback>();
  const operationLocked = ref(false);
  const soloDirty = ref(false);
  const savingBusy = ref(false);
  const targetPending = ref(false);
  const frozen = ref(false);
  let frozenContext: string | undefined;
  const detail = shallowRef<DocumentDetail>();
  const grants = shallowRef<DocumentCapabilities>();
  const binding = shallowRef<ContentCollaborationBinding>();
  const online = shallowRef<AuthenticatedPresence[]>([]);
  const surface = shallowRef<{
    content: ContentDescriptor;
    capabilities: SurfaceCapabilities;
    callbacks: SurfaceCallbacks;
    operations: ('view' | 'edit' | 'download_original')[];
  }>();
  let disposed = false;
  let request: AbortController | undefined;
  let saving: AbortController | undefined;
  let collaboration: ReturnType<typeof createCollaborationProvider> | undefined;
  let attempt: { input: string; requestId: string } | undefined;
  let editGeneration = 0;
  const sessionKey = computed(() =>
    JSON.stringify([
      generation.value,
      user.accountId,
      user.tenantName,
      user.role,
      user.permissions,
      props.contextKey,
      props.documentId,
      props.owner,
      props.revisionId,
      props.mode,
      props.readOnly,
      props.allowDownloads,
      props.businessActions,
    ])
  );
  const editable = computed(
    () =>
      props.mode === 'edit' &&
      !props.readOnly &&
      props.revisionId === undefined &&
      grants.value?.can_edit === true &&
      surface.value?.capabilities.canEdit === true
  );
  const operationReadOnly = computed(
    () =>
      props.readOnly ||
      props.mode !== 'edit' ||
      props.revisionId !== undefined ||
      loading.value ||
      savingBusy.value ||
      frozen.value ||
      (!grants.value?.collaboration_enabled && soloDirty.value) ||
      (grants.value?.collaboration_enabled === true &&
        editable.value &&
        !operationLocked.value &&
        (binding.value?.ready !== true || collaboration?.writable !== true))
  );
  const prepareSaveTarget = async (requestId: string) => {
    const active = collaboration;
    const key = sessionKey.value;
    if (!active || !active.ready || !active.writable)
      throw new Error('DOCUMENT_COLLABORATION_NOT_READY');
    targetPending.value = true;
    if (binding.value) binding.value = { ...binding.value, writable: false };
    let confirmed = false;
    try {
      const proof = await active.requestSaveTarget(requestId);
      if (disposed || key !== sessionKey.value || collaboration !== active)
        throw new Error('DOCUMENT_SAVE_TARGET_CONTEXT_CHANGED');
      confirmed = true;
      return proof;
    } finally {
      if (
        !confirmed &&
        !disposed &&
        key === sessionKey.value &&
        collaboration === active
      ) {
        targetPending.value = false;
        if (binding.value)
          binding.value = { ...binding.value, writable: active.writable };
      }
    }
  };
  const releaseSaveTargetHold = () => {
    targetPending.value = false;
    if (binding.value && collaboration)
      binding.value = { ...binding.value, writable: collaboration.writable };
  };
  const lockBusiness = () => {
    operationLocked.value = true;
    request?.abort();
    saving?.abort();
    collaboration?.destroy();
    collaboration = undefined;
    online.value = [];
    binding.value = undefined;
    surface.value = undefined;
  };
  const businessChanged = (value: DocumentOperationFeedback) => {
    businessFeedback.value = value;
    emit('operationState', value);
  };
  const sameOwner = (
    a: DocumentOwner | undefined,
    b: DocumentOwner | undefined
  ) =>
    !!a &&
    !!b &&
    ['module_key', 'object_type', 'object_id', 'slot'].every(
      (key) => a[key as keyof DocumentOwner] === b[key as keyof DocumentOwner]
    );
  const clear = () => {
    request?.abort();
    saving?.abort();
    saving = undefined;
    savingBusy.value = false;
    frozen.value = false;
    frozenContext = undefined;
    targetPending.value = false;
    soloDirty.value = false;
    operationLocked.value = false;
    surface.value = undefined;
    binding.value = undefined;
    collaboration?.destroy();
    collaboration = undefined;
    online.value = [];
    detail.value = undefined;
    grants.value = undefined;
    attempt = undefined;
    editGeneration = 0;
    loading.value = false;
  };
  const failed = (code: string) => {
    if (collaboration && binding.value) {
      frozen.value = true;
      frozenContext = sessionKey.value;
      binding.value = {
        ...binding.value,
        ready: false,
        writable: false,
        frozen: true,
      };
    } else clear();
    message.value = code;
    emit('saveState', 'error');
    emit('error', code);
  };
  const exportUnconfirmed = () => {
    if (!frozen.value || frozenContext !== sessionKey.value || !collaboration)
      return;
    const bytes = encodeStateAsUpdate(collaboration.document);
    const url = URL.createObjectURL(
      new Blob([new Uint8Array(bytes).buffer], {
        type: 'application/octet-stream',
      })
    );
    const link = document.createElement('a');
    link.href = url;
    link.download = `document-${props.documentId}-unconfirmed.yjs`;
    link.click();
    URL.revokeObjectURL(url);
  };
  const valid = (controller: AbortController, key: string) =>
    !disposed &&
    !controller.signal.aborted &&
    key === sessionKey.value &&
    getSessionSnapshot().generation === generation.value;
  const load = async () => {
    if (frozen.value && frozenContext === sessionKey.value) return;
    if (
      operationLocked.value &&
      (businessFeedback.value?.state !== 'completed' ||
        !businessFeedback.value.released)
    )
      return;
    clear();
    message.value = '';
    loading.value = true;
    const current = new AbortController();
    request = current;
    const key = sessionKey.value;
    try {
      if (
        !Number.isSafeInteger(props.documentId) ||
        props.documentId < 1 ||
        (props.revisionId !== undefined &&
          (!Number.isSafeInteger(props.revisionId) || props.revisionId < 1))
      )
        throw new Error('DOCUMENT_ID_INVALID');
      const intent =
        props.mode === 'edit' &&
        !props.readOnly &&
        props.revisionId === undefined
          ? 'edit'
          : 'view';
      if (props.metadataResolver && (intent !== 'edit' || !props.owner))
        throw new Error('DOCUMENT_OWNER_METADATA_INTENT_INVALID');
      const resolved = props.metadataResolver
        ? await props.metadataResolver(current.signal)
        : undefined;
      const [contentResponse, capabilityResponse] = resolved
        ? [{ data: resolved.detail }, { data: resolved.capabilities }]
        : await Promise.all([
            getDocumentDetail(
              props.documentId,
              props.revisionId,
              current.signal
            ),
            getDocumentCapabilities(
              props.documentId,
              props.revisionId,
              intent,
              current.signal
            ),
          ]);
      if (!valid(current, key)) return;
      const { data } = contentResponse;
      const permissions = capabilityResponse.data;
      if (
        data.document_id !== props.documentId ||
        permissions.document_id !== props.documentId ||
        !data.owner ||
        Object.keys(data.owner).sort().join(',') !==
          'module_key,object_id,object_type,slot' ||
        Object.values(data.owner).some(
          (value) => typeof value !== 'string' || !value
        ) ||
        (props.owner && !sameOwner(data.owner, props.owner)) ||
        typeof data.authorization_revision !== 'string' ||
        !/^[0-9a-f]{64}$/.test(data.authorization_revision) ||
        !Number.isSafeInteger(data.state_seq) ||
        data.state_seq < 0 ||
        (props.revisionId !== undefined &&
          (data.revision_id !== props.revisionId ||
            data.confirmed !== true ||
            !Number.isSafeInteger(data.source_state_seq) ||
            typeof data.digest !== 'string' ||
            !/^[0-9a-f]{64}$/.test(data.digest))) ||
        (typeof permissions.can_view !== 'boolean' &&
          !(
            props.metadataResolver &&
            intent === 'edit' &&
            permissions.can_view === undefined
          )) ||
        typeof permissions.can_edit !== 'boolean' ||
        typeof permissions.can_download_original !== 'boolean' ||
        typeof permissions.collaboration_enabled !== 'boolean'
      )
        throw new Error('DOCUMENT_RESPONSE_INVALID');
      const capabilities = parseCapabilities(permissions.capabilities);
      if (capabilities.contentKind !== data.content_kind)
        throw new Error('DOCUMENT_RESPONSE_INVALID');
      let content: ContentDescriptor;
      if (data.content_kind === 'rich_text')
        content = {
          kind: 'rich_text',
          identity: `${data.document_id}:${props.revisionId ?? 'current'}`,
          value: parseRichTextDocument(data.structured_content),
        };
      else {
        // A version's document digest is not the immutable file byte hash.
        const hash =
          props.revisionId === undefined ? data.digest : data.file_sha256;
        if (
          typeof data.file_key !== 'string' ||
          !/^file_[0-9a-f]{32}$/.test(data.file_key) ||
          typeof hash !== 'string' ||
          !/^[0-9a-f]{64}$/.test(hash) ||
          typeof data.verified_mime_type !== 'string'
        )
          throw new Error('DOCUMENT_RESPONSE_INVALID');
        content = {
          kind: 'file_resource',
          identity: `${data.document_id}:${props.revisionId ?? 'current'}`,
          fileKey: data.file_key,
          sourceSha256: hash,
          verifiedMimeType: data.verified_mime_type,
          displayName: '文档资源',
        };
      }
      const operations: ('view' | 'edit' | 'download_original')[] = [];
      if (permissions.can_view) operations.push('view');
      if (permissions.can_edit && intent === 'edit') operations.push('edit');
      if (permissions.can_download_original && props.allowDownloads)
        operations.push('download_original');
      const fileCallbacks =
        content.kind === 'file_resource'
          ? createFileContentCallbacks({
              documentId: data.document_id,
              viewerAdapterId: capabilities.viewerAdapterId ?? '',
              canDownloadOriginal: () =>
                valid(current, key) &&
                grants.value?.can_download_original === true &&
                props.allowDownloads,
            })
          : {};
      detail.value = data;
      grants.value = permissions;
      surface.value = {
        content,
        capabilities,
        operations,
        callbacks: { ...props.callbacks, ...fileCallbacks },
      };
      emit('load', data);
      emit('saveState', editable.value ? 'saved' : 'readonly');
      if (permissions.collaboration_enabled && intent === 'edit') {
        if (
          !editable.value ||
          !props.resolveTransport ||
          content.kind !== 'rich_text'
        ) {
          message.value = '协同编辑不可用：等待受权工具能力与服务配置。';
          return;
        }
        emit('saveState', 'syncing');
        const transport = await props.resolveTransport(
          data,
          permissions,
          current.signal
        );
        if (!valid(current, key)) return;
        if (
          transport.documentId !== data.document_id ||
          !sameOwner(transport.owner, data.owner) ||
          transport.authorizationRevision !== data.authorization_revision ||
          transport.capabilityRevision !== capabilities.capabilityRevision ||
          transport.mode !== 'write'
        )
          throw new Error('DOCUMENT_TRANSPORT_INVALID');
        const active = createCollaborationProvider({
          url: transport.url,
          documentId: data.document_id,
          documentName: transport.documentName,
          token: getSessionSnapshot().token ?? '',
          onPresenceChanged: (members) => {
            if (valid(current, key)) online.value = members;
          },
          onRecoveryRequired: (code) => {
            if (valid(current, key)) {
              failed(
                /^[A-Z][A-Z0-9_]{0,127}$/.test(code)
                  ? code
                  : 'RECOVERY_REQUIRED'
              );
            }
          },
          onSavedChanged: (saved) => {
            if (!valid(current, key) || !binding.value || frozen.value) return;
            binding.value = { ...binding.value, saved };
            let state: 'readonly' | 'saved' | 'persisting' | 'syncing' =
              'syncing';
            if (!active.writable) state = 'readonly';
            else if (saved) state = 'saved';
            else if (binding.value.ready) state = 'persisting';
            emit('saveState', state);
          },
          onReadyChanged: (ready) => {
            if (!valid(current, key) || !binding.value || frozen.value) return;
            binding.value = {
              ...binding.value,
              ready,
            };
            if (!ready) emit('saveState', 'syncing');
          },
          onWritableChanged: (writable) => {
            if (!valid(current, key) || !binding.value || frozen.value) return;
            binding.value = {
              ...binding.value,
              ready: active.ready,
              writable: writable && !targetPending.value,
            };
            if (!writable) emit('saveState', 'readonly');
          },
          onCommitted: (proof) => {
            if (
              !valid(current, key) ||
              !detail.value ||
              proof.documentId !== data.document_id ||
              !sameOwner(detail.value.owner, data.owner)
            )
              return;
            if (proof.stateSeq > detail.value.state_seq) {
              detail.value = { ...detail.value, state_seq: proof.stateSeq };
              emit('load', detail.value);
            }
          },
        });
        collaboration = active;
        binding.value = {
          protocol: 'yjs/1',
          document: active.document,
          generation: active.tracker.generation,
          ready: false,
          writable: active.writable,
          saved: false,
        };
      }
    } catch (error) {
      if (valid(current, key)) {
        surface.value = undefined;
        failed(error instanceof Error ? error.message : 'DOCUMENT_LOAD_FAILED');
      }
    } finally {
      if (request === current) loading.value = false;
    }
  };
  const restartBusiness = async () => {
    businessFeedback.value = undefined;
    operationLocked.value = false;
    await load();
  };
  const dirty = (changed: boolean) => {
    if (changed && !binding.value) {
      editGeneration += 1;
      soloDirty.value = true;
      emit('saveState', 'idle');
    }
  };
  const save = async (value: unknown) => {
    if (
      !editable.value ||
      !detail.value ||
      grants.value?.collaboration_enabled ||
      binding.value ||
      saving
    )
      return;
    const data = detail.value;
    const key = sessionKey.value;
    const submittedGeneration = editGeneration;
    const current = new AbortController();
    saving = current;
    savingBusy.value = true;
    emit('saveState', 'persisting');
    try {
      if (!valid(current, key)) return;
      const structured = parseRichTextDocument(value);
      const input = JSON.stringify([
        data.document_id,
        data.owner,
        data.state_seq,
        structured,
      ]);
      if (attempt?.input !== input)
        attempt = { input, requestId: randomUuid() };
      const response = await saveSoloDocument(
        data.document_id,
        data.state_seq,
        structured,
        attempt.requestId,
        current.signal
      );
      if (!valid(current, key)) return;
      const receipt = response.data;
      if (
        receipt.document_id !== data.document_id ||
        receipt.state_seq !== data.state_seq + 1 ||
        !/^[0-9a-f]{64}$/.test(receipt.digest)
      )
        throw new Error('DOCUMENT_SAVE_RECEIPT_INVALID');
      detail.value = {
        ...data,
        state_seq: receipt.state_seq,
        digest: receipt.digest,
        persisted_at: receipt.persisted_at,
        structured_content: structured,
      };
      // Advance the durable CAS base without replacing newer local input.
      const caughtUp = editGeneration === submittedGeneration;
      if (caughtUp && surface.value?.content.kind === 'rich_text')
        surface.value = {
          ...surface.value,
          content: { ...surface.value.content, value: structured },
        };
      attempt = undefined;
      soloDirty.value = !caughtUp;
      emit('load', detail.value);
      emit('saveState', caughtUp ? 'saved' : 'idle');
    } catch (error) {
      if (valid(current, key))
        failed(
          error instanceof Error ? error.message : 'DOCUMENT_SAVE_UNCONFIRMED'
        );
    } finally {
      if (saving === current) {
        saving = undefined;
        savingBusy.value = false;
      }
    }
  };
  watch(
    sessionKey,
    () => {
      businessFeedback.value = undefined;
      operationLocked.value = false;
    },
    { flush: 'sync' }
  );
  watch(
    [
      sessionKey,
      () => props.callbacks,
      () => props.resolveTransport,
      () => props.metadataResolver,
    ],
    load,
    { immediate: true, flush: 'sync' }
  );
  onBeforeUnmount(() => {
    disposed = true;
    clear();
    unsubscribe();
  });
  defineExpose({
    prepareSaveTarget,
    releaseSaveTargetHold,
    reload: () => {
      if (
        operationLocked.value &&
        (businessFeedback.value?.state !== 'completed' ||
          !businessFeedback.value.released)
      )
        return;
      load();
    },
  });
</script>
