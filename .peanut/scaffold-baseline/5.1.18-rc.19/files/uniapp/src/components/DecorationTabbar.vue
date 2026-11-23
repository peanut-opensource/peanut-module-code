<template>
  <view v-if="items.length >= 2" class="decoration-tabbar" :style="tabbarStyle">
    <view
      v-for="item in items"
      :key="`${item.position}-${item.name}`"
      class="decoration-tabbar__item"
      :style="itemStyle(item)"
      @click="executeDecorationLink(item.link)"
    >
      <image
        v-if="itemIcon(item)"
        class="decoration-tabbar__icon"
        :src="itemIcon(item)"
        mode="aspectFit"
      />
      <text class="decoration-tabbar__text">{{ item.name }}</text>
    </view>
  </view>
</template>

<script setup lang="ts">
  import { computed, onMounted } from 'vue';
  import { useAppStore } from '@/store/app';
  import { executeDecorationLink } from '@/utils/decoration';
  import type { DecorationTabbarItem } from '@/utils/decoration';

  const appStore = useAppStore();
  const items = computed(() => {
    const list = appStore.config?.tabbar?.list;
    if (!Array.isArray(list)) return [];
    return list
      .filter((item) => item.is_show === 1)
      .sort((a, b) => Number(a.position || 0) - Number(b.position || 0))
      .slice(0, 5);
  });
  const tabbarStyle = computed(() => ({
    color: appStore.config?.tabbar?.style?.default_color || '#666666',
  }));
  const currentRoute = (() => {
    const pages = getCurrentPages();
    return pages.length ? `/${pages[pages.length - 1].route || ''}` : '';
  })();
  const shopRoutes: Record<string, string> = {
    home: '/pages/index/index',
    news: '/pages/news/news',
    profile: '/pages/user/user',
  };
  const isActive = (item: DecorationTabbarItem) =>
    item.link.target_type === 'shop' &&
    shopRoutes[String(item.link.target)] === currentRoute;
  const itemIcon = (item: DecorationTabbarItem) =>
    isActive(item)
      ? item.selected || item.unselected
      : item.unselected || item.selected;
  const itemStyle = (item: DecorationTabbarItem) => ({
    color: isActive(item)
      ? appStore.config?.tabbar?.style?.selected_color || '#2979ff'
      : appStore.config?.tabbar?.style?.default_color || '#666666',
  });

  onMounted(() => {
    appStore.loadConfig().catch((error) => {
      console.error('Failed to load decoration tabbar:', error);
    });
  });
</script>

<style scoped>
  .decoration-tabbar {
    position: fixed;
    right: 0;
    bottom: 0;
    left: 0;
    z-index: 100;
    display: flex;
    align-items: stretch;
    min-height: 100rpx;
    padding-bottom: env(safe-area-inset-bottom);
    background: #fff;
    border-top: 1rpx solid #eee;
    box-shadow: 0 -4rpx 16rpx rgb(0 0 0 / 5%);
  }

  .decoration-tabbar__item {
    display: flex;
    flex: 1;
    min-width: 0;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 10rpx 4rpx;
  }

  .decoration-tabbar__icon {
    width: 44rpx;
    height: 44rpx;
  }

  .decoration-tabbar__text {
    max-width: 100%;
    margin-top: 4rpx;
    overflow: hidden;
    font-size: 22rpx;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
</style>
