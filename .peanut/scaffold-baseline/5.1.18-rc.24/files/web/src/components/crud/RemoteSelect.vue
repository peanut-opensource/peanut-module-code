<template>
  <el-select
    :model-value="modelValue"
    filterable
    remote
    :remote-method="search"
    :loading="loading"
    :disabled="!allowed || disabled"
    :multiple="multiple"
    clearable
    @update:model-value="$emit('update:modelValue', $event)"
  >
    <el-option
      v-for="item in options"
      :key="item.value"
      :label="item.label"
      :value="item.value"
      :disabled="item.disabled"
    />
  </el-select>
</template>

<script setup lang="ts">
  import { ref, watch } from 'vue';
  import useCrudTab from '@/hooks/useCrudTab';

  type Value = string | number;
  interface Option {
    value: Value;
    label: string;
    disabled?: boolean;
  }
  const props = defineProps<{
    modelValue?: Value | Value[];
    allowed: boolean;
    disabled?: boolean;
    multiple?: boolean;
    load: (query: string, signal: AbortSignal) => Promise<Option[]>;
  }>();
  const emit = defineEmits<{
    (event: 'update:modelValue', value: Value | Value[] | undefined): void;
    (event: 'error', error: unknown): void;
  }>();
  const options = ref<Option[]>([]);
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
        options.value = [];
      }
    }
  );
  const search = async (query: string) => {
    cancel();
    if (!props.allowed || !current.value) return;
    const controller = new AbortController();
    request = controller;
    loading.value = true;
    try {
      const result = await props.load(query, controller.signal);
      if (!controller.signal.aborted) options.value = result;
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
