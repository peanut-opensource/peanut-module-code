<template>
  <view class="index-page" :style="pageStyle">
    <view v-if="loading" class="request-state" role="status"
      >正在加载首页…</view
    >
    <view v-else-if="error" class="request-state" role="alert">
      <text>{{ error }}</text>
      <button size="mini" @click="loadHome">重新加载</button>
    </view>
    <view
      class="page-meta"
      :class="metaTextClass"
      data-decoration-role="page-meta"
    >
      <image
        v-if="showTitleImage"
        :src="String(meta.title_img)"
        class="page-title-image"
        mode="heightFix"
      />
      <text v-else>{{ meta.title || '首页' }}</text>
    </view>

    <template
      v-for="decorationComponent in renderComponents"
      :key="decorationComponent.name"
    >
      <view
        v-if="decorationComponent.name === 'search'"
        class="decoration-search"
        data-decoration-component="search"
        @click="goNews"
      >
        <text class="search-icon">⌕</text><text>搜索资讯</text>
      </view>

      <swiper
        v-else-if="
          decorationComponent.name === 'banner' &&
          bannerItems(decorationComponent).length
        "
        class="banner"
        :class="`banner-style-${Number(
          decorationComponent.content.style || 1
        )}`"
        data-decoration-component="banner"
        :autoplay="true"
        :interval="4000"
        circular
      >
        <swiper-item
          v-for="item in bannerItems(decorationComponent)"
          :key="`${item.name}-${item.image}`"
        >
          <view
            class="banner-item"
            :style="bannerItemStyle(decorationComponent, item)"
            @click="executeDecorationLink(item.link)"
          >
            <image
              v-if="showBannerImage(decorationComponent, item)"
              :src="item.image"
              mode="aspectFill"
              class="banner-img"
            />
            <view v-if="item.name" class="banner-title">{{ item.name }}</view>
          </view>
        </swiper-item>
      </swiper>

      <view
        v-else-if="
          decorationComponent.name === 'nav' &&
          componentItems(decorationComponent).length
        "
        class="nav-grid"
        :class="`nav-style-${Number(decorationComponent.content.style || 1)}`"
        :style="navGridStyle(decorationComponent)"
        data-decoration-component="nav"
      >
        <view
          v-for="item in limitedNavItems(decorationComponent)"
          :key="`${item.name}-${item.image}`"
          class="nav-item"
          @click="executeDecorationLink(item.link)"
        >
          <image
            v-if="item.image"
            :src="item.image"
            mode="aspectFill"
            class="nav-image"
          />
          <view class="nav-name">{{ item.name }}</view>
        </view>
      </view>

      <swiper
        v-else-if="
          decorationComponent.name === 'middle-banner' &&
          bannerItems(decorationComponent).length
        "
        class="middle-banner"
        data-decoration-component="middle-banner"
        :autoplay="true"
        :interval="5000"
        circular
      >
        <swiper-item
          v-for="item in bannerItems(decorationComponent)"
          :key="`${item.name}-${item.image}`"
        >
          <view class="banner-item" @click="executeDecorationLink(item.link)">
            <image
              v-if="item.image"
              :src="item.image"
              mode="aspectFill"
              class="middle-banner-img"
            />
            <view v-if="item.name" class="banner-title">{{ item.name }}</view>
          </view>
        </swiper-item>
      </swiper>

      <view
        v-else-if="decorationComponent.name === 'news'"
        class="article-section"
        data-decoration-component="news"
      >
        <view class="section-title">最新资讯</view>
        <view v-if="articles.length" class="article-list">
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
                <text class="author">{{ item.author }}</text>
                <text class="views">{{ item.click }} 次浏览</text>
              </view>
            </view>
          </view>
        </view>
        <view v-else class="article-empty">
          <text class="article-empty-title">暂未发布资讯</text>
          <text class="article-empty-copy">新的内容将在这里展示</text>
        </view>
      </view>
    </template>
    <DecorationTabbar />
  </view>
</template>

<script setup lang="ts">
  import { computed, onScopeDispose, ref } from 'vue';
  import { onHide, onShow, onUnload } from '@dcloudio/uni-app';
  import { getIndexData, type Article } from '../api/index';
  import { useAppStore } from '@/store/app';
  import DecorationTabbar from '@/components/DecorationTabbar.vue';
  import {
    executeDecorationLink,
    applyDecorationPageMeta,
    getDecorationComponents,
    getDecorationItems,
    getDecorationMeta,
    getDecorationTheme,
    isDecorationComponentEnabled,
    type DecorationComponent,
    type DecorationPage,
    type DecorationItem,
  } from '@/utils/decoration';

  const appStore = useAppStore();
  const articles = ref<Article[]>([]);
  const decorate = ref<DecorationPage | null>(null);
  const loading = ref(false);
  const error = ref('');
  let generation = 0;
  let disposed = false;

  function invalidateHome() {
    generation += 1;
    loading.value = false;
  }

  function disposeHome() {
    disposed = true;
    invalidateHome();
  }

  onHide(invalidateHome);
  onUnload(disposeHome);
  onScopeDispose(disposeHome);
  const theme = computed(() => getDecorationTheme(appStore.config?.theme));

  const renderComponents = computed(() =>
    getDecorationComponents(decorate.value).filter(isDecorationComponentEnabled)
  );
  const meta = computed(() => getDecorationMeta(decorate.value));
  const showTitleImage = computed(
    () =>
      Number(meta.value.title_type) === 2 &&
      typeof meta.value.title_img === 'string' &&
      meta.value.title_img !== ''
  );
  const metaTextClass = computed(() =>
    Number(meta.value.text_color) === 2 ? 'page-meta-dark' : 'page-meta-light'
  );
  const componentItems = (component: DecorationComponent) =>
    getDecorationItems(component).filter(
      (item) => item.is_show === undefined || item.is_show === 1
    );
  const isMeaningfulBannerValue = (value: unknown) =>
    typeof value === 'string' && value.trim() !== '';
  const bannerItems = (component: DecorationComponent) =>
    componentItems(component).filter(
      (item) =>
        isMeaningfulBannerValue(item.image) ||
        isMeaningfulBannerValue(item.name) ||
        isMeaningfulBannerValue(item.bg)
    );
  const pageStyle = computed(() => {
    const style: Record<string, string> = {
      '--theme-primary': theme.value?.themeColor1 || '#2979ff',
      '--theme-secondary': theme.value?.themeColor2 || '#1d54c4',
    };
    if (meta.value.bg_type === 1 && typeof meta.value.bg_color === 'string')
      style.backgroundColor = meta.value.bg_color;
    if (
      meta.value.bg_type === 2 &&
      typeof meta.value.bg_image === 'string' &&
      meta.value.bg_image
    )
      style.backgroundImage = `url(${meta.value.bg_image})`;
    return style;
  });
  const navGridStyle = (component: DecorationComponent) => {
    const perLine = Number(component.content.per_line || 5);
    return {
      gridTemplateColumns: `repeat(${Math.min(5, Math.max(1, perLine))}, 1fr)`,
    };
  };
  const limitedNavItems = (component: DecorationComponent) => {
    const perLine = Math.min(
      5,
      Math.max(1, Number(component.content.per_line || 5))
    );
    const showLine = Math.min(
      2,
      Math.max(1, Number(component.content.show_line || 1))
    );
    return componentItems(component).slice(0, perLine * showLine);
  };
  const showBannerImage = (
    component: DecorationComponent,
    item: DecorationItem
  ) =>
    isMeaningfulBannerValue(item.image) &&
    (Number(component.content.bg_style || 1) === 1 ||
      !isMeaningfulBannerValue(item.bg));
  const bannerItemStyle = (
    component: DecorationComponent,
    item: DecorationItem
  ) =>
    Number(component.content.bg_style || 1) === 2 &&
    isMeaningfulBannerValue(item.bg)
      ? { backgroundImage: `url(${String(item.bg)})` }
      : {};

  async function loadHome() {
    if (disposed) return;
    const requestGeneration = ++generation;
    loading.value = true;
    error.value = '';
    try {
      await appStore.loadConfig();
      if (requestGeneration !== generation) return;
      const data = await getIndexData();
      if (requestGeneration !== generation) return;
      articles.value = data.article;
      decorate.value = data.decorate;
      applyDecorationPageMeta(decorate.value);
    } catch {
      if (requestGeneration !== generation) return;
      error.value = '首页加载失败，请重试';
    } finally {
      if (requestGeneration === generation) loading.value = false;
    }
  }

  onShow(loadHome);

  function goDetail(id: number) {
    uni.navigateTo({ url: `/pages/news_detail/news_detail?id=${id}` });
  }
  function goNews() {
    uni.navigateTo({ url: '/pages/search/search' });
  }
</script>

<style scoped>
  .request-state {
    padding: 24rpx;
    text-align: center;
  }
  .index-page {
    min-height: 100vh;
    padding-bottom: calc(120rpx + env(safe-area-inset-bottom));
    background: #f5f5f5;
    background-size: cover;
    background-position: center;
    box-sizing: border-box;
  }
  .page-meta {
    display: flex;
    min-height: 88rpx;
    align-items: center;
    justify-content: center;
    padding: 12rpx 24rpx;
    background: var(--theme-primary);
    font-size: 32rpx;
    font-weight: 600;
    box-sizing: border-box;
  }
  .page-meta-light {
    color: #fff;
  }
  .page-meta-dark {
    color: #000;
  }
  .page-title-image {
    height: 52rpx;
    max-width: 360rpx;
  }
  .decoration-search {
    display: flex;
    align-items: center;
    gap: 12rpx;
    margin: 20rpx 24rpx;
    padding: 18rpx 24rpx;
    border-radius: 999rpx;
    background: #fff;
    color: #999;
    font-size: 26rpx;
  }
  .search-icon {
    font-size: 34rpx;
  }
  .banner,
  .middle-banner {
    width: 100%;
    height: 360rpx;
  }
  .banner-style-2 {
    width: calc(100% - 48rpx);
    margin: 20rpx 24rpx 0;
    border-radius: 18rpx;
    overflow: hidden;
  }
  .middle-banner {
    margin: 20rpx 24rpx 0;
    width: calc(100% - 48rpx);
    height: 220rpx;
    border-radius: 12rpx;
    overflow: hidden;
  }
  .banner-item {
    position: relative;
    width: 100%;
    height: 100%;
    background: #eee center / cover no-repeat;
  }
  .banner-img,
  .middle-banner-img {
    width: 100%;
    height: 100%;
  }
  .banner-title {
    position: absolute;
    left: 24rpx;
    bottom: 20rpx;
    color: #fff;
    font-size: 28rpx;
    text-shadow: 0 1rpx 6rpx rgb(0 0 0 / 50%);
  }
  .nav-grid {
    display: grid;
    gap: 12rpx;
    margin: 20rpx 24rpx;
    padding: 20rpx;
    background: #fff;
    border-radius: 12rpx;
  }
  .nav-item {
    display: flex;
    flex-direction: column;
    align-items: center;
    min-width: 0;
  }
  .nav-image {
    width: 72rpx;
    height: 72rpx;
    border-radius: 10rpx;
  }
  .nav-style-2 .nav-image {
    border-radius: 50%;
  }
  .nav-style-2 {
    border-top: 1rpx solid #f0f0f0;
    border-bottom: 1rpx solid #f0f0f0;
    border-radius: 0;
  }
  .nav-name {
    margin-top: 8rpx;
    color: #333;
    font-size: 24rpx;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    max-width: 100%;
  }
  .article-section {
    padding: 24rpx;
  }
  .section-title {
    font-size: 32rpx;
    font-weight: 600;
    margin-bottom: 20rpx;
    color: var(--theme-primary);
  }
  .article-empty {
    display: flex;
    min-height: 200rpx;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 12rpx;
    border: 2rpx dashed #cbd5e1;
    border-radius: 12rpx;
    background: #f8fafc;
  }
  .article-empty-title {
    color: #334155;
    font-size: 28rpx;
    font-weight: 600;
  }
  .article-empty-copy {
    color: #64748b;
    font-size: 24rpx;
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
  .article-meta {
    display: flex;
    justify-content: space-between;
    font-size: 22rpx;
    color: #999;
  }
</style>
