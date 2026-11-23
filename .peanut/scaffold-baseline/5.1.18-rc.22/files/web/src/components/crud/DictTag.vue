<template
  ><el-tag>{{ label }}</el-tag></template
>

<script setup lang="ts">
  import { computed } from 'vue';

  const props = defineProps<{
    value: string | number;
    options: readonly { value: string | number; label: string }[];
  }>();
  const label = computed(
    () =>
      props.options.find((item) => item.value === props.value)?.label ??
      String(props.value)
  );
</script>
