<template>
  <el-drawer
    :model-value="modelValue"
    :title="title"
    size="60%"
    @update:model-value="$emit('update:modelValue', $event)"
  >
    <div class="detail-actions"><el-button @click="print">打印</el-button></div>
    <div ref="content">
      <slot />
      <el-table v-if="changes.length" :data="changes">
        <el-table-column prop="field" label="字段" />
        <el-table-column prop="before" label="修改前" />
        <el-table-column prop="after" label="修改后" />
      </el-table>
      <slot name="diff" />
    </div>
  </el-drawer>
</template>

<script setup lang="ts">
  import { computed, ref } from 'vue';
  import printContent from './printContent';

  const props = defineProps<{
    modelValue: boolean;
    title: string;
    before?: Record<string, unknown>;
    after?: Record<string, unknown>;
  }>();
  const showValue = (value: unknown) =>
    typeof value === 'string' ? value : JSON.stringify(value) ?? '';
  const changes = computed(() => {
    if (!props.before || !props.after) return [];
    const keys = new Set([
      ...Object.keys(props.before),
      ...Object.keys(props.after),
    ]);
    return [...keys]
      .map((field) => ({
        field,
        before: showValue(props.before?.[field]),
        after: showValue(props.after?.[field]),
      }))
      .filter((item) => item.before !== item.after);
  });
  defineEmits<{ (event: 'update:modelValue', value: boolean): void }>();
  const content = ref<HTMLElement>();
  const print = () => {
    if (content.value) printContent(content.value);
  };
</script>

<style scoped>
  .detail-actions {
    margin-bottom: 16px;
  }
</style>
