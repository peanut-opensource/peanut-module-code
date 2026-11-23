<template>
  <el-tree-select
    :model-value="modelValue"
    :data="nodes"
    node-key="value"
    :disabled="disabled || !allowed"
    :loading="loading"
    check-strictly
    clearable
    @visible-change="onVisible"
    @update:model-value="$emit('update:modelValue', $event)"
  />
</template>

<script setup lang="ts">
  import { ref, watch } from 'vue';
  import useCrudTab from '@/hooks/useCrudTab';

  type Value = string | number;
  interface Node {
    value: Value;
    label: string;
    children?: Node[];
    disabled?: boolean;
  }
  const props = defineProps<{
    modelValue?: Value;
    allowed: boolean;
    disabled?: boolean;
    load: (signal: AbortSignal) => Promise<Node[]>;
  }>();
  const emit = defineEmits<{
    (event: 'update:modelValue', value: Value | undefined): void;
    (event: 'error', error: unknown): void;
  }>();
  const nodes = ref<Node[]>([]);
  const loading = ref(false);
  let request: AbortController | undefined;
  const cancel = () => {
    request?.abort();
    request = undefined;
    loading.value = false;
  };
  const current = useCrudTab(cancel);
  watch(
    () => props.allowed,
    (allowed) => {
      if (!allowed) {
        cancel();
        nodes.value = [];
      }
    }
  );
  const onVisible = async (visible: boolean) => {
    if (!visible || !props.allowed || !current.value || request) return;
    const controller = new AbortController();
    request = controller;
    loading.value = true;
    try {
      const result = await props.load(controller.signal);
      if (!controller.signal.aborted) nodes.value = result;
    } catch (error) {
      if (!controller.signal.aborted) emit('error', error);
    } finally {
      if (request === controller) {
        request = undefined;
        loading.value = false;
      }
    }
  };
</script>
