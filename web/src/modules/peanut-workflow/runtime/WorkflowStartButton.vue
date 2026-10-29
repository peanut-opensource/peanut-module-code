<script setup lang="ts">
  import { ref } from 'vue';
  import type { StartInput } from '../api';
  import { createHostWorkflowApi } from '../host-api';
  import type { WorkflowInstance } from '../types';
  import { useWorkflowLifecycle } from './lifecycle';

  const props = defineProps<{
    input: StartInput;
    availableActions: readonly string[];
  }>();
  const emit = defineEmits<{
    (event: 'started', instance: WorkflowInstance): void;
  }>();
  const api = createHostWorkflowApi();
  const busy = ref(false);
  const error = ref('');
  const lifecycle = useWorkflowLifecycle(() => {
    api.dispose();
    error.value = '';
    busy.value = false;
  });
  async function start() {
    if (
      busy.value ||
      !props.availableActions.includes('start') ||
      !lifecycle.valid()
    )
      return;
    busy.value = true;
    error.value = '';
    try {
      const summary = await api.definitionSummary(
        props.input.module_key,
        props.input.workflow_key,
        props.input.subject_type,
        props.input.subject_key
      );
      if (!lifecycle.valid()) return;
      const instance = await api.start(
        { ...props.input, expected_revision: summary.draft_revision },
        crypto.randomUUID()
      );
      if (lifecycle.valid()) emit('started', instance);
    } catch (cause) {
      if (lifecycle.valid())
        error.value = cause instanceof Error ? cause.message : '发起失败';
    } finally {
      if (lifecycle.valid()) busy.value = false;
    }
  }
</script>

<template>
  <div
    ><el-button
      v-if="availableActions.includes('start')"
      type="primary"
      :loading="busy"
      @click="start"
      >送审已确认版本</el-button
    ><el-alert v-if="error" :title="error" type="error" :closable="false"
  /></div>
</template>
