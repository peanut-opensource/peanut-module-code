<template>
  <footer class="footer">
    © {{ new Date().getFullYear() }} {{ brandStore.website.copyright }}
  </footer>
</template>

<script lang="ts" setup>
  import { useBrandStore } from '@/store';

  const brandStore = useBrandStore();
</script>

<style lang="less" scoped>
  .footer {
    display: flex;
    align-items: center;
    justify-content: center;
    height: 40px;
    color: var(--el-text-color-secondary);
    text-align: center;
  }
</style>
