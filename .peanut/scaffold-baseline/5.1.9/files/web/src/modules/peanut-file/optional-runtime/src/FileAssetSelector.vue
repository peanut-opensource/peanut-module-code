<script setup lang="ts">
  import { EmptyState } from '@peanut-admin/ui-vue';
  import { ElButton } from 'element-plus';
  import type { AssetCandidate } from './contracts';

  withDefaults(
    defineProps<{
      items: readonly AssetCandidate[];
      selectedFileKey?: string | null;
      loading?: boolean;
      error?: string | null;
      disabled?: boolean;
    }>(),
    {
      selectedFileKey: null,
      loading: false,
      error: null,
      disabled: false,
    }
  );

  const emit = defineEmits<{
    select: [asset: AssetCandidate];
    retry: [];
  }>();
</script>

<template>
  <section class="asset-selector" aria-labelledby="asset-selector-title">
    <header class="asset-selector__header">
      <h2 id="asset-selector-title"> 图片素材 </h2>
      <ElButton :loading="loading" :disabled="disabled" @click="emit('retry')">
        刷新
      </ElButton>
    </header>
    <div v-if="error" class="asset-selector__error" role="alert">
      <p>{{ error }}</p>
    </div>
    <div v-else-if="loading" role="status" class="asset-selector__status">
      正在加载图片素材…
    </div>
    <EmptyState
      v-else-if="items.length === 0"
      title="暂无图片素材"
      message="请先上传图片，再选择素材。"
    />
    <ul v-else class="asset-selector__grid" aria-label="可选图片素材">
      <li
        v-for="asset in items"
        :key="asset.fileKey"
        class="asset-selector__item"
      >
        <button
          type="button"
          class="asset-selector__choice"
          :class="{ 'is-selected': selectedFileKey === asset.fileKey }"
          :aria-pressed="selectedFileKey === asset.fileKey"
          :disabled="disabled"
          @click="emit('select', asset)"
        >
          <img
            v-if="asset.previewUri"
            :src="asset.previewUri"
            :alt="asset.originalName"
            class="asset-selector__preview"
            loading="lazy"
            decoding="async"
            referrerpolicy="no-referrer"
          />
          <span v-else class="asset-selector__placeholder" aria-hidden="true"
            >图片</span
          >
          <span class="asset-selector__name">{{ asset.originalName }}</span>
          <span class="asset-selector__meta">
            {{
              asset.width !== null && asset.height !== null
                ? asset.width + ' x ' + asset.height
                : asset.mediaType
            }}
          </span>
          <span v-if="asset.variants.length" class="asset-selector__meta">
            {{ asset.variants.length }} 个可用尺寸
          </span>
        </button>
      </li>
    </ul>
  </section>
</template>

<style scoped>
  .asset-selector {
    min-width: 0;
  }
  .asset-selector__header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 12px;
  }
  .asset-selector__header h2 {
    margin: 0;
    font-size: 16px;
    letter-spacing: 0;
  }
  .asset-selector__status,
  .asset-selector__error {
    padding: 16px 0;
  }
  .asset-selector__error {
    color: var(--el-color-danger);
  }
  .asset-selector__grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(144px, 1fr));
    gap: 12px;
    padding: 0;
    margin: 0;
    list-style: none;
  }
  .asset-selector__item {
    min-width: 0;
  }
  .asset-selector__choice {
    width: 100%;
    padding: 8px;
    border: 1px solid var(--el-border-color);
    background: var(--el-bg-color);
    color: inherit;
    text-align: left;
    cursor: pointer;
  }
  .asset-selector__choice.is-selected {
    border-color: var(--el-color-primary);
    box-shadow: 0 0 0 1px var(--el-color-primary) inset;
  }
  .asset-selector__choice:disabled {
    cursor: not-allowed;
    opacity: 0.65;
  }
  .asset-selector__preview,
  .asset-selector__placeholder {
    display: flex;
    width: 100%;
    aspect-ratio: 1;
    object-fit: cover;
    align-items: center;
    justify-content: center;
    background: var(--el-fill-color-light);
  }
  .asset-selector__placeholder {
    color: var(--el-text-color-secondary);
    font-size: 13px;
  }
  .asset-selector__name,
  .asset-selector__meta {
    display: block;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
  .asset-selector__name {
    margin-top: 8px;
    font-weight: 600;
  }
  .asset-selector__meta {
    margin-top: 2px;
    color: var(--el-text-color-secondary);
    font-size: 12px;
  }
</style>
