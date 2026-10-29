<template>
  <view class="search-page">
    <view class="search-bar">
      <input
        v-model="keyword"
        placeholder="搜索资讯..."
        class="search-input"
        confirm-type="search"
        @confirm="doSearch"
      />
      <text class="search-btn" @click="doSearch">搜索</text>
    </view>

    <view v-if="!searched && hotList.length" class="hot-section">
      <view class="section-title">热门搜索</view>
      <view class="hot-tags">
        <view
          v-for="item in hotList"
          :key="item.id"
          class="hot-tag"
          @click="selectHot(item.name)"
        >
          {{ item.name }}
        </view>
      </view>
    </view>

    <view v-if="searched" class="result-list">
      <view v-if="results.length === 0" class="empty">未找到相关资讯</view>
      <view
        v-for="item in results"
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
  </view>
</template>

<script setup lang="ts">
  import { ref, onMounted } from 'vue';
  import {
    getHotSearch,
    getArticleLists,
  } from '@/modules/peanut-article/api/news';
  import type { Article } from '@/modules/peanut-article/api/news';

  const keyword = ref('');
  const searched = ref(false);
  const results = ref<Article[]>([]);
  const hotList = ref<Array<{ id: number; name: string }>>([]);

  onMounted(async () => {
    try {
      const data = await getHotSearch();
      if (data.status === 1) hotList.value = data.data;
    } catch (error) {
      console.error('Failed to load hot search:', error);
    }
  });

  async function doSearch() {
    if (!keyword.value.trim()) return;
    try {
      const data = await getArticleLists({ keyword: keyword.value });
      results.value = data.lists;
      searched.value = true;
    } catch (error) {
      console.error('Search failed:', error);
    }
  }

  function selectHot(name: string) {
    keyword.value = name;
    doSearch();
  }

  function goDetail(id: number) {
    uni.navigateTo({ url: `/pages/news_detail/news_detail?id=${id}` });
  }
</script>

<style scoped>
  .search-page {
    background: #f5f5f5;
    min-height: 100vh;
  }
  .search-bar {
    display: flex;
    align-items: center;
    background: #fff;
    padding: 20rpx 24rpx;
  }
  .search-input {
    flex: 1;
    background: #f5f5f5;
    height: 70rpx;
    border-radius: 35rpx;
    padding: 0 30rpx;
    font-size: 28rpx;
  }
  .search-btn {
    margin-left: 20rpx;
    color: #2979ff;
    font-size: 28rpx;
  }
  .hot-section {
    padding: 24rpx;
  }
  .section-title {
    font-size: 28rpx;
    font-weight: 600;
    color: #333;
    margin-bottom: 20rpx;
  }
  .hot-tags {
    display: flex;
    flex-wrap: wrap;
    gap: 16rpx;
  }
  .hot-tag {
    background: #fff;
    padding: 12rpx 30rpx;
    border-radius: 30rpx;
    font-size: 26rpx;
    color: #555;
  }
  .result-list {
    padding: 24rpx;
  }
  .empty {
    text-align: center;
    color: #999;
    padding: 80rpx 0;
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
