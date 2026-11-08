<template>
  <div class="tab-bar-container">
    <el-affix ref="affixRef" :offset="offsetTop">
      <div class="tab-bar-box">
        <div class="tab-bar-scroll">
          <div class="tags-wrap">
            <tab-item
              v-for="(tag, index) in tagList"
              :key="tag.fullPath"
              :index="index"
              :item-data="tag"
            />
          </div>
        </div>
        <div class="tag-bar-operation"></div>
      </div>
    </el-affix>
  </div>
</template>

<script lang="ts" setup>
  import { ref, computed, watch, onUnmounted } from 'vue';
  import type { RouteLocationNormalized } from 'vue-router';
  import {
    listenerRouteChange,
    removeRouteListener,
  } from '@/utils/route-listener';
  import { useAppStore, useTabBarStore } from '@/store';
  import tabItem from './tab-item.vue';

  const appStore = useAppStore();
  const tabBarStore = useTabBarStore();

  const affixRef = ref();
  const tagList = computed(() => {
    return tabBarStore.getTabList;
  });
  const offsetTop = computed(() => {
    return appStore.navbar ? 60 : 0;
  });

  watch(
    () => appStore.navbar,
    () => {
      affixRef.value?.updateRoot?.();
    }
  );
  listenerRouteChange((route: RouteLocationNormalized) => {
    if (
      !route.meta.noAffix &&
      !tagList.value.some((tag) => tag.fullPath === route.fullPath)
    ) {
      tabBarStore.updateTabList(route);
    }
  }, true);

  onUnmounted(() => {
    removeRouteListener();
  });
</script>

<style scoped lang="less">
  .tab-bar-container {
    position: relative;
    background-color: var(--el-bg-color);
    .tab-bar-box {
      display: flex;
      padding: 0 0 0 20px;
      background-color: var(--el-bg-color);
      border-bottom: 1px solid var(--el-border-color);
      .tab-bar-scroll {
        height: 32px;
        flex: 1;
        overflow: hidden;
        .tags-wrap {
          padding: 4px 0;
          height: 48px;
          white-space: nowrap;
          overflow-x: auto;

          :deep(.el-tag) {
            display: inline-flex;
            align-items: center;
            margin-right: 6px;
            cursor: pointer;
          }
        }
      }
    }

    .tag-bar-operation {
      width: 100px;
      height: 32px;
    }
  }
</style>
