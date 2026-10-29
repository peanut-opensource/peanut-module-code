<script setup lang="ts">
  import { computed } from 'vue';
  import type {
    AggregationPolicy,
    AllocationMode,
    AssignmentRule,
    WorkflowNode,
  } from '../types';
  import { AGGREGATION_LABELS, ALLOCATION_LABELS } from '../types';
  import { humanConfig } from './editor-state';
  import AssignmentRules from './AssignmentRules.vue';

  const props = defineProps<{ node: WorkflowNode; disabled?: boolean }>();
  const emit = defineEmits<{ (event: 'change', node: WorkflowNode): void }>();
  const human = computed(() => humanConfig(props.node));
  const showDepartmentLeaderApprovalGuidance = computed(
    () =>
      props.node.type === 'approval' &&
      human.value.allocation_mode === 'multi' &&
      human.value.aggregation_policy === 'all_approve' &&
      human.value.assignments.some((rule) => rule.kind === 'department_leaders')
  );
  function name(label: string) {
    emit('change', { ...props.node, label });
  }
  function config(field: string, value: unknown) {
    emit('change', {
      ...props.node,
      config: { ...props.node.config, [field]: value },
    });
  }
  function allocation(value: AllocationMode) {
    emit('change', {
      ...props.node,
      config: {
        ...props.node.config,
        allocation_mode: value,
        aggregation_policy: value === 'multi' ? 'all_approve' : null,
      },
    });
  }
  function aggregation(value: AggregationPolicy) {
    config('aggregation_policy', value);
  }
  function rules(field: string, value: AssignmentRule[]) {
    config(field, value);
  }
</script>

<template>
  <section class="node-panel nodrag nopan nowheel" aria-label="节点属性">
    <h3>节点配置</h3><p>节点标识：{{ node.key }}；类型：{{ node.type }}</p>
    <el-form label-position="top" :disabled="disabled">
      <el-form-item label="节点名称"
        ><el-input
          :model-value="node.label"
          maxlength="120"
          aria-label="节点名称"
          @update:model-value="name"
      /></el-form-item>
      <template v-if="node.type === 'approval' || node.type === 'human_task'">
        <el-alert
          v-if="
            !['direct', 'claim', 'multi'].includes(human.allocation_mode) ||
            (human.aggregation_policy !== null &&
              !['all_approve', 'any_approve'].includes(
                human.aggregation_policy
              ))
          "
          title="CAPABILITY_UNSUPPORTED：当前节点包含未实现的分配或聚合策略，原始值已保留，不能发布。"
          type="error"
          :closable="false"
        />
        <el-form-item label="处理方式"
          ><el-select
            :model-value="human.allocation_mode"
            aria-label="分配模式"
            @update:model-value="allocation"
            ><el-option
              v-for="(title, value) in ALLOCATION_LABELS"
              :key="value"
              :label="title"
              :value="value"
              :disabled="
                node.type === 'human_task' && value === 'multi'
              " /></el-select
        ></el-form-item>
        <el-form-item
          v-if="human.allocation_mode === 'multi'"
          label="通过与不通过规则"
          ><el-select
            :model-value="human.aggregation_policy"
            aria-label="多人聚合策略"
            @update:model-value="aggregation"
            ><el-option
              v-for="(title, value) in AGGREGATION_LABELS"
              :key="value"
              :label="title"
              :value="value" /></el-select
        ></el-form-item>
        <el-alert
          v-if="showDepartmentLeaderApprovalGuidance"
          title="所有必需来源与资格校验通过后，若处理人来源合并去重后只有一名合格审批人，该成员同意即可满足本节点的全员同意条件，不必凑两人；必需来源为空或资格不满足时仍会阻塞。"
          type="info"
          :closable="false"
        />
        <el-alert
          v-if="human.allocation_mode === 'claim'"
          title="候选池只生成一个共享任务；领取后才能处理，不按候选人数计票。"
          type="info"
          :closable="false"
        />
        <AssignmentRules
          :model-value="human.assignments"
          label="处理人"
          @update:model-value="rules('assignments', $event)"
        />
        <AssignmentRules
          :model-value="human.participant_rules"
          label="参与人"
          @update:model-value="rules('participant_rules', $event)"
        />
        <AssignmentRules
          :model-value="human.watcher_rules"
          label="抄送人"
          @update:model-value="rules('watcher_rules', $event)"
        />
        <p>参与人不能表决或完成，抄送人只读；关系不自动扩大业务对象权限。</p>
        <el-checkbox
          :model-value="human.allow_self_approval"
          @update:model-value="config('allow_self_approval', $event)"
          >明确允许发起人自审</el-checkbox
        >
        <el-alert
          v-if="human.allow_self_approval"
          title="此配置取消默认自审排除，请确认场景职责分离要求。"
          type="warning"
          :closable="false"
        />
        <el-checkbox
          :model-value="human.opinion_required"
          @update:model-value="config('opinion_required', $event)"
          >同意时也必须填写意见</el-checkbox
        >
        <p>拒绝、退回和恢复操作始终要求原因。</p>
      </template>
      <el-form-item v-if="node.type === 'end'" label="流程结果"
        ><el-select
          :model-value="node.config.result_code"
          aria-label="结束结果"
          @update:model-value="config('result_code', $event)"
          ><el-option label="通过" value="approved" /><el-option
            label="不通过"
            value="rejected" /><el-option
            label="办理完成"
            value="completed" /></el-select
      ></el-form-item>
      <el-alert
        v-if="node.type === 'service_task' || node.type === 'exclusive_gateway'"
        title="该节点的动作与字段由业务场景登记；保留服务器配置，未登记能力不能发布。"
        type="info"
        :closable="false"
      />
    </el-form>
  </section>
</template>

<style scoped>
  .node-panel {
    padding: 12px;
    overflow-y: auto;
    height: 100%;
  }
  .el-select {
    width: 100%;
  }
  p {
    overflow-wrap: anywhere;
  }
</style>
