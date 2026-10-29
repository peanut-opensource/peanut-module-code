<template>
  <view class="page">
    <view v-if="articles.length === 0" class="empty">
      <text>暂无收藏</text>
    </view>
    <view
      v-for="item in articles"
      :key="item.id"
      class="article-item"
      @click="goDetail(item.id)"
    >
      <image :src="item.image" class="article-img" mode="aspectFill" />
      <view class="article-info">
        <view class="article-title">{{ item.title }}</view>
        <view class="article-meta">
          <text>{{ item.author }}</text>
          <text>{{ item.click }} 次浏览</text>
        </view>
      </view>
    </view>
  </view>
</template>

<script setup lang="ts">
  import { ref, onMounted } from 'vue';
  import { getCollectLists } from '@/modules/peanut-article/api/news';
  import { useUserStore } from '@/store/user';
  import type { ArticleCollection } from '@/modules/peanut-article/api/news';

  const userStore = useUserStore();
  const articles = ref<ArticleCollection[]>([]);

  onMounted(async () => {
    if (!userStore.isLoggedIn) {
      uni.reLaunch({ url: '/pages/login/login' });
      return;
    }

    try {
      const data = await getCollectLists();
      articles.value = data.lists;
    } catch (error) {
      console.error('Failed to load collections:', error);
    }
  });

  function goDetail(id: number) {
    uni.navigateTo({ url: `/pages/news_detail/news_detail?id=${id}` });
  }
</script>

<style scoped>
  .page {
    background: #f5f5f5;
    min-height: 100vh;
    padding: 24rpx;
  }
  .empty {
    text-align: center;
    color: #999;
    padding: 120rpx 0;
    font-size: 28rpx;
  }
  .article-item {
    display: flex;
    background: #fff;
    border-radius: 12rpx;
    margin-bottom: 20rpx;
    overflow: hidden;
  }
  .article-img {
    width: 200rpx;
    height: 160rpx;
    flex-shrink: 0;
  }
  .article-info {
    flex: 1;
    padding: 16rpx 20rpx;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
  }
  .article-title {
    font-size: 28rpx;
    color: #333;
    font-weight: 500;
  }
  .article-meta {
    display: flex;
    justify-content: space-between;
    font-size: 22rpx;
    color: #999;
  }
</style>
