<template>
  <el-drawer
    :model-value="modelValue"
    :title="title"
    size="60%"
    @update:model-value="$emit('update:modelValue', $event)"
  >
    <div class="detail-actions">
      <el-button @click="previewVisible = true">打印</el-button>
    </div>
    <div>
      <slot />
      <el-table v-if="changes.length" :data="changes">
        <el-table-column prop="field" label="字段" />
        <el-table-column prop="before" label="修改前" />
        <el-table-column prop="after" label="修改后" />
      </el-table>
      <slot name="diff" />
      <el-collapse v-if="auditHistory?.length" class="detail-history">
        <el-collapse-item
          v-for="entry in auditHistory"
          :key="entry.id"
          :name="entry.id"
          :title="entry.occurred_at + ' UTC'"
        >
          <el-table :data="compareFields(entry.before, entry.after)">
            <el-table-column prop="field" label="字段" />
            <el-table-column prop="before" label="修改前" />
            <el-table-column prop="after" label="修改后" />
          </el-table>
        </el-collapse-item>
      </el-collapse>
    </div>
  </el-drawer>
  <PrintPreview v-if="previewVisible" v-model="previewVisible">
    <slot />
    <el-table v-if="changes.length" :data="changes">
      <el-table-column prop="field" label="字段" />
      <el-table-column prop="before" label="修改前" />
      <el-table-column prop="after" label="修改后" />
    </el-table>
    <slot name="diff" />
  </PrintPreview>
</template>

<script setup lang="ts">
  import { computed, ref, watch } from 'vue';
  import PrintPreview from './PrintPreview.vue';

  const props = defineProps<{
    modelValue: boolean;
    title: string;
    before?: Record<string, unknown>;
    after?: Record<string, unknown>;
    auditHistory?: readonly {
      id: string;
      occurred_at: string;
      before: Record<string, unknown>;
      after: Record<string, unknown>;
    }[];
  }>();
  const showValue = (value: unknown) =>
    typeof value === 'string' ? value : JSON.stringify(value) ?? '';
  const compareFields = (
    before: Record<string, unknown>,
    after: Record<string, unknown>
  ) => {
    const keys = new Set([...Object.keys(before), ...Object.keys(after)]);
    return [...keys]
      .map((field) => ({
        field,
        before: showValue(before[field]),
        after: showValue(after[field]),
      }))
      .filter((item) => item.before !== item.after);
  };
  const changes = computed(() =>
    props.before && props.after ? compareFields(props.before, props.after) : []
  );
  defineEmits<{ (event: 'update:modelValue', value: boolean): void }>();
  const previewVisible = ref(false);
  watch(
    () => props.modelValue,
    (visible) => {
      if (!visible) previewVisible.value = false;
    }
  );
</script>

<style scoped>
  .detail-history {
    margin-top: 16px;
  }
  .detail-actions {
    margin-bottom: 16px;
  }
</style>
