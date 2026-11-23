<template>
  <el-image-viewer
    v-if="modelValue && urls.length"
    :url-list="urls"
    :initial-index="initialIndex"
    @close="$emit('update:modelValue', false)"
  />
</template>

<script setup lang="ts">
  withDefaults(
    defineProps<{
      modelValue: boolean;
      urls: string[];
      initialIndex?: number;
    }>(),
    { initialIndex: 0 }
  );
  defineEmits<{ (event: 'update:modelValue', value: boolean): void }>();
</script>
