<template>
  <el-select
    :model-value="modelValue"
    :disabled="disabled"
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
  type Value = string | number;
  defineProps<{
    modelValue?: Value;
    disabled?: boolean;
    options: readonly { value: Value; label: string; disabled?: boolean }[];
  }>();
  defineEmits<{
    (event: 'update:modelValue', value: Value | undefined): void;
  }>();
</script>
