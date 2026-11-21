<template>
  <div>
    <div
      v-for="(item, index) in modelValue"
      :key="itemKey(item)"
      class="dynamic-form-list__row"
    >
      <slot :item="item" :index="index" />
      <el-button :disabled="disabled" @click="remove(index)">删除</el-button>
    </div>
    <el-button :disabled="disabled" @click="add">添加</el-button>
  </div>
</template>

<script setup lang="ts" generic="T extends object">
  const props = defineProps<{
    modelValue: T[];
    createItem: () => T;
    itemKey: (item: T) => string | number;
    disabled?: boolean;
  }>();
  const emit = defineEmits<{
    (event: 'update:modelValue', value: T[]): void;
  }>();
  const add = () =>
    emit('update:modelValue', [...props.modelValue, props.createItem()]);
  const remove = (index: number) =>
    emit(
      'update:modelValue',
      props.modelValue.filter((_, position) => position !== index)
    );
</script>

<style scoped>
  .dynamic-form-list__row {
    display: flex;
    align-items: start;
    gap: 8px;
    margin-bottom: 8px;
  }
</style>
