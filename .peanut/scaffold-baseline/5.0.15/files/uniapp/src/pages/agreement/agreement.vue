<template>
  <view class="page">
    <view
      class="policy-content"
      v-if="policy && (policy.title.trim() || policy.content.trim())"
    >
      <view class="policy-title">{{ policy.title }}</view>
      <rich-text :nodes="policy.content" class="policy-body" />
    </view>
    <view v-else-if="loading" class="loading">加载中...</view>
    <view v-else class="loading">{{ errorMessage || '暂未配置相关协议内容' }}</view>
  </view>
</template>

<script setup lang="ts">
  import { ref } from 'vue';
  import { onLoad, onUnload } from '@dcloudio/uni-app';
  import { getPolicy } from '@/api/index';
  import type { PolicyData } from '@/api/index';
  import { policyKind } from '@/utils/page-input';

  const policy = ref<PolicyData | null>(null);
  const loading = ref(true);
  const errorMessage = ref('');
  let requestRevision = 0;

  onLoad((options) => {
    try {
      void loadPolicy(policyKind(options?.type));
    } catch {
      loading.value = false;
      errorMessage.value = '协议类型无效';
    }
  });
  onUnload(() => {
    requestRevision += 1;
  });

  async function loadPolicy(type: 'privacy' | 'service') {
    const revision = ++requestRevision;
    loading.value = true;
    errorMessage.value = '';
    try {
      const result = await getPolicy(type);
      if (revision === requestRevision) policy.value = result;
    } catch {
      if (revision === requestRevision)
        errorMessage.value = '加载失败，请返回后重试';
    } finally {
      if (revision === requestRevision) loading.value = false;
    }
  }
</script>

<style scoped>
  .page {
    background: #fff;
    min-height: 100vh;
  }
  .loading {
    text-align: center;
    padding: 80rpx;
    color: #999;
  }
  .policy-content {
    padding: 40rpx;
  }
  .policy-title {
    font-size: 36rpx;
    font-weight: 700;
    color: #333;
    margin-bottom: 30rpx;
  }
  .policy-body {
    font-size: 28rpx;
    color: #555;
    line-height: 1.8;
  }
</style>
