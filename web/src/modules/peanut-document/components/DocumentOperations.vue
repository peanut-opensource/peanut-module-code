<template>
  <section
    v-if="allowed.length || feedback"
    aria-label="文档业务操作"
    :aria-busy="busy"
  >
    <template v-if="!command">
      <select
        v-model="action"
        :disabled="busy || readOnly || !document || !!targetRequestId"
        aria-label="文档操作"
      >
        <option v-for="item in allowed" :key="item" :value="item">{{
          labels[item]
        }}</option>
      </select>
      <input
        v-if="action === 'version.create'"
        v-model="reason"
        maxlength="255"
        placeholder="版本原因"
        :disabled="busy || !!targetRequestId"
      />
      <input
        v-if="action === 'version.restore'"
        v-model="revision"
        type="number"
        min="1"
        placeholder="不可变版本 ID"
        :disabled="busy"
      />
      <button
        :disabled="
          busy ||
          readOnly ||
          !document ||
          !action ||
          (collaborationEnabled && action !== 'version.create' && !draftSaved)
        "
        @click="start"
        >执行业务操作</button
      >
    </template>
    <button
      v-if="targetRequestId && !command"
      :disabled="busy"
      @click="observeTarget"
    >
      查询原保存目标并继续
    </button>
    <p v-if="feedback" role="status">
      操作 {{ feedback.operationId }}：{{ feedback.state }}。
      <template v-if="feedback.state === 'completed'"
        >数据库已确认业务提交；{{
          feedback.released
            ? '原连接已释放，可重新加载。'
            : '连接释放未确认，保持旧编辑器卸载。'
        }}</template
      >
      <template v-else
        >尚未确认业务提交，不能显示已保存；关闭界面不会取消后台操作。</template
      >
      {{ feedback.errorCode }}
    </p>
    <template v-if="command">
      <button
        v-if="collaborationEnabled"
        :disabled="busy || canRestart"
        @click="query"
        >查询原 operation ID</button
      >
      <button
        :disabled="
          busy || canRestart || readOnly || !allowed.includes(command.action)
        "
        @click="submit"
        >用原标识和原输入继续</button
      >
      <button v-if="canRestart" :disabled="busy" @click="restart"
        >基线已改变；重新加载后建立新操作</button
      >
      <p v-if="!collaborationEnabled"
        >Solo 未提供 operation 查询；未知结果仅能用原请求与原输入显式重试。</p
      >
    </template>
  </section>
</template>

<script setup lang="ts">
  import axios from 'axios';
  import { computed, onBeforeUnmount, ref, shallowRef, watch } from 'vue';
  import { getSessionSnapshot } from '@/utils/auth';
  import randomUuid from '@/utils/random-uuid';
  import type { CommittedProof } from '@/modules/peanut-rich-text/src/collaboration/provider';
  import {
    DocumentOperationRejection,
    queryDocumentOperation,
    queryDocumentSaveTarget,
    submitDocumentBusinessOperation,
  } from '../api';
  import {
    operationKey,
    parseBusinessResult,
    parseDocumentOperation,
    validateSoloResult,
    parseSaveTarget,
  } from '../src/operations';
  import type { DocumentBusinessCommand } from '../src/operations';
  import type {
    DocumentDetail,
    DocumentBusinessAction,
    DocumentOperationFeedback,
  } from '../src/contracts';

  const props = defineProps<{
    documentId: number;
    document?: DocumentDetail;
    contextKey: string;
    allowedActions: DocumentBusinessAction[];
    collaborationEnabled: boolean;
    readOnly: boolean;
    draftSaved: boolean;
    prepareSaveTarget?: (requestId: string) => Promise<CommittedProof>;
  }>();
  const emit = defineEmits<{
    (event: 'lock'): void;
    (event: 'state', value: DocumentOperationFeedback): void;
    (event: 'released'): void;
    (event: 'denied', code: string): void;
    (event: 'restart'): void;
  }>();
  const labels: Record<DocumentBusinessAction, string> = {
    'version.create': '创建不可变版本',
    'version.restore': '恢复旧版本为新版本',
    'archive': '归档',
    'restore': '恢复归档',
  };
  const allowed = computed(() =>
    Array.isArray(props.allowedActions)
      ? props.allowedActions.filter(
          (item) =>
            typeof item === 'string' &&
            Object.prototype.hasOwnProperty.call(labels, item)
        )
      : []
  );
  const action = ref<DocumentBusinessAction>();
  const reason = ref('');
  const revision = ref('');
  const command = shallowRef<DocumentBusinessCommand>();
  const feedback = shallowRef<DocumentOperationFeedback>();
  const busy = ref(false);
  const canRestart = ref(false);
  let uncertainOrAdmitted = false;
  let targetRequestId: string | undefined;
  let targetReason: string | undefined;
  let disposed = false;
  let epoch = 0;
  let controller: AbortController | undefined;
  let timer: ReturnType<typeof setTimeout> | undefined;
  let observations = 0;
  const stopTimer = () => {
    if (timer !== undefined) clearTimeout(timer);
    timer = undefined;
  };
  const clear = () => {
    epoch += 1;
    controller?.abort();
    controller = undefined;
    stopTimer();
    command.value = undefined;
    feedback.value = undefined;
    busy.value = false;
    observations = 0;
    canRestart.value = false;
    uncertainOrAdmitted = false;
    targetRequestId = undefined;
    targetReason = undefined;
    reason.value = '';
    revision.value = '';
  };
  const publish = (value: DocumentOperationFeedback) => {
    feedback.value = value;
    emit('state', value);
  };
  const state = (
    status: DocumentOperationFeedback['state'],
    released = false,
    errorCode: string | undefined = undefined
  ) => {
    const input = command.value;
    if (!input) return;
    publish({
      documentId: input.input.document_id,
      operationId: operationKey(input),
      action: input.action,
      state: status,
      released,
      errorCode,
      result: feedback.value?.result,
      receipt: feedback.value?.receipt,
    });
  };
  async function request(
    call: (signal: AbortSignal) => Promise<unknown>,
    receive: (value: unknown) => void,
    submitting = false
  ) {
    if (busy.value || disposed || !command.value) return;
    stopTimer();
    const current = new AbortController();
    controller = current;
    const key = props.contextKey;
    const currentEpoch = epoch;
    const { generation } = getSessionSnapshot();
    const live = () =>
      !disposed &&
      !current.signal.aborted &&
      epoch === currentEpoch &&
      key === props.contextKey &&
      generation === getSessionSnapshot().generation;
    busy.value = true;
    try {
      const result = await call(current.signal);
      if (live()) receive(result);
    } catch (failure) {
      if (!live()) return;
      let status: number | undefined;
      if (failure instanceof DocumentOperationRejection)
        status = failure.status;
      else if (axios.isAxiosError(failure)) status = failure.response?.status;
      const code =
        failure instanceof Error
          ? failure.message
          : 'DOCUMENT_OPERATION_UNCONFIRMED';
      if (status === 401 || status === 403) {
        feedback.value = undefined;
        state('failed', false, code);
        stopTimer();
        command.value = undefined;
        emit('denied', code);
      } else if (feedback.value?.state === 'completed')
        state('completed', false, code);
      else {
        const input = command.value;
        // These fixed producer codes occur before admission/receipt creation.
        // A prior unknown request may still be in flight, so it cannot gain a new ID.
        canRestart.value =
          submitting &&
          !uncertainOrAdmitted &&
          status === 409 &&
          failure instanceof DocumentOperationRejection &&
          !!input &&
          failure.errorCode ===
            (input.action.startsWith('version.')
              ? 'DOCUMENT_STATE_SEQ_CONFLICT'
              : 'DOCUMENT_AUTH_REVISION_CONFLICT');
        if (!canRestart.value) uncertainOrAdmitted = true;
        state(
          status === 409 || status === 422 ? 'failed' : 'unknown',
          false,
          code
        );
      }
    } finally {
      if (controller === current) {
        busy.value = false;
        controller = undefined;
      }
    }
  }
  async function query() {
    const input = command.value;
    if (!input || !props.collaborationEnabled) return;
    await request(
      async (signal) =>
        (
          await queryDocumentOperation(
            input.input.document_id,
            operationKey(input),
            signal
          )
        ).data,
      (value) => {
        const result = parseDocumentOperation(value, input);
        uncertainOrAdmitted = true;
        canRestart.value = false;
        if (result.phase === 'completed' && result.result) {
          publish({
            documentId: result.document_id,
            operationId: result.operation_id,
            action: result.action,
            state: 'completed',
            released: result.released,
            result: result.result,
          });
          if (result.released) emit('released');
        } else
          state(
            'pending',
            false,
            result.phase === 'attention'
              ? 'DOCUMENT_OPERATION_ATTENTION'
              : undefined
          );
      }
    );
    if (
      command.value === input &&
      feedback.value &&
      !feedback.value.released &&
      feedback.value.state !== 'failed' &&
      observations < 5
    ) {
      observations += 1;
      timer = setTimeout(query, 3000);
    }
  }
  async function submit() {
    const input = command.value;
    if (!input || canRestart.value || !allowed.value.includes(input.action))
      return;
    state(
      feedback.value?.result || feedback.value?.receipt
        ? 'completed'
        : 'pending'
    );
    await request(
      async (signal) =>
        (
          await submitDocumentBusinessOperation(input, signal)
        ).data,
      (value) => {
        if (props.collaborationEnabled) {
          const result = parseBusinessResult(
            value,
            input.input.document_id,
            operationKey(input),
            input.action
          );
          uncertainOrAdmitted = true;
          publish({
            documentId: input.input.document_id,
            operationId: operationKey(input),
            action: input.action,
            state: 'completed',
            released: false,
            result,
          });
        } else {
          const receipt = validateSoloResult(value, input);
          publish({
            documentId: input.input.document_id,
            operationId: operationKey(input),
            action: input.action,
            state: 'completed',
            released: true,
            receipt,
          });
          emit('released');
        }
      },
      true
    );
    if (
      command.value === input &&
      props.collaborationEnabled &&
      feedback.value?.state !== 'failed'
    )
      await query();
  }
  const restart = () => {
    if (busy.value || !canRestart.value) return;
    clear();
    emit('restart');
  };
  const start = async () => {
    const detail = props.document;
    const selected = action.value;
    if (
      busy.value ||
      !detail ||
      detail.document_id !== props.documentId ||
      !Number.isSafeInteger(detail.state_seq) ||
      detail.state_seq < 0 ||
      !Number.isSafeInteger(detail.auth_revision) ||
      detail.auth_revision < 0 ||
      props.readOnly ||
      !selected ||
      (props.collaborationEnabled &&
        selected !== 'version.create' &&
        !props.draftSaved) ||
      !allowed.value.includes(selected) ||
      command.value
    )
      return;
    const key =
      selected === 'version.create'
        ? (targetRequestId ??= randomUuid())
        : randomUuid();
    if (selected === 'version.create') {
      if (!reason.value.trim() || [...reason.value].length > 255) return;
      targetReason ??= reason.value;
      let stateSeq = detail.state_seq;
      if (props.collaborationEnabled) {
        if (!props.prepareSaveTarget) return;
        const currentEpoch = epoch;
        const { contextKey } = props;
        busy.value = true;
        try {
          const proof = await props.prepareSaveTarget(key);
          if (
            disposed ||
            epoch !== currentEpoch ||
            props.contextKey !== contextKey
          )
            return;
          stateSeq = proof.stateSeq;
        } catch (error) {
          emit(
            'denied',
            error instanceof Error
              ? error.message
              : 'DOCUMENT_SAVE_TARGET_FAILED'
          );
          return;
        } finally {
          if (epoch === currentEpoch) busy.value = false;
        }
      }
      command.value = {
        action: selected,
        input: {
          document_id: detail.document_id,
          expected_state_seq: stateSeq,
          reason: targetReason,
          idempotency_key: key,
        },
      };
    } else if (selected === 'version.restore') {
      const id = Number(revision.value);
      if (!Number.isSafeInteger(id) || id < 1) return;
      command.value = {
        action: selected,
        input: {
          document_id: detail.document_id,
          expected_state_seq: detail.state_seq,
          revision_id: id,
          request_id: key,
        },
      };
    } else
      command.value = {
        action: selected,
        input: {
          document_id: detail.document_id,
          expected_auth_revision: detail.auth_revision,
          request_id: key,
        },
      };
    emit('lock');
    await submit();
  };
  const observeTarget = async () => {
    const requestId = targetRequestId;
    const originalReason = targetReason;
    const { documentId } = props;
    if (!requestId || !originalReason || busy.value || command.value) return;
    const current = new AbortController();
    controller = current;
    const currentEpoch = epoch;
    const key = props.contextKey;
    busy.value = true;
    try {
      const { data } = await queryDocumentSaveTarget(
        documentId,
        requestId,
        current.signal
      );
      if (
        disposed ||
        current.signal.aborted ||
        epoch !== currentEpoch ||
        props.contextKey !== key
      )
        return;
      const target = parseSaveTarget(data, documentId, requestId);
      if (target.phase === 'not_prepared') {
        emit('denied', 'DOCUMENT_SAVE_TARGET_NOT_PREPARED');
        return;
      }
      if (target.phase === 'canceled') {
        emit('denied', 'DOCUMENT_SAVE_TARGET_CANCELED');
        return;
      }
      if (target.phase === 'prepared') {
        emit('denied', 'DOCUMENT_SAVE_TARGET_UNCONFIRMED');
        return;
      }
      command.value = {
        action: 'version.create',
        input: {
          document_id: documentId,
          expected_state_seq: Number(target.state_seq),
          reason: originalReason,
          idempotency_key: requestId,
        },
      };
      emit('lock');
    } catch (error) {
      if (!disposed && epoch === currentEpoch && props.contextKey === key)
        emit(
          'denied',
          error instanceof Error
            ? error.message
            : 'DOCUMENT_SAVE_TARGET_UNCONFIRMED'
        );
      return;
    } finally {
      if (epoch === currentEpoch) busy.value = false;
    }
    await submit();
  };
  watch(
    [
      () => props.contextKey,
      () => props.documentId,
      () => JSON.stringify(props.allowedActions),
      () => props.collaborationEnabled,
    ],
    () => {
      clear();
      [action.value] = allowed.value;
    },
    { immediate: true, flush: 'sync' }
  );
  onBeforeUnmount(() => {
    disposed = true;
    clear();
  });
</script>
