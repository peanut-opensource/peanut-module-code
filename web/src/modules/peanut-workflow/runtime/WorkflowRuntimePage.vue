<script setup lang="ts">
  import {
    computed,
    markRaw,
    onBeforeUnmount,
    ref,
    shallowRef,
    watch,
  } from 'vue';
  import type { Component } from 'vue';
  import { useRouter } from 'vue-router';
  import { getSessionSnapshot } from '@/utils/auth';
  import { resolveWorkflowAttachment } from '../api';
  import { createHostWorkflowApi } from '../host-api';
  import type {
    InboxFilter,
    WorkItem,
    WorkflowEvent,
    WorkflowInstance,
    WorkflowAttachment,
  } from '../types';
  import { useWorkflowTaskForm } from './business-forms';
  import type { TaskActionDetails } from '../api';
  import { useWorkflowLifecycle } from './lifecycle';
  import {
    INSTANCE_ACTION_LABELS,
    requiresReason,
    TASK_ACTION_LABELS,
    visibleTaskActions,
  } from './task-actions';

  const props = defineProps<{ instanceKey?: string }>();
  const actionError = ref<HTMLElement | null>(null);
  const emit = defineEmits<{
    (event: 'instance', instance: WorkflowInstance): void;
  }>();
  const formProvider = useWorkflowTaskForm();
  const api = createHostWorkflowApi();
  const router = useRouter();
  // Exact optional local modules: a Workflow-only application has no File/Content Tools imports.
  const contentTools = import.meta.glob(
    '/src/modules/peanut-content-tools/src/{ContentViewer.vue,contributions.ts,capabilities.ts}'
  );
  const fileTools = import.meta.glob(
    '/src/modules/peanut-file/optional-runtime/src/contentCallbacks.ts'
  );
  const attachmentBusy = ref(false);
  const attachmentSurface = shallowRef<{
    viewer: Component;
    content: Record<string, unknown>;
    capabilities: Record<string, unknown>;
    callbacks: Record<string, unknown>;
    registry: object;
    contextKey: string;
    operations: string[];
  } | null>(null);
  let attachmentController: AbortController | null = null;
  let attachmentEpoch = 0;
  function closeAttachment() {
    attachmentEpoch += 1;
    attachmentController?.abort();
    attachmentController = null;
    attachmentSurface.value = null;
    attachmentBusy.value = false;
  }
  const filter = ref<InboxFilter>('assigned');
  const page = ref(1);
  const count = ref(0);
  const tasks = shallowRef<
    {
      instance_key: string;
      label: string;
      status: string;
      owner_member_id: string | null;
      assignee_member_id: string | null;
    }[]
  >([]);
  const instance = shallowRef<WorkflowInstance | null>(null);
  const events = shallowRef<WorkflowEvent[]>([]);
  const loading = ref(false);
  const submitting = ref(false);
  const pageActive = ref(true);
  const error = ref('');
  const notice = ref('');
  const action = ref('');
  const selectedTask = shallowRef<WorkItem | null>(null);
  const comment = ref('');
  const dialog = ref(false);
  watch(
    error,
    () => {
      if (dialog.value && error.value) actionError.value?.focus();
    },
    { flush: 'post' }
  );
  const reassignTaskKey = ref('');
  const newAssignee = ref('');
  let requestEpoch = 0;
  // Detail selection is independent from inbox/list refresh generations.
  let selectionGeneration = 0;
  let selectedInstanceKey: string | null = null;
  let controller: AbortController | null = null;
  let timer: ReturnType<typeof setTimeout> | null = null;
  let removeVisibilityListener: (() => void) | undefined;
  const lifecycle = useWorkflowLifecycle(() => {
    closeAttachment();
    pageActive.value = false;
    requestEpoch += 1;
    selectionGeneration += 1;
    selectedInstanceKey = null;
    if (timer) clearTimeout(timer);
    timer = null;
    removeVisibilityListener?.();
    tasks.value = [];
    count.value = 0;
    instance.value = null;
    events.value = [];
    selectedTask.value = null;
    action.value = '';
    comment.value = '';
    reassignTaskKey.value = '';
    newAssignee.value = '';
    error.value = '';
    notice.value = '';
    dialog.value = false;
    loading.value = false;
    submitting.value = false;
    controller?.abort();
    controller = null;
    api.dispose();
  });
  const instanceActions = computed(
    () =>
      instance.value?.available_actions.filter((entry) =>
        Object.prototype.hasOwnProperty.call(INSTANCE_ACTION_LABELS, entry)
      ) || []
  );
  const filterOptions: { value: InboxFilter; label: string }[] = [
    { value: 'assigned', label: '我的待办' },
    { value: 'claimable', label: '待领取' },
    { value: 'completed', label: '已办' },
    { value: 'initiated', label: '我发起的' },
    { value: 'participant', label: '我参与的' },
    { value: 'watcher', label: '抄送给我的' },
  ];
  const fail = (cause: unknown) => {
    error.value = cause instanceof Error ? cause.message : '请求失败';
  };
  async function accessAttachment(
    attachment: WorkflowAttachment,
    sequence: number,
    operation: 'preview' | 'download_original' | 'manage'
  ) {
    if (!lifecycle.valid() || !instance.value) return;
    closeAttachment();
    const epoch = attachmentEpoch;
    const instanceKey = instance.value.instance_key;
    const { generation, token } = getSessionSnapshot();
    const abort = new AbortController();
    attachmentController = abort;
    const valid = () =>
      epoch === attachmentEpoch &&
      !abort.signal.aborted &&
      lifecycle.valid() &&
      generation === getSessionSnapshot().generation &&
      instance.value?.instance_key === instanceKey;
    attachmentBusy.value = true;
    error.value = '';
    try {
      const access = await resolveWorkflowAttachment(
        attachment,
        operation,
        token,
        abort.signal
      );
      if (!valid()) return;
      if (operation === 'manage') {
        // This operates on the current File object, never a fabricated historical FileRecord.
        await router.push({ name: 'peanut.file.assets' });
        return;
      }
      const callbacksLoader =
        fileTools[
          '/src/modules/peanut-file/optional-runtime/src/contentCallbacks.ts'
        ];
      if (!callbacksLoader) throw new Error('当前应用未安装 File 内容访问能力');
      const callbackModule = (await callbacksLoader()) as {
        createFileContentCallbacks: (options: {
          viewerAdapterId: string;
          canDownloadOriginal: () => boolean;
        }) => Record<string, unknown>;
      };
      if (!valid()) return;
      let mime = attachment.media_type;
      let capabilities: Record<string, unknown> = {};
      if (operation === 'preview') {
        const parser =
          contentTools[
            '/src/modules/peanut-content-tools/src/capabilities.ts'
          ];
        if (!parser) throw new Error('当前应用未安装内容工具');
        const capabilityModule = (await parser()) as {
          default: (value: unknown) => Record<string, unknown>;
        };
        capabilities = capabilityModule.default(access.capabilities);
        if (!valid()) return;
        if (
          capabilities.contentKind !== 'file_resource' ||
          capabilities.canView !== true ||
          typeof capabilities.verifiedMimeType !== 'string' ||
          typeof capabilities.viewerAdapterId !== 'string'
        )
          throw new Error('当前文件暂无可用的受控预览');
        mime = capabilities.verifiedMimeType;
      }
      const content = {
        kind: 'file_resource',
        identity: attachment.file_key,
        fileKey: attachment.file_key,
        sourceSha256: attachment.sha256,
        verifiedMimeType: mime,
        displayName: attachment.name,
      };
      const callbacks = callbackModule.createFileContentCallbacks({
        viewerAdapterId:
          typeof capabilities.viewerAdapterId === 'string'
            ? capabilities.viewerAdapterId
            : '',
        canDownloadOriginal: () =>
          valid() && access.can_download_original === true,
      });
      if (operation === 'download_original') {
        if (typeof callbacks.downloadOriginal !== 'function')
          throw new Error('当前文件无法下载原件');
        await callbacks.downloadOriginal(content, abort.signal);
        return;
      }
      const viewerLoader =
        contentTools[
          '/src/modules/peanut-content-tools/src/ContentViewer.vue'
        ];
      const registryLoader =
        contentTools[
          '/src/modules/peanut-content-tools/src/contributions.ts'
        ];
      if (!viewerLoader || !registryLoader)
        throw new Error('当前应用未安装内容查看器');
      const [viewerModule, registryModule] = (await Promise.all([
        viewerLoader(),
        registryLoader(),
      ])) as [{ default: Component }, { default: () => object }];
      if (!valid()) return;
      attachmentSurface.value = {
        viewer: markRaw(viewerModule.default),
        content,
        capabilities,
        callbacks,
        registry: markRaw(registryModule.default()),
        contextKey: `${instanceKey}:${sequence}:${attachment.file_key}:${attachment.sha256}:${generation}:${epoch}`,
        operations:
          access.can_download_original === true
            ? ['view', 'download_original']
            : ['view'],
      };
    } catch (cause) {
      if (valid()) {
        closeAttachment();
        fail(cause);
      }
    } finally {
      if (epoch === attachmentEpoch) attachmentBusy.value = false;
    }
  }
  async function load() {
    if (!lifecycle.valid()) return;
    controller?.abort();
    controller = new AbortController();
    requestEpoch += 1;
    const epoch = requestEpoch;
    loading.value = true;
    error.value = '';
    try {
      const result = await api.inbox(
        filter.value,
        page.value,
        controller.signal
      );
      if (epoch !== requestEpoch || !lifecycle.valid()) return;
      tasks.value = result.lists.map((item) =>
        'work_item_key' in item
          ? item
          : {
              instance_key: item.instance_key,
              label: item.instance_key,
              status: item.status,
              owner_member_id: null,
              assignee_member_id: null,
            }
      );
      count.value = result.count;
    } catch (cause) {
      if (epoch === requestEpoch && lifecycle.valid()) fail(cause);
    } finally {
      if (epoch === requestEpoch && lifecycle.valid()) loading.value = false;
    }
  }
  async function showInstance(key: string) {
    if (!lifecycle.valid()) return;
    if (selectedInstanceKey !== key) {
      selectedInstanceKey = key;
      selectionGeneration += 1;
      selectedTask.value = null;
      action.value = '';
      comment.value = '';
      reassignTaskKey.value = '';
      newAssignee.value = '';
      dialog.value = false;
      notice.value = '';
    }
    closeAttachment();
    controller?.abort();
    controller = new AbortController();
    requestEpoch += 1;
    const epoch = requestEpoch;
    loading.value = true;
    error.value = '';
    instance.value = null;
    events.value = [];
    try {
      const detail = await api.instance(key, controller.signal);
      const history = await api.events(key, '0', controller.signal);
      if (epoch !== requestEpoch || !lifecycle.valid()) return;
      instance.value = detail;
      emit('instance', detail);
      events.value = history;
    } catch (cause) {
      if (epoch === requestEpoch && lifecycle.valid()) fail(cause);
    } finally {
      if (epoch === requestEpoch && lifecycle.valid()) loading.value = false;
    }
  }
  function openAction(next: string, task: WorkItem | null = null) {
    action.value = next;
    selectedTask.value = task;
    comment.value = '';
    reassignTaskKey.value = '';
    newAssignee.value = '';
    reassignTaskKey.value = '';
    newAssignee.value = '';
    dialog.value = true;
    notice.value = '';
  }
  async function submit() {
    if (!instance.value || submitting.value || !lifecycle.valid()) return;
    if (requiresReason(action.value) && !comment.value.trim()) {
      error.value = '该操作必须填写原因';
      return;
    }
    const reassignTask = instance.value.reassign_work_items.find(
      (entry) => entry.work_item_key === reassignTaskKey.value
    );
    if (
      action.value === 'reassign' &&
      (!reassignTask || !/^[1-9]\d*$/.test(newAssignee.value))
    ) {
      error.value = '请选择可见待处理工作项，并填写合法的新办理人成员 ID';
      return;
    }
    submitting.value = true;
    error.value = '';
    const prior = instance.value;
    const selectionAtSubmit = selectionGeneration;
    // Ordinary list refresh must not invalidate the selected workflow action.
    const stillSelected = () =>
      lifecycle.valid() &&
      selectionAtSubmit === selectionGeneration &&
      (selectedInstanceKey === null ||
        selectedInstanceKey === prior.instance_key);
    const task = selectedTask.value;
    try {
      let details: TaskActionDetails | undefined;
      if (
        task &&
        formProvider &&
        (action.value === 'complete' || action.value === 'resubmit')
      ) {
        const form = await formProvider({
          action: action.value,
          workItem: task,
          instance: prior,
        });
        if (!stillSelected() || form === null) return;
        details = form;
        if (form.comment !== undefined) comment.value = form.comment;
      }
      if (task && ['complete', 'resubmit'].includes(action.value) && !details)
        throw new Error(
          '该业务模块尚未登记保存/确认表单接口，请从业务表单完成材料后操作。'
        );
      // An async form may finish after the user opens another workflow.
      if (!stillSelected()) return;
      let result: WorkflowInstance;
      if (task) {
        result = await api.taskAction(
          task,
          prior,
          action.value,
          comment.value.trim(),
          crypto.randomUUID(),
          details
        );
      } else {
        let instanceDetails: Parameters<typeof api.instanceAction>[4];
        if (action.value === 'reassign' && reassignTask) {
          instanceDetails = {
            work_item_key: reassignTask.work_item_key,
            assignee_member_id: newAssignee.value,
            expected_task_revision: reassignTask.revision,
          };
        } else if (
          action.value === 'terminate' &&
          prior.active_nodes.some(
            (node) => node.task_termination_evidence_required
          )
        ) {
          const diagnostic = await api.diagnostics(prior.instance_key);
          if (!stillSelected()) return;
          const cancelledNode = diagnostic.items.find(
            (node) => node.job.status === 'cancelled'
          );
          if (cancelledNode) {
            instanceDetails = {
              expected_job_revision: cancelledNode.job.revision,
            };
          }
        }
        result = await api.instanceAction(
          prior,
          action.value,
          comment.value.trim(),
          crypto.randomUUID(),
          instanceDetails
        );
      }
      if (!stillSelected()) return;
      instance.value = result;
      emit('instance', result);
      dialog.value = false;
      notice.value =
        result.status === 'blocked'
          ? '本次操作已记录，下一步骤阻塞，请查看原因。'
          : '操作已记录，以当前流程状态为准。';
      await load();
    } catch (cause) {
      if (!stillSelected()) return;
      fail(cause);
      notice.value =
        '操作失败时不会自动重新提交。保留本地意见，请刷新当前状态后核对。';
      // Refresh state after a possible conflict; preserve the unsent opinion.
      try {
        const current = await api.instance(prior.instance_key);
        if (stillSelected()) {
          instance.value = current;
          emit('instance', current);
        }
      } catch {
        /* Keep the original error visible. */
      }
    } finally {
      if (lifecycle.valid()) submitting.value = false;
    }
  }
  function schedule() {
    if (!lifecycle.valid()) return;
    timer = setTimeout(async () => {
      if (!document.hidden && !dialog.value && !submitting.value) {
        if (props.instanceKey) await showInstance(props.instanceKey);
        else await load();
      }
      if (lifecycle.valid()) schedule();
    }, 15000);
  }
  function visibility() {
    if (document.hidden && timer) {
      clearTimeout(timer);
      timer = null;
    } else if (!document.hidden && !timer && lifecycle.valid()) {
      if (!dialog.value && !submitting.value) load();
      schedule();
    }
  }
  if (lifecycle.valid()) {
    document.addEventListener('visibilitychange', visibility);
    removeVisibilityListener = () =>
      document.removeEventListener('visibilitychange', visibility);
  }
  onBeforeUnmount(() =>
    document.removeEventListener('visibilitychange', visibility)
  );
  if (!props.instanceKey) load();
  watch(
    () => props.instanceKey,
    (key) => {
      if (key) showInstance(key);
    },
    { immediate: true }
  );
  schedule();
</script>

<template>
  <section
    v-if="pageActive"
    class="workflow-runtime"
    aria-label="工作流运行中心"
  >
    <h1>流程运行中心</h1>
    <el-alert
      v-if="error"
      :title="error"
      type="error"
      :closable="false"
      role="alert"
    />
    <el-alert v-if="notice" :title="notice" type="info" :closable="false" />
    <template v-if="!props.instanceKey">
      <div class="toolbar">
        <el-select
          v-model="filter"
          aria-label="任务列表范围"
          @change="
            page = 1;
            load();
          "
          ><el-option
            v-for="item in filterOptions"
            :key="item.value"
            :label="item.label"
            :value="item.value"
        /></el-select>
        <el-button :loading="loading" @click="load">刷新当前列表</el-button>
      </div>
      <el-table
        v-loading="loading"
        :data="tasks"
        empty-text="当前范围没有工作项"
      >
        <el-table-column prop="label" label="任务" min-width="160" />
        <el-table-column prop="status" label="状态" width="110" />
        <el-table-column label="当前办理人" min-width="130"
          ><template #default="{ row }">{{
            row.assignee_member_id || '尚未领取'
          }}</template></el-table-column
        >
        <el-table-column label="任务归属人" min-width="130"
          ><template #default="{ row }">{{
            row.owner_member_id || '未分配'
          }}</template></el-table-column
        >
        <el-table-column label="操作" width="120"
          ><template #default="{ row }"
            ><el-button link @click="showInstance(row.instance_key)"
              >查看流程</el-button
            ></template
          ></el-table-column
        >
      </el-table>
      <el-pagination
        v-model:current-page="page"
        :page-size="20"
        :total="count"
        layout="prev, pager, next, total"
        @current-change="load"
      />
    </template>
    <section v-if="instance" aria-label="流程详情" class="detail">
      <h2>流程 {{ instance.instance_key }}</h2>
      <p
        >当前状态：{{ instance.status }}；流程结果：{{
          instance.result_code || '尚未确定'
        }}</p
      >
      <el-alert
        v-if="instance.blocker"
        :title="instance.blocker.message"
        type="warning"
        :closable="false"
      />
      <p>送审版本：{{ instance.subject_revision_key }}</p>

      <slot name="business-collaboration" :instance="instance" />

      <el-timeline
        ><el-timeline-item
          v-for="node in instance.active_nodes"
          :key="node.node_execution_key"
          >{{ node.label }} — {{ node.status }}</el-timeline-item
        ></el-timeline
      >
      <div
        v-for="task in instance.own_work_items"
        :key="task.work_item_key"
        class="work-item"
      >
        <h3>{{ task.label }}</h3>
        <p
          >归属人：{{ task.owner_member_id || '未分配' }}；当前办理人：{{
            task.assignee_member_id || '待领取'
          }}；本人关系：{{ task.relations.join('、') || '无' }}</p
        >
        <p v-if="task.decision">已记录意见：{{ task.decision }}</p>
        <el-button
          v-for="entry in visibleTaskActions(task)"
          :key="entry"
          :disabled="
            submitting ||
            (!formProvider && ['complete', 'resubmit'].includes(entry))
          "
          @click="openAction(entry, task)"
          >{{ TASK_ACTION_LABELS[entry] }}</el-button
        >
      </div>
      <p>办理表单由所属业务页面提供。保存业务草稿与完成工作项是不同操作。</p>
      <el-alert
        v-if="
          !formProvider &&
          instance.own_work_items.some((task) =>
            task.available_actions.some((action) =>
              ['complete', 'resubmit'].includes(action)
            )
          )
        "
        title="当前入口未登记业务表单，完成办理和重新送审已禁用。请使用所属业务模块的办理入口保存/确认材料。"
        type="warning"
        :closable="false"
      />
      <el-button
        v-for="entry in instanceActions"
        :key="entry"
        :disabled="submitting"
        @click="openAction(entry)"
        >{{ INSTANCE_ACTION_LABELS[entry] }}</el-button
      >
      <h3>处理历史</h3
      ><el-timeline
        ><el-timeline-item
          v-for="event in events"
          :key="event.sequence_no"
          :timestamp="event.occurred_at"
          ><span>{{ event.event_key }} {{ event.actor_member_id || '' }}</span
          ><p class="safe-text">{{ event.comment }}</p>
          <ul v-if="event.attachments.length">
            <li
              v-for="attachment in event.attachments"
              :key="attachment.file_key"
            >
              <span class="safe-text">{{ attachment.name }}</span>
              <span>（历史附件，{{ attachment.size_bytes }} 字节）</span>
              <el-button
                :disabled="attachmentBusy"
                @click="
                  accessAttachment(attachment, event.sequence_no, 'preview')
                "
                >查看</el-button
              >
              <el-button
                :disabled="attachmentBusy"
                @click="
                  accessAttachment(
                    attachment,
                    event.sequence_no,
                    'download_original'
                  )
                "
                >下载历史原件</el-button
              >
              <el-button
                :disabled="attachmentBusy"
                @click="
                  accessAttachment(attachment, event.sequence_no, 'manage')
                "
                >管理当前文件</el-button
              >
            </li>
          </ul></el-timeline-item
        ></el-timeline
      >
    </section>
    <el-dialog
      :model-value="attachmentSurface !== null"
      title="历史附件"
      width="min(92vw, 900px)"
      destroy-on-close
      @close="closeAttachment"
    >
      <component
        v-if="attachmentSurface"
        :is="attachmentSurface.viewer"
        :content="attachmentSurface.content"
        :capabilities="attachmentSurface.capabilities"
        :callbacks="attachmentSurface.callbacks"
        :registry="attachmentSurface.registry"
        :context-key="attachmentSurface.contextKey"
        :allowed-operations="attachmentSurface.operations"
        @error="
          closeAttachment();
          error = '受控文件访问失败，请重新核对当前权限。';
        "
      />
    </el-dialog>
    <el-dialog
      v-model="dialog"
      :title="TASK_ACTION_LABELS[action] || INSTANCE_ACTION_LABELS[action]"
      width="min(92vw, 560px)"
      :close-on-click-modal="false"
    >
      <div
        v-if="error"
        ref="actionError"
        role="alert"
        tabindex="-1"
        class="action-error"
      >
        <el-alert :title="error" type="error" :closable="false" />
      </div>
      <p v-if="instance"
        >正在处理送审版本
        {{ instance.subject_revision_key }}，不修改已送审材料。</p
      >
      <p v-if="action === 'terminate'"
        >终止不会撤销已经成立的业务效果；正在执行或结果未知的动作须先核对。</p
      >
      <p v-if="action === 'resubmit' || action === 'complete'"
        >提交时由所属业务模块打开保存/确认表单，并返回材料版本、业务输入与附件。未登记接口时不能提交。</p
      >
      <template v-if="action === 'reassign' && instance">
        <el-select v-model="reassignTaskKey" aria-label="重分配工作项"
          ><el-option
            v-for="item in instance.reassign_work_items"
            :key="item.work_item_key"
            :label="`${item.label} (${item.work_item_key})`"
            :value="item.work_item_key"
        /></el-select>
        <el-input
          v-model="newAssignee"
          inputmode="numeric"
          aria-label="新办理人成员 ID"
          placeholder="新办理人成员 ID（服务端核对原责任来源、权限与职责分离）"
        />
      </template>
      <el-input
        v-model="comment"
        type="textarea"
        :maxlength="2000"
        :rows="4"
        :aria-label="
          requiresReason(action)
            ? '操作原因（必填）'
            : '处理意见（要求由当前节点校验）'
        "
        :placeholder="
          requiresReason(action)
            ? '请填写原因（必填）'
            : '处理意见（要求由当前节点校验）'
        "
      />
      <template #footer
        ><el-button :disabled="submitting" @click="dialog = false"
          >取消</el-button
        ><el-button type="primary" :loading="submitting" @click="submit"
          >确认提交</el-button
        ></template
      >
    </el-dialog>
  </section>
  <el-alert
    v-else
    title="身份已变化，请重新进入工作流运行中心。"
    type="info"
    :closable="false"
    role="status"
  />
</template>

<style scoped>
  .workflow-runtime {
    padding: 20px;
    min-width: 0;
    overflow-wrap: anywhere;
  }
  .action-error {
    margin-bottom: 12px;
    overflow-wrap: anywhere;
  }
  .toolbar {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    margin: 16px 0;
  }
  .detail,
  .work-item {
    padding: 16px;
    margin-top: 16px;
    border: 1px solid var(--el-border-color);
    border-radius: 8px;
  }
  .safe-text {
    white-space: pre-wrap;
    overflow-wrap: anywhere;
  }
  @media (max-width: 600px) {
    .workflow-runtime {
      padding: 10px;
    }
    .work-item .el-button {
      margin: 4px;
    }
  }
</style>
