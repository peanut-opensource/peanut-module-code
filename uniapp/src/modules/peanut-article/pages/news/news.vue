<template>
  <view class="news-page">
    <view v-if="loadingCategories" class="request-state" role="status"
      >正在加载分类…</view
    >
    <view v-else-if="categoriesError" class="request-state" role="alert">
      <text>{{ categoriesError }}</text>
      <button size="mini" @click="loadCategories">重新加载分类</button>
    </view>
    <view class="tabs">
      <view
        v-for="cate in categories"
        :key="cate.id"
        class="tab-item"
        :class="{ active: currentCateId === cate.id }"
        @click="switchCate(cate.id)"
      >
        {{ cate.name }}
      </view>
    </view>

    <scroll-view scroll-y class="article-list">
      <view v-if="loadingArticles" class="request-state" role="status"
        >正在加载资讯…</view
      >
      <view v-else-if="articlesError" class="request-state" role="alert">
        <text>{{ articlesError }}</text>
        <button size="mini" @click="loadArticles">重新加载资讯</button>
      </view>
      <view v-else-if="articles.length === 0" class="request-state"
        >暂无资讯</view
      >
      <view
        v-for="item in articles"
        :key="item.id"
        class="article-item"
        @click="goDetail(item.id)"
      >
        <image :src="item.image" class="article-img" mode="aspectFill" />
        <view class="article-info">
          <view class="article-title">{{ item.title }}</view>
          <view class="article-desc">{{ item.desc }}</view>
          <view class="article-meta">
            <text>{{ item.author }}</text>
            <text>{{ item.click }}次浏览</text>
          </view>
        </view>
      </view>
    </scroll-view>
    <DecorationTabbar />
  </view>
</template>

<script setup lang="ts">
  import { onScopeDispose, ref } from 'vue';
  import { onHide, onShow, onUnload } from '@dcloudio/uni-app';
  import {
    getArticleCate,
    getArticleLists,
  } from '@/modules/peanut-article/api/news';
  import type { Article, ArticleCate } from '@/modules/peanut-article/api/news';
  import DecorationTabbar from '@/components/DecorationTabbar.vue';

  const categories = ref<ArticleCate[]>([]);
  const currentCateId = ref<number>(0);
  const articles = ref<Article[]>([]);

  const loadingCategories = ref(false);
  const loadingArticles = ref(false);
  const categoriesError = ref('');
  const articlesError = ref('');
  let pageGeneration = 0;
  let categoryGeneration = 0;
  let articleGeneration = 0;
  let disposed = false;

  function invalidateNews() {
    pageGeneration += 1;
    categoryGeneration += 1;
    articleGeneration += 1;
    loadingCategories.value = false;
    loadingArticles.value = false;
  }

  function disposeNews() {
    disposed = true;
    invalidateNews();
  }

  onHide(invalidateNews);
  onUnload(disposeNews);
  onScopeDispose(disposeNews);
  onShow(async () => {
    if (disposed) return;
    invalidateNews();
    const generation = pageGeneration;
    await loadCategories();
    if (generation === pageGeneration && !disposed) await loadArticles();
  });

  async function loadCategories() {
    if (disposed) return;
    const generation = ++categoryGeneration;
    loadingCategories.value = true;
    categoriesError.value = '';
    try {
      const list = await getArticleCate();
      if (generation !== categoryGeneration) return;
      categories.value = [{ id: 0, name: '全部', image: '', sort: 0 }, ...list];
    } catch {
      if (generation !== categoryGeneration) return;
      categoriesError.value = '分类加载失败，请重试';
    } finally {
      if (generation === categoryGeneration) loadingCategories.value = false;
    }
  }

  async function loadArticles() {
    if (disposed) return;
    const generation = ++articleGeneration;
    const categoryId = currentCateId.value;
    const isCurrent = () =>
      generation === articleGeneration && categoryId === currentCateId.value;
    loadingArticles.value = true;
    articlesError.value = '';
    try {
      const data = await getArticleLists({ cid: categoryId || undefined });
      if (!isCurrent()) return;
      articles.value = data.lists;
    } catch {
      if (!isCurrent()) return;
      articlesError.value = '资讯加载失败，请重试';
    } finally {
      if (isCurrent()) loadingArticles.value = false;
    }
  }

  function switchCate(id: number) {
    if (disposed) return;
    currentCateId.value = id;
    articles.value = [];
    return loadArticles();
  }

  function goDetail(id: number) {
    uni.navigateTo({ url: `/pages/news_detail/news_detail?id=${id}` });
  }
</script>

<style scoped>
  .request-state {
    padding: 24rpx;
    text-align: center;
  }
  .news-page {
    display: flex;
    flex-direction: column;
    height: 100vh;
    padding-bottom: calc(120rpx + env(safe-area-inset-bottom));
    background: #f5f5f5;
    box-sizing: border-box;
  }
  .tabs {
    display: flex;
    background: #fff;
    padding: 0 24rpx;
    border-bottom: 1rpx solid #eee;
    overflow-x: auto;
    white-space: nowrap;
  }
  .tab-item {
    padding: 24rpx 20rpx;
    font-size: 28rpx;
    color: #666;
    display: inline-block;
  }
  .tab-item.active {
    color: #2979ff;
    border-bottom: 4rpx solid #2979ff;
    font-weight: 600;
  }
  .article-list {
    flex: 1;
    padding: 24rpx;
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
    line-height: 1.4;
  }
  .article-desc {
    font-size: 24rpx;
    color: #999;
    overflow: hidden;
    text-overflow: ellipsis;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
  }
  .article-meta {
    display: flex;
    justify-content: space-between;
    font-size: 22rpx;
    color: #999;
  }
</style>
