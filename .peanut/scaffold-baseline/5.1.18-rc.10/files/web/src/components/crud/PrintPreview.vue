<template>
  <el-dialog
    :model-value="modelValue"
    title="打印预览"
    @update:model-value="$emit('update:modelValue', $event)"
  >
    <div ref="content"><slot /></div>
    <template #footer><el-button @click="print">打印</el-button></template>
  </el-dialog>
</template>

<script setup lang="ts">
  import { ref } from 'vue';
  import printContent from './printContent';

  defineProps<{ modelValue: boolean }>();
  defineEmits<{ (event: 'update:modelValue', value: boolean): void }>();
  const content = ref<HTMLElement>();
  const print = () => {
    if (content.value) printContent(content.value);
  };
</script>
