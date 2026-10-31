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
      <button class="search-btn" size="mini" @click="doSearch">搜索</button>
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

    <view v-if="!searched && hotError" class="empty" role="alert">
      <text>热门搜索加载失败</text>
      <button size="mini" @click="loadHotSearch">重试</button>
    </view>
    <view v-if="searched" class="result-list">
      <view class="section-title">“{{ searchKeyword }}”的搜索结果</view>
      <view v-if="loading" class="empty" role="status">正在搜索…</view>
      <view v-else-if="searchError" class="empty" role="alert">
        <text>{{ searchError }}</text>
        <button size="mini" @click="loadResults(requestedPage)">重试</button>
      </view>
      <view v-else-if="results.length === 0" class="empty">未找到相关资讯</view>
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
      <view v-if="total > pageSize" class="pagination">
        <button
          size="mini"
          :disabled="loading || pageNo <= 1"
          @click="loadResults(pageNo - 1)"
          >上一页</button
        >
        <text>第 {{ pageNo }} / {{ Math.ceil(total / pageSize) }} 页</text>
        <button
          size="mini"
          :disabled="loading || pageNo * pageSize >= total"
          @click="loadResults(pageNo + 1)"
          >下一页</button
        >
      </view>
    </view>
  </view>
</template>

<script setup lang="ts">
  import { onScopeDispose, ref } from 'vue';
  import { onHide, onShow, onUnload } from '@dcloudio/uni-app';
  import {
    getHotSearch,
    getArticleLists,
  } from '@/modules/peanut-article/api/news';
  import type { Article } from '@/modules/peanut-article/api/news';

  const keyword = ref('');
  const searched = ref(false);
  const results = ref<Article[]>([]);
  const hotList = ref<Array<{ id: number; name: string }>>([]);
  const searchKeyword = ref('');
  const loading = ref(false);
  const searchError = ref('');
  const hotError = ref(false);
  const pageNo = ref(1);
  const pageSize = 15;
  const total = ref(0);
  const requestedPage = ref(1);
  let searchGeneration = 0;
  let hotGeneration = 0;
  let hotLoaded = false;
  let interrupted = false;
  let disposed = false;

  function invalidate() {
    interrupted = loading.value;
    searchGeneration += 1;
    hotGeneration += 1;
    loading.value = false;
  }

  function dispose() {
    disposed = true;
    invalidate();
  }

  onHide(invalidate);
  onUnload(dispose);
  onScopeDispose(dispose);
  onShow(() => {
    if (disposed) return;
    if (!hotLoaded) loadHotSearch();
    if (interrupted) loadResults(requestedPage.value);
  });

  async function loadHotSearch() {
    if (disposed) return;
    const generation = ++hotGeneration;
    hotError.value = false;
    try {
      const data = await getHotSearch();
      if (generation !== hotGeneration) return;
      if (data.status === 1) hotList.value = data.data;
      hotLoaded = true;
    } catch {
      if (generation === hotGeneration) hotError.value = true;
    }
  }

  function doSearch() {
    const value = keyword.value.trim();
    if (!value || disposed) return;
    searchKeyword.value = value;
    searched.value = true;
    results.value = [];
    total.value = 0;
    pageNo.value = 1;
    return loadResults(1);
  }

  async function loadResults(page: number) {
    if (disposed) return;
    const generation = ++searchGeneration;
    requestedPage.value = page;
    loading.value = true;
    interrupted = false;
    searchError.value = '';
    try {
      const data = await getArticleLists({
        keyword: searchKeyword.value,
        pageNo: page,
        pageSize,
      });
      if (generation !== searchGeneration) return;
      results.value = data.lists;
      total.value = data.count;
      pageNo.value = data.pageNo;
    } catch {
      if (generation === searchGeneration)
        searchError.value = '搜索失败，请重试';
    } finally {
      if (generation === searchGeneration) loading.value = false;
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
  .pagination {
    display: flex;
    align-items: center;
    gap: 16rpx;
    font-size: 24rpx;
    padding: 24rpx 0;
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
