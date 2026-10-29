<script setup lang="ts">
  import { onBeforeUnmount, onMounted } from 'vue';
  import { useUserStore } from '@/store';
  import FileAssetSelector from './FileAssetSelector.vue';
  import {
    createFileAssetRuntime,
    FILE_ASSET_READ_PERMISSION,
  } from './runtime';

  const user = useUserStore();
  const runtime = createFileAssetRuntime({
    canRead: () => user.permissions.includes(FILE_ASSET_READ_PERMISSION),
  });
  const { state } = runtime;

  onMounted(() => runtime.load());
  onBeforeUnmount(runtime.dispose);
</script>

<template>
  <section class="file-asset-page">
    <header>
      <h1>图片素材</h1>
      <p>浏览已上传的图片并选择需要使用的素材。</p>
    </header>
    <FileAssetSelector
      :items="state.items"
      :selected-file-key="state.selectedFileKey"
      :loading="state.loading"
      :error="state.error?.message ?? null"
      @select="runtime.select"
      @retry="runtime.load()"
    />
    <footer v-if="state.total > state.pageSize" class="file-asset-page__pager">
      <el-pagination
        :current-page="state.page"
        :page-size="state.pageSize"
        :total="state.total"
        layout="prev, pager, next"
        @current-change="runtime.load"
      />
    </footer>
  </section>
</template>

<style scoped>
  .file-asset-page {
    padding: 20px;
  }
  .file-asset-page header {
    margin-bottom: 20px;
  }
  .file-asset-page h1 {
    margin: 0;
  }
  .file-asset-page p {
    color: var(--color-text-3);
  }
  .file-asset-page__pager {
    display: flex;
    justify-content: flex-end;
    margin-top: 20px;
  }
</style>
