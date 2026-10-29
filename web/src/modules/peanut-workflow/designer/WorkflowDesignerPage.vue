<script setup lang="ts">
  import { computed, onBeforeUnmount, ref, shallowRef } from 'vue';
  import { onBeforeRouteLeave } from 'vue-router';
  import { ElMessageBox } from 'element-plus';
  import { hasPermission } from '@/hooks/permission';
  import { useUserStore } from '@/store';
  import { getSessionSnapshot, SESSION_KEY } from '@/utils/auth';
  import { readGraph, readLayout } from '../api';
  import { createHostWorkflowApi } from '../host-api';
  import { WorkflowHttpError } from '../transport';
  import type {
    EditorDocument,
    NodeType,
    ValidationIssue,
    WorkflowDefinition,
    WorkflowTransition,
  } from '../types';
  import { useWorkflowLifecycle } from '../runtime/lifecycle';
  import { createEditorHistory, graphIssues, newNode } from './editor-state';
  import NodePanel from './NodePanel.vue';
  import WorkflowCanvas from './WorkflowCanvas.vue';

  const api = createHostWorkflowApi();
  const moduleKey = ref('');
  const workflowKey = ref('');
  const definition = shallowRef<WorkflowDefinition | null>(null);
  const definitionScope = ref('');
  const initial: EditorDocument = {
    graph: {
      contract_version: 2,
      scenario_contract_version: 1,
      subject_resource_key: '',
      subject_read_operation: '',
      subject_start_operation: '',
      start_permission_keys: [],
      required_capabilities: [],
      nodes: [newNode('start', 'start'), newNode('end', 'approved')],
      transitions: [],
    },
    layout: { nodes: [], viewport: null },
  };
  const history = createEditorHistory(initial);
  const state = shallowRef(history.document);
  const selectedKey = ref('start');
  const revision = ref<number | null>(null);
  const changed = ref(false);
  const canUndo = ref(false);
  const canRedo = ref(false);
  const step = ref(0);
  const mode = ref<'wizard' | 'canvas'>('wizard');
  const issues = shallowRef<ValidationIssue[]>([]);
  const error = ref('');
  const notice = ref('');
  const busy = ref(false);
  const pageActive = ref(true);
  const conflict = ref(false);
  const serverRevision = ref<number | null>(null);
  const previewSummary = ref('');
  const previewPeople = ref<string[]>([]);
  const user = useUserStore();
  const hostId = user.id;
  const previewInitiator = ref(
    typeof hostId === 'string' || Number.isSafeInteger(hostId)
      ? String(hostId)
      : ''
  );
  const previewDepartment = ref('');
  const previewSubjectType = ref('');
  const previewSubjectKey = ref('');
  const edgeFrom = ref('');
  const edgeTo = ref('');
  const identity =
    localStorage.getItem(SESSION_KEY) ||
    String(getSessionSnapshot().generation);
  const instanceId = ref(`workflow-${crypto.randomUUID()}`);
  const scopeKey = () =>
    `peanut.workflow.draft:${window.location.origin}:${
      import.meta.env.VITE_API_BASE_URL
    }:${user.tenantName}:${user.id}:${identity}:${moduleKey.value}:${
      workflowKey.value
    }`;
  const selected = computed(() =>
    state.value.graph.nodes.find((node) => node.key === selectedKey.value)
  );
  const allowedWrite = computed(
    () =>
      hasPermission('peanut.workflow.definition.write') &&
      !!moduleKey.value &&
      !!workflowKey.value
  );
  const allowedPublish = computed(() =>
    hasPermission('peanut.workflow.definition.publish')
  );
  let epoch = 0;
  let controller: AbortController | null = null;
  let removeBeforeUnloadListener: (() => void) | undefined;
  function sync() {
    state.value = history.document;
    changed.value = history.dirty;
    canUndo.value = history.canUndo;
    canRedo.value = history.canRedo;
  }
  const lifecycle = useWorkflowLifecycle(() => {
    pageActive.value = false;
    epoch += 1;
    history.replace(initial);
    sync();
    moduleKey.value = '';
    workflowKey.value = '';
    definition.value = null;
    definitionScope.value = '';
    revision.value = null;
    previewSummary.value = '';
    previewPeople.value = [];
    previewInitiator.value = '';
    previewDepartment.value = '';
    previewSubjectType.value = '';
    previewSubjectKey.value = '';
    issues.value = [];
    selectedKey.value = '';
    edgeFrom.value = '';
    edgeTo.value = '';
    error.value = '';
    notice.value = '';
    busy.value = false;
    conflict.value = false;
    serverRevision.value = null;
    removeBeforeUnloadListener?.();
    controller?.abort();
    controller = null;
    api.dispose();
  });
  function change(update: (draft: EditorDocument) => void) {
    if (!lifecycle.valid() || !allowedWrite.value || busy.value) return;
    history.update(update);
    sync();
    previewSummary.value = '';
    previewPeople.value = [];
    issues.value = graphIssues(state.value.graph);
  }
  function undo() {
    history.undo();
    sync();
    previewSummary.value = '';
  }
  function redo() {
    history.redo();
    sync();
    previewSummary.value = '';
  }
  function saveLocal() {
    if (!lifecycle.valid() || !moduleKey.value || !workflowKey.value) return;
    sessionStorage.setItem(
      scopeKey(),
      JSON.stringify({ document: state.value, revision: revision.value })
    );
    notice.value = '已暂存当前身份的本地草稿，尚未保存到服务器。';
  }
  const fail = (cause: unknown) => {
    error.value = cause instanceof Error ? cause.message : '请求失败';
  };
  function restoreLocal() {
    if (!lifecycle.valid()) return;
    const saved = sessionStorage.getItem(scopeKey());
    if (!saved) {
      notice.value = '当前身份和定义没有本地草稿。';
      return;
    }
    try {
      const value: unknown = JSON.parse(saved);
      if (typeof value !== 'object' || value === null || !('document' in value))
        throw new Error('Invalid local draft');
      const { document } = value;
      if (
        typeof document !== 'object' ||
        document === null ||
        !('graph' in document) ||
        !('layout' in document)
      )
        throw new Error('Invalid local document');
      const parsed = {
        graph: readGraph(document.graph),
        layout: readLayout(document.layout),
      };
      history.update((draft) => {
        draft.graph = parsed.graph;
        draft.layout = parsed.layout;
      });
      sync();
      conflict.value = true;
      notice.value =
        '已恢复本地内容。请先读取服务器修订并明确核对，不能覆盖较新草稿。';
    } catch (cause) {
      fail(cause);
    }
  }
  async function load() {
    if (
      busy.value ||
      !lifecycle.valid() ||
      !moduleKey.value ||
      !workflowKey.value
    )
      return;
    if (changed.value) {
      try {
        await ElMessageBox.confirm(
          '读取服务器将替换当前编辑内容。可先暂存；确定继续？',
          '未保存内容',
          { type: 'warning' }
        );
      } catch {
        return;
      }
    }
    if (busy.value || !lifecycle.valid()) return;
    busy.value = true;
    error.value = '';
    controller?.abort();
    controller = new AbortController();
    epoch += 1;
    const current = epoch;
    const requestedScope = `${moduleKey.value}:${workflowKey.value}`;
    try {
      const result = await api.definition(
        moduleKey.value,
        workflowKey.value,
        controller.signal
      );
      if (
        current !== epoch ||
        !lifecycle.valid() ||
        requestedScope !== `${moduleKey.value}:${workflowKey.value}`
      )
        return;
      definition.value = result;
      definitionScope.value = requestedScope;
      revision.value = result.draft_revision;
      history.replace({ graph: result.graph, layout: result.layout });
      sync();
      conflict.value = false;
      serverRevision.value = null;
      selectedKey.value = result.graph.nodes[0]?.key || '';
      instanceId.value = `workflow-${crypto.randomUUID()}`;
      previewSummary.value = '';
      previewPeople.value = [];
      issues.value = graphIssues(result.graph);
    } catch (cause) {
      if (current === epoch && lifecycle.valid()) fail(cause);
    } finally {
      if (current === epoch && lifecycle.valid()) busy.value = false;
    }
  }
  async function switchDefinition() {
    if (busy.value || !lifecycle.valid()) return;
    if (changed.value) {
      try {
        await ElMessageBox.confirm(
          '当前草稿未保存。请先暂存或确认放弃，然后切换定义。',
          '切换定义',
          { type: 'warning' }
        );
      } catch {
        return;
      }
    }
    if (busy.value || !lifecycle.valid()) return;
    epoch += 1;
    controller?.abort();
    definition.value = null;
    definitionScope.value = '';
    revision.value = null;
    history.replace(initial);
    sync();
    conflict.value = false;
    serverRevision.value = null;
    issues.value = [];
    previewPeople.value = [];
    previewSummary.value = '';
    selectedKey.value = 'start';
    step.value = 0;
    instanceId.value = `workflow-${crypto.randomUUID()}`;
    moduleKey.value = '';
    workflowKey.value = '';
  }
  async function save() {
    if (
      busy.value ||
      conflict.value ||
      !allowedWrite.value ||
      !lifecycle.valid()
    )
      return;
    const requestedModule = moduleKey.value;
    const requestedWorkflow = workflowKey.value;
    const requestedScope = `${requestedModule}:${requestedWorkflow}`;
    if (revision.value !== null && definitionScope.value !== requestedScope) {
      error.value = '定义已切换，请先读取其草稿修订';
      return;
    }
    epoch += 1;
    const current = epoch;
    const valid = () =>
      lifecycle.valid() &&
      current === epoch &&
      requestedScope === `${moduleKey.value}:${workflowKey.value}`;
    busy.value = true;
    error.value = '';
    try {
      const result = await api.save(
        requestedModule,
        requestedWorkflow,
        {
          ...state.value,
          layout: {
            ...state.value.layout,
            viewport: state.value.layout.viewport ?? { x: 0, y: 0, zoom: 1 },
          },
        },
        revision.value,
        crypto.randomUUID()
      );
      if (!valid()) return;
      revision.value = result.draft_revision;
      definitionScope.value = requestedScope;
      issues.value = result.issues;
      history.markSaved();
      sync();
      sessionStorage.removeItem(scopeKey());
      notice.value = '服务器草稿已保存；存在的问题需要在发布前修复。';
      // Refresh available actions only after the successful save.
      const resultDefinition = await api.definition(
        requestedModule,
        requestedWorkflow
      );
      if (valid()) definition.value = resultDefinition;
    } catch (cause) {
      if (!valid()) return;
      fail(cause);
      if (
        cause instanceof WorkflowHttpError &&
        cause.code === 'WORKFLOW_DEFINITION_CONFLICT'
      ) {
        conflict.value = true;
        try {
          const currentDefinition = await api.definition(
            requestedModule,
            requestedWorkflow
          );
          if (valid()) serverRevision.value = currentDefinition.draft_revision;
        } catch {
          /* Original conflict remains visible. */
        }
      }
    } finally {
      if (lifecycle.valid() && current === epoch) busy.value = false;
    }
  }
  async function check(simulate = false) {
    if (simulate && !/^[1-9]\d*$/.test(previewInitiator.value)) {
      error.value = '请明确填写模拟发起人的字符串成员 ID';
      return;
    }
    if (busy.value || !lifecycle.valid()) return;
    busy.value = true;
    error.value = '';
    try {
      if (simulate) {
        const result = await api.preview(
          moduleKey.value,
          workflowKey.value,
          state.value,
          {
            initiator_member_id: previewInitiator.value,
            business_department_key: previewDepartment.value || null,
            previous_actor_member_id: null,
            subject_type: previewSubjectType.value,
            subject_key: previewSubjectKey.value,
          }
        );
        if (lifecycle.valid()) {
          issues.value = result.issues;
          previewSummary.value = result.summary;
          previewPeople.value = result.participants;
        }
      } else {
        const result = await api.validate(
          moduleKey.value,
          workflowKey.value,
          state.value
        );
        if (lifecycle.valid()) issues.value = result;
      }
    } catch (cause) {
      if (lifecycle.valid()) fail(cause);
    } finally {
      if (lifecycle.valid()) busy.value = false;
    }
  }
  async function publish() {
    if (
      busy.value ||
      changed.value ||
      conflict.value ||
      revision.value === null ||
      !allowedPublish.value ||
      !lifecycle.valid()
    )
      return;
    busy.value = true;
    error.value = '';
    try {
      await api.publish(
        moduleKey.value,
        workflowKey.value,
        revision.value,
        crypto.randomUUID()
      );
      if (lifecycle.valid())
        notice.value = '新版本已发布；在途实例仍使用原固定版本。';
    } catch (cause) {
      if (lifecycle.valid()) fail(cause);
    } finally {
      if (lifecycle.valid()) busy.value = false;
    }
  }
  function add(type: NodeType) {
    const key = `${type}-${crypto.randomUUID()}`;
    change((draft) => {
      draft.graph.nodes.push(newNode(type, key));
      draft.layout.nodes.push({
        key,
        x: 200,
        y: 160 * draft.graph.nodes.length,
      });
    });
    selectedKey.value = key;
  }
  async function remove() {
    if (!selected.value) return;
    const { key } = selected.value;
    const links = state.value.graph.transitions.filter(
      (edge) => edge.from === key || edge.to === key
    ).length;
    try {
      await ElMessageBox.confirm(
        `删除此节点会同时删除 ${links} 条关联连线，可撤销。`,
        '删除节点',
        { type: 'warning' }
      );
    } catch {
      return;
    }
    change((draft) => {
      draft.graph.nodes = draft.graph.nodes.filter((node) => node.key !== key);
      draft.graph.transitions = draft.graph.transitions.filter(
        (edge) => edge.from !== key && edge.to !== key
      );
      draft.layout.nodes = draft.layout.nodes.filter(
        (node) => node.key !== key
      );
    });
    selectedKey.value = '';
  }
  function connect(from: string, to: string) {
    const source = state.value.graph.nodes.find((node) => node.key === from);
    const target = state.value.graph.nodes.find((node) => node.key === to);
    if (
      !source ||
      !target ||
      source.type === 'end' ||
      target.type === 'start' ||
      from === to
    ) {
      error.value = '不能连接此方向';
      return;
    }
    change((draft) =>
      draft.graph.transitions.push({
        key: `edge-${crypto.randomUUID()}`,
        from,
        to,
        action: source.type === 'approval' ? 'approve' : 'advance',
        operation: null,
        permission_keys: [],
        return_edge: false,
        max_traversals: null,
      })
    );
  }
  function edgeChange(
    key: string,
    field: keyof WorkflowTransition,
    value: unknown
  ) {
    change((draft) => {
      const edge = draft.graph.transitions.find((entry) => entry.key === key);
      if (!edge) return;
      if (field === 'action' && typeof value === 'string') edge.action = value;
      if (field === 'return_edge' && typeof value === 'boolean') {
        edge.return_edge = value;
        edge.max_traversals = value ? 10 : null;
      }
      if (field === 'max_traversals' && typeof value === 'number')
        edge.max_traversals = value;
    });
  }
  function beforeUnload(event: BeforeUnloadEvent) {
    if (changed.value && lifecycle.valid()) {
      event.preventDefault();
      event.returnValue = '';
    }
  }
  if (lifecycle.valid()) {
    window.addEventListener('beforeunload', beforeUnload);
    removeBeforeUnloadListener = () =>
      window.removeEventListener('beforeunload', beforeUnload);
  }
  onBeforeUnmount(() =>
    window.removeEventListener('beforeunload', beforeUnload)
  );
  onBeforeRouteLeave(async () => {
    if (!changed.value || !lifecycle.valid()) return true;
    try {
      await ElMessageBox.confirm('当前草稿尚未保存，确定离开？', '未保存草稿', {
        type: 'warning',
      });
      return true;
    } catch {
      return false;
    }
  });
</script>

<template>
  <section
    v-if="pageActive"
    class="workflow-designer"
    aria-label="工作流设计器"
  >
    <h1>流程设计</h1>
    <el-alert
      v-if="error"
      :title="error"
      type="error"
      :closable="false"
      role="alert"
    /><el-alert v-if="notice" :title="notice" type="info" :closable="false" />
    <el-alert
      v-if="conflict"
      :title="`修订冲突或本地恢复待核对。服务器修订：${
        serverRevision ?? '未读取'
      }；本地修订：${
        revision ?? '未创建'
      }。本地内容保留，请暂存后读取服务器并人工合并。`"
      type="warning"
      :closable="false"
    />
    <el-steps :active="step" finish-status="success"
      ><el-step title="场景与模板" /><el-step title="基本信息" /><el-step
        title="节点与处理人" /><el-step title="检查与模拟" /><el-step
        title="保存与发布"
    /></el-steps>
    <div class="step-nav"
      ><el-button :disabled="step === 0" @click="step--">上一步</el-button
      ><el-button :disabled="step === 4" @click="step++">下一步</el-button
      ><el-radio-group v-model="mode"
        ><el-radio-button label="wizard">向导</el-radio-button
        ><el-radio-button label="canvas">画布</el-radio-button></el-radio-group
      ></div
    >
    <el-form v-if="step <= 1" label-position="top" class="basics">
      <el-form-item label="业务模块键"
        ><el-input
          v-model="moduleKey"
          placeholder="例如 peanut.application"
          :disabled="busy || changed || definition !== null"
          aria-label="业务模块键" /></el-form-item
      ><el-form-item label="流程用途键"
        ><el-input
          v-model="workflowKey"
          placeholder="例如 approval"
          :disabled="busy || changed || definition !== null"
          aria-label="流程用途键"
      /></el-form-item>
      <el-button
        :loading="busy"
        :disabled="!moduleKey || !workflowKey"
        @click="load"
        >读取服务器定义</el-button
      >
      <el-button :disabled="busy" @click="switchDefinition">切换定义</el-button>
      <el-form-item label="业务资源合同键"
        ><el-input
          :model-value="state.graph.subject_resource_key"
          :disabled="!allowedWrite"
          @update:model-value="
            change((draft) => {
              draft.graph.subject_resource_key = $event;
            })
          "
      /></el-form-item>
      <el-form-item label="业务读取操作"
        ><el-input
          :model-value="state.graph.subject_read_operation"
          :disabled="!allowedWrite"
          @update:model-value="
            change((draft) => {
              draft.graph.subject_read_operation = $event;
            })
          "
      /></el-form-item>
      <el-form-item label="业务发起操作"
        ><el-input
          :model-value="state.graph.subject_start_operation"
          :disabled="!allowedWrite"
          @update:model-value="
            change((draft) => {
              draft.graph.subject_start_operation = $event;
            })
          "
      /></el-form-item>
      <el-form-item label="发起权限（逗号分隔）"
        ><el-input
          :model-value="state.graph.start_permission_keys.join(',')"
          :disabled="!allowedWrite"
          @update:model-value="
            change((draft) => {
              draft.graph.start_permission_keys = $event
                .split(',')
                .map((key: string) => key.trim())
                .filter(Boolean);
            })
          "
      /></el-form-item>
      <p
        >场景字段、组织绑定与业务动作必须来自正式登记，填写合同键不会授予业务权限。模板由所属业务模块提供。</p
      >
    </el-form>
    <div v-if="step === 2 || mode === 'canvas'" class="editor">
      <div class="graph-area">
        <div class="toolbar"
          ><el-button :disabled="!canUndo || busy" @click="undo">撤销</el-button
          ><el-button :disabled="!canRedo || busy" @click="redo">重做</el-button
          ><el-button
            v-for="type in (['approval', 'human_task', 'end'] as const)"
            :key="type"
            :disabled="!allowedWrite || busy"
            @click="add(type)"
            >添加{{
              type === 'approval'
                ? '审批'
                : type === 'human_task'
                ? '办理'
                : '结束'
            }}</el-button
          ><el-button
            :disabled="!selected || !allowedWrite || busy"
            type="danger"
            @click="remove"
            >删除节点</el-button
          ></div
        >
        <WorkflowCanvas
          v-if="mode === 'canvas'"
          :key="instanceId"
          :document="state"
          :instance-id="instanceId"
          :selected-key="selectedKey"
          :editable="allowedWrite && !busy"
          @select="selectedKey = $event"
          @move="
            (key, x, y) =>
              change((draft) => {
                const found = draft.layout.nodes.find(
                  (node) => node.key === key
                );
                if (found) {
                  found.x = x;
                  found.y = y;
                } else draft.layout.nodes.push({ key, x, y });
              })
          "
          @connect="connect"
          @viewport="
            change((draft) => {
              draft.layout.viewport = $event;
            })
          "
        />
        <div v-else class="node-list"
          ><el-button
            v-for="node in state.graph.nodes"
            :key="node.key"
            :type="selectedKey === node.key ? 'primary' : 'default'"
            @click="selectedKey = node.key"
            >{{ node.label }}（{{ node.type }}）</el-button
          ></div
        >
        <h3>连线与处理动作</h3
        ><div class="toolbar"
          ><el-select v-model="edgeFrom" placeholder="来源节点"
            ><el-option
              v-for="node in state.graph.nodes"
              :key="node.key"
              :label="node.label"
              :value="node.key" /></el-select
          ><el-select v-model="edgeTo" placeholder="目标节点"
            ><el-option
              v-for="node in state.graph.nodes"
              :key="node.key"
              :label="node.label"
              :value="node.key" /></el-select
          ><el-button
            :disabled="!allowedWrite || !edgeFrom || !edgeTo"
            @click="connect(edgeFrom, edgeTo)"
            >添加连线</el-button
          ></div
        >
        <div
          v-for="edge in state.graph.transitions"
          :key="edge.key"
          class="edge-row"
          ><span
            >{{
              state.graph.nodes.find((node) => node.key === edge.from)?.label
            }}
            →
            {{
              state.graph.nodes.find((node) => node.key === edge.to)?.label
            }}</span
          ><el-select
            :model-value="edge.action"
            :disabled="!allowedWrite"
            @update:model-value="edgeChange(edge.key, 'action', $event)"
            ><el-option
              v-for="action in [
                'advance',
                'approve',
                'reject',
                'complete',
                'return',
              ]"
              :key="action"
              :value="action"
              :label="action" /></el-select
          ><el-checkbox
            :model-value="edge.return_edge"
            :disabled="!allowedWrite"
            @update:model-value="edgeChange(edge.key, 'return_edge', $event)"
            >受限退回</el-checkbox
          ><el-input-number
            v-if="edge.return_edge"
            :model-value="edge.max_traversals || 10"
            :min="1"
            :max="10"
            @update:model-value="edgeChange(edge.key, 'max_traversals', $event)"
          /><el-button
            link
            :disabled="!allowedWrite"
            @click="
              change((draft) => {
                draft.graph.transitions = draft.graph.transitions.filter(
                  (item) => item.key !== edge.key
                );
              })
            "
            >删除连线</el-button
          ></div
        >
      </div>
      <aside v-if="selected" class="properties"
        ><NodePanel
          :node="selected"
          :disabled="!allowedWrite || busy"
          @change="
            change((draft) => {
              const at = draft.graph.nodes.findIndex(
                (node) => node.key === $event.key
              );
              if (at >= 0) draft.graph.nodes[at] = $event;
            })
          "
      /></aside>
    </div>
    <section v-if="step === 3"
      ><h2>检查与模拟</h2
      ><p
        >模拟只解释规则与测试人员解析，不执行库存、通知或外部效果。以下为显式模拟基准，不改变实际发起人。</p
      ><el-form label-position="top"
        ><el-form-item label="模拟发起人成员 ID"
          ><el-input
            v-model="previewInitiator"
            inputmode="numeric" /></el-form-item
        ><el-form-item label="模拟业务部门绑定"
          ><el-input v-model="previewDepartment" /></el-form-item
        ><el-form-item label="模拟对象类型"
          ><el-input v-model="previewSubjectType" /></el-form-item
        ><el-form-item label="模拟对象键"
          ><el-input v-model="previewSubjectKey" /></el-form-item></el-form
      ><el-button :loading="busy" @click="check(false)"
        >服务器完整校验</el-button
      ><el-button
        :loading="busy"
        :disabled="!previewSubjectType || !previewSubjectKey"
        @click="check(true)"
        >模拟预览</el-button
      ><p v-if="previewSummary">模拟：{{ previewSummary }}</p
      ><p v-if="previewPeople.length"
        >示例解析成员：{{ previewPeople.join('、') }}</p
      ></section
    >
    <ul v-if="issues.length" class="issues" aria-label="定义问题"
      ><li v-for="(issue, index) in issues" :key="index"
        ><el-button
          link
          @click="
            selectedKey = issue.node_key || '';
            step = 2;
          "
          >{{ issue.message }} {{ issue.node_key || '' }}
          {{ issue.field || '' }}</el-button
        ></li
      ></ul
    >
    <footer class="toolbar"
      ><span
        >草稿修订：{{ revision ?? '新建' }}；{{
          changed ? '有未保存修改' : '无未保存修改'
        }}</span
      ><el-button :disabled="busy" @click="saveLocal">暂存到本地</el-button
      ><el-button :disabled="busy" @click="restoreLocal">恢复本地暂存</el-button
      ><el-button
        type="primary"
        :loading="busy"
        :disabled="!allowedWrite || conflict || !moduleKey || !workflowKey"
        @click="save"
        >保存服务器草稿</el-button
      ><el-button
        v-if="allowedPublish"
        :loading="busy"
        :disabled="changed || conflict || revision === null"
        @click="publish"
        >发布已保存版本</el-button
      ></footer
    >
  </section>
  <el-alert
    v-else
    title="身份已变化，请重新进入工作流设计器。"
    type="info"
    :closable="false"
    role="status"
  />
</template>

<style scoped>
  .workflow-designer {
    padding: 20px;
  }
  .step-nav,
  .toolbar,
  .edge-row {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    align-items: center;
    margin: 16px 0;
  }
  .basics {
    max-width: 720px;
  }
  .editor {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 340px;
    gap: 16px;
  }
  .properties {
    max-height: 740px;
    border: 1px solid var(--el-border-color);
    border-radius: 6px;
    overflow: auto;
  }
  .node-list {
    display: flex;
    flex-direction: column;
    gap: 12px;
    padding: 16px;
    border: 1px solid var(--el-border-color);
  }
  .node-list .el-button {
    margin-left: 0;
    white-space: normal;
    min-height: 48px;
  }
  .edge-row .el-select {
    width: 140px;
  }
  .issues {
    color: var(--el-color-danger);
  }
  @media (max-width: 900px) {
    .editor {
      grid-template-columns: minmax(0, 1fr);
    }
    .properties {
      max-height: none;
    }
  }
</style>
