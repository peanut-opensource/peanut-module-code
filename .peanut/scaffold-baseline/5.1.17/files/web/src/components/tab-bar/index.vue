<template>
  <div class="tab-bar-container">
    <div class="tab-bar-box">
      <div v-if="tagList[0]" class="tab-bar-pinned">
        <tab-item
          :index="0"
          :item-data="tagList[0]"
          :icon="tagIcons.get(getTabKey(tagList[0]))"
        />
      </div>
      <el-button
        v-if="canScrollLeft || canScrollRight"
        class="tab-scroll-control"
        link
        :disabled="!canScrollLeft"
        aria-label="向左滚动标签"
        title="向左滚动标签"
        @click="scrollTabs(-1)"
      >
        <el-icon><ArrowLeft /></el-icon>
      </el-button>
      <div
        ref="tabScrollRef"
        class="tab-bar-scroll"
        @scroll="updateScrollControls"
      >
        <div class="tags-wrap">
          <tab-item
            v-for="(tag, index) in tagList.slice(1)"
            :key="getTabKey(tag)"
            :index="index + 1"
            :item-data="tag"
            :icon="tagIcons.get(getTabKey(tag))"
          />
        </div>
      </div>
      <el-button
        v-if="canScrollLeft || canScrollRight"
        class="tab-scroll-control"
        link
        :disabled="!canScrollRight"
        aria-label="向右滚动标签"
        title="向右滚动标签"
        @click="scrollTabs(1)"
      >
        <el-icon><ArrowRight /></el-icon>
      </el-button>
      <TabBarMenu
        v-if="actionTag"
        class="tab-bar-actions"
        show-tabs
        :index="actionIndex"
        :item-data="actionTag"
      />
    </div>
  </div>
</template>

<script lang="ts" setup>
  import {
    ref,
    computed,
    watch,
    nextTick,
    onMounted,
    onBeforeUnmount,
  } from 'vue';
  import { useRoute, useRouter } from 'vue-router';
  import { ArrowLeft, ArrowRight } from '@element-plus/icons-vue';
  import { getTabKey } from '@peanut-admin/ui-vue/tabs';
  import { useTabBarStore } from '@/store';
  import useMenuTree, {
    findMenuRoutePath,
  } from '@/components/menu/use-menu-tree';
  import tabItem from './tab-item.vue';
  import TabBarMenu from './TabBarMenu.vue';

  const tabBarStore = useTabBarStore();
  const route = useRoute();
  const router = useRouter();
  const { menuTree } = useMenuTree();

  const tabScrollRef = ref<HTMLElement>();
  const canScrollLeft = ref(false);
  const canScrollRight = ref(false);
  const tagList = computed(() => {
    return tabBarStore.getTabList;
  });
  const actionIndex = computed(() => {
    const index = tagList.value.findIndex(
      (tag) => getTabKey(tag) === getTabKey(route)
    );
    return index < 0 ? 0 : index;
  });
  const actionTag = computed(() => tagList.value[actionIndex.value]);
  const tagIcons = computed(
    () =>
      new Map(
        tagList.value.map((tag) => {
          const resolved = router.resolve(tag.fullPath);
          const menuPath = findMenuRoutePath(menuTree.value, resolved);
          const item = menuPath?.[menuPath.length - 1];
          return [
            getTabKey(tag),
            item?.meta?.icon ?? resolved.meta.icon,
          ] as const;
        })
      )
  );

  const updateScrollControls = () => {
    const container = tabScrollRef.value;
    if (!container) return;
    canScrollLeft.value = container.scrollLeft > 0;
    canScrollRight.value =
      container.scrollLeft + container.clientWidth < container.scrollWidth - 1;
  };

  const scrollTabs = (direction: number) => {
    const container = tabScrollRef.value;
    if (!container) return;
    container.scrollBy({
      left: direction * Math.max(180, container.clientWidth * 0.75),
      behavior: 'smooth',
    });
  };

  const scrollActiveTabIntoView = async () => {
    await nextTick();
    const container = tabScrollRef.value;
    const activeTab = container?.querySelector<HTMLElement>('.link-activated');
    if (!container || !activeTab) return;

    const containerBounds = container.getBoundingClientRect();
    const tabBounds = activeTab.getBoundingClientRect();
    if (tabBounds.left < containerBounds.left) {
      container.scrollLeft += tabBounds.left - containerBounds.left;
    } else if (tabBounds.right > containerBounds.right) {
      container.scrollLeft += tabBounds.right - containerBounds.right;
    }
  };

  let resizeObserver: ResizeObserver;
  onMounted(() => {
    resizeObserver = new ResizeObserver(() => {
      updateScrollControls();
      scrollActiveTabIntoView();
    });
    if (tabScrollRef.value) {
      resizeObserver.observe(tabScrollRef.value);
      const tagsWrap = tabScrollRef.value.firstElementChild;
      if (tagsWrap) resizeObserver.observe(tagsWrap);
    }
    updateScrollControls();
  });
  onBeforeUnmount(() => resizeObserver.disconnect());

  watch(
    () => route.fullPath,
    async () => {
      if (!route.meta.noAffix) tabBarStore.updateTabList(route);
      await scrollActiveTabIntoView();
      updateScrollControls();
    },
    { immediate: true, flush: 'post' }
  );
</script>

<style scoped lang="less">
  .tab-bar-container {
    position: relative;
    background: var(--el-bg-color);
    border-bottom: 1px solid var(--el-border-color-lighter);
    .tab-bar-box {
      display: flex;
      height: 44px;
      padding-inline: 16px;
      align-items: center;
      gap: 8px;
      .tab-bar-pinned {
        min-width: 0;
        max-width: 35%;
        flex: 0 1 auto;
        overflow: hidden;
      }
      .tab-scroll-control {
        width: 24px;
        height: 32px;
        flex: 0 0 24px;
        padding: 0;
      }
      .tab-bar-scroll {
        min-width: 0;
        height: 100%;
        flex: 1;
        overflow-x: auto;
        overflow-y: hidden;
        scrollbar-width: none;

        &::-webkit-scrollbar {
          height: 0;
        }

        .tags-wrap {
          display: flex;
          width: max-content;
          min-width: 100%;
          height: 100%;
          align-items: center;
          gap: 0;
          white-space: nowrap;

          :deep(.el-dropdown) {
            flex: 0 0 auto;
          }

          :deep(.el-tag) {
            display: inline-flex;
            align-items: center;
            height: 44px;
            flex: 0 0 auto;
            cursor: pointer;
          }
        }
      }
      .tab-bar-actions {
        flex: 0 0 32px;
      }
      .tab-bar-pinned :deep(.el-tag) {
        height: 44px;
        max-width: 100%;
      }
      .tab-bar-pinned :deep(.tag-link) {
        display: block;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
      }
    }
  }
</style>
