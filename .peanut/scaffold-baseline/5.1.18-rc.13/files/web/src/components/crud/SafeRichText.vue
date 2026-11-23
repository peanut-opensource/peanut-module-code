<template><div v-html="safeHtml" /></template>

<script setup lang="ts">
  import { computed } from 'vue';
  import DOMPurify from 'dompurify';

  const props = defineProps<{ html: string }>();
  const safeHtml = computed(() => DOMPurify.sanitize(props.html));
</script>
