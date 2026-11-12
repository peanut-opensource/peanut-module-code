<template>
  <main class="installation-entry" aria-live="polite">
    <img src="/brand/logo.svg" alt="" width="40" height="40" />
    <h1>正在打开安装向导</h1>
    <p>如果没有自动跳转，请点击下方链接继续。</p>
    <a href="/install">打开安装向导 →</a>
  </main>
</template>

<script lang="ts" setup>
  import { onMounted } from 'vue';

  // A document navigation reaches the PHP-owned installer in Vite and Nginx.
  // Keep one wizard for direct visits and client-side installation guards.
  onMounted(() => window.location.replace('/install'));
</script>

<style scoped>
  .installation-entry {
    display: grid;
    justify-items: center;
    align-content: center;
    gap: 16px;
    min-height: 100vh;
    padding: 24px;
    color: #17243c;
    background: #f5f7fb;
  }
  h1 {
    margin: 0;
    font-size: 24px;
  }
  p {
    margin: 0;
    color: #69778d;
  }
  a {
    color: #2563eb;
  }
</style>
