<script setup lang="ts">
  import type { AssignmentKind, AssignmentRule } from '../types';
  import { ASSIGNMENT_LABELS } from '../types';
  const props = defineProps<{ modelValue: AssignmentRule[]; label: string }>();
  const emit = defineEmits<{
    (event: 'update:modelValue', value: AssignmentRule[]): void;
  }>();
  const kinds = Object.entries(ASSIGNMENT_LABELS);
  function change(
    index: number,
    field: 'kind' | 'key' | 'required' | 'department_scope',
    value: string | boolean | null
  ) {
    const next = props.modelValue.map((rule) => ({ ...rule }));
    const rule = next[index];
    if (!rule) return;
    if (field === 'kind') {
      rule.kind = value as AssignmentKind;
      rule.key = ['initiator', 'subject_owner', 'previous_actor'].includes(
        rule.kind
      )
        ? null
        : '';
    }
    if (field === 'key' && typeof value === 'string') rule.key = value;
    if (field === 'required' && typeof value === 'boolean')
      rule.required = value;
    if (
      field === 'department_scope' &&
      (typeof value === 'string' || value === null)
    )
      rule.department_scope = value || null;
    emit('update:modelValue', next);
  }
  function add() {
    emit('update:modelValue', [
      ...props.modelValue,
      { kind: 'member', key: '', required: true, department_scope: null },
    ]);
  }
  function remove(index: number) {
    emit(
      'update:modelValue',
      props.modelValue.filter((_, at) => at !== index)
    );
  }
</script>
<template>
  <fieldset class="assignment-rules nodrag nopan nowheel">
    <legend>{{ label }}</legend>
    <div v-for="(rule, index) in modelValue" :key="index" class="rule">
      <el-select
        :model-value="rule.kind"
        :aria-label="`${label}来源`"
        @update:model-value="change(index, 'kind', $event)"
        ><el-option
          v-for="[value, title] in kinds"
          :key="value"
          :value="value"
          :label="title"
      /></el-select>
      <el-input
        v-if="
          [
            'member',
            'role_members',
            'department_members',
            'department_leaders',
          ].includes(rule.kind)
        "
        :model-value="rule.key || ''"
        placeholder="已登记的成员/角色/部门绑定标识"
        :aria-label="`${label}绑定标识`"
        @update:model-value="change(index, 'key', $event)"
      />
      <el-input
        v-if="rule.kind === 'role_members'"
        :model-value="rule.department_scope || ''"
        placeholder="可选：部门范围绑定标识"
        aria-label="角色部门范围"
        @update:model-value="change(index, 'department_scope', $event)"
      />
      <el-checkbox
        :model-value="rule.required"
        @update:model-value="change(index, 'required', $event)"
        >必需来源（为空时阻塞）</el-checkbox
      >
      <el-button link type="danger" @click="remove(index)"
        >移除此来源</el-button
      >
    </div>
    <el-button size="small" @click="add">添加{{ label }}来源</el-button>
    <p
      >多个来源合并去重；部门成员只含直接成员。岗位、上级链和前序多人来源尚未支持。</p
    >
  </fieldset>
</template>
<style scoped>
  .assignment-rules {
    border: 1px solid var(--el-border-color);
    border-radius: 6px;
    margin: 12px 0;
    padding: 10px;
    min-width: 0;
  }
  .rule {
    display: grid;
    gap: 8px;
    padding-bottom: 12px;
  }
  p {
    font-size: 12px;
    color: var(--el-text-color-secondary);
  }
</style>
