<template>
  <view class="detail-page">
    <view v-if="loading" class="page-state">加载中...</view>
    <view v-else-if="errorMessage" class="page-state">{{ errorMessage }}</view>
    <view v-if="article" class="content">
      <image :src="article.image" class="cover" mode="widthFix" />

      <view class="article-body">
        <view class="title">{{ article.title }}</view>
        <view class="meta">
          <text>{{ article.author }}</text>
          <text>{{ article.click }} 次浏览</text>
          <text>{{ article.create_time }}</text>
        </view>
        <!-- rich text content -->
        <rich-text :nodes="article.content" class="rich-content" />
      </view>
    </view>

    <view v-if="article" class="action-bar">
      <view class="action-item" @click="toggleCollect">
        <text>{{ article.collect ? '❤️' : '🤍' }}</text>
        <text>{{ article.collect ? '已收藏' : '收藏' }}</text>
      </view>
    </view>
  </view>
</template>

<script setup lang="ts">
  import { ref } from 'vue';
  import { onLoad, onUnload } from '@dcloudio/uni-app';
  import { positivePageId } from '@/utils/page-input';
  import {
    getArticleDetail,
    addCollect,
    cancelCollect,
  } from '@/modules/peanut-article/api/news';
  import type { ArticleDetail } from '@/modules/peanut-article/api/news';
  import { useUserStore } from '@/store/user';

  const userStore = useUserStore();
  const article = ref<ArticleDetail | null>(null);
  const loading = ref(true);
  const errorMessage = ref('');
  const collecting = ref(false);
  let requestRevision = 0;

  onLoad((options) => {
    try {
      void loadDetail(positivePageId(options?.id));
    } catch {
      loading.value = false;
      errorMessage.value = '资讯编号无效';
    }
  });
  onUnload(() => {
    requestRevision += 1;
  });

  async function loadDetail(id: number) {
    const revision = ++requestRevision;
    loading.value = true;
    errorMessage.value = '';
    try {
      const result = await getArticleDetail(id);
      if (revision === requestRevision) article.value = result;
    } catch {
      if (revision === requestRevision) {
        article.value = null;
        errorMessage.value = '加载失败，请返回后重试';
      }
    } finally {
      if (revision === requestRevision) loading.value = false;
    }
  }

  async function toggleCollect() {
    if (!userStore.isLoggedIn) {
      uni.navigateTo({ url: '/pages/login/login' });
      return;
    }
    if (!article.value || collecting.value) return;
    const current = article.value;
    const revision = requestRevision;
    collecting.value = true;
    try {
      if (current.collect) {
        await cancelCollect(current.id);
      } else {
        await addCollect(current.id);
      }
      if (revision === requestRevision && article.value?.id === current.id) {
        article.value.collect = !current.collect;
      }
    } catch {
      if (revision === requestRevision)
        uni.showToast({ title: '收藏操作失败，请重试', icon: 'none' });
    } finally {
      collecting.value = false;
    }
  }
</script>

<style scoped>
  .page-state {
    padding: 40rpx;
    text-align: center;
  }
  .detail-page {
    background: #fff;
    min-height: 100vh;
    padding-bottom: 100rpx;
  }
  .cover {
    width: 100%;
  }
  .article-body {
    padding: 30rpx;
  }
  .title {
    font-size: 36rpx;
    font-weight: 700;
    color: #333;
    line-height: 1.4;
  }
  .meta {
    display: flex;
    gap: 20rpx;
    font-size: 24rpx;
    color: #999;
    margin: 20rpx 0 30rpx;
  }
  .rich-content {
    font-size: 30rpx;
    line-height: 1.8;
    color: #444;
  }
  /* #ifdef H5 */
  /* Rich-text authors may supply fixed image dimensions; keep the page readable. */
  .rich-content :deep(img) {
    max-width: 100% !important;
    height: auto !important;
  }
  /* #endif */
  .action-bar {
    position: fixed;
    bottom: 0;
    left: 0;
    right: 0;
    background: #fff;
    border-top: 1rpx solid #eee;
    padding: 20rpx 40rpx;
    display: flex;
  }
  .action-item {
    display: flex;
    flex-direction: column;
    align-items: center;
    font-size: 24rpx;
    color: #666;
  }
</style>
