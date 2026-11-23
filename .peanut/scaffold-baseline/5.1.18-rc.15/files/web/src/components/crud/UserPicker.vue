<template>
  <RemoteSelect
    :model-value="modelValue"
    :allowed="allowed"
    :disabled="disabled"
    :multiple="multiple"
    :load="load"
    @update:model-value="$emit('update:modelValue', $event)"
    @error="$emit('error', $event)"
  />
</template>

<script setup lang="ts">
  import RemoteSelect from './RemoteSelect.vue';

  type Value = string | number;
  interface UserOption {
    value: Value;
    label: string;
    disabled?: boolean;
  }
  defineProps<{
    modelValue?: Value | Value[];
    allowed: boolean;
    disabled?: boolean;
    multiple?: boolean;
    load: (query: string, signal: AbortSignal) => Promise<UserOption[]>;
  }>();
  defineEmits<{
    (event: 'update:modelValue', value: Value | Value[] | undefined): void;
    (event: 'error', error: unknown): void;
  }>();
</script>
