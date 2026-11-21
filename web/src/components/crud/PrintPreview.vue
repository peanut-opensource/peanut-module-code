<template>
  <el-dialog
    :model-value="modelValue"
    title="打印预览"
    @update:model-value="$emit('update:modelValue', $event)"
  >
    <el-alert v-if="printError" :title="printError" type="error" />
    <div ref="content"><slot /></div>
    <template #footer>
      <el-button :loading="printing" @click="print">打印</el-button>
    </template>
  </el-dialog>
</template>

<script setup lang="ts">
  import { ref } from 'vue';
  import printContent from './printContent';

  defineProps<{ modelValue: boolean }>();
  defineEmits<{ (event: 'update:modelValue', value: boolean): void }>();
  const content = ref<HTMLElement>();
  const printing = ref(false);
  const printError = ref('');
  const print = async () => {
    if (!content.value || printing.value) return;
    printing.value = true;
    printError.value = '';
    try {
      await printContent(content.value);
    } catch (error) {
      printError.value = error instanceof Error ? error.message : '打印失败';
    } finally {
      printing.value = false;
    }
  };
</script>
