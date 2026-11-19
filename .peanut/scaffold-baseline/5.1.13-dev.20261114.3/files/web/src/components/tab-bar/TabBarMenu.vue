<template>
  <el-dropdown
    :trigger="showTabs ? 'click' : 'contextmenu'"
    :popper-class="showTabs ? 'tab-bar-actions-popper' : undefined"
    @command="actionSelect"
  >
    <slot :close-current="closeCurrent">
      <el-button
        class="tab-actions-trigger"
        link
        aria-label="标签页管理与切换"
        title="标签页管理与切换"
      >
        <el-icon><MoreFilled /></el-icon>
      </el-button>
    </slot>
    <template #dropdown>
      <el-dropdown-menu :class="{ 'tab-list-menu': showTabs }">
        <el-dropdown-item
          v-for="(tag, tagIndex) in showTabs ? tagList : []"
          :key="getTabKey(tag)"
          class="tab-list-item"
          :class="{ 'sperate-line': tagIndex === tagList.length - 1 }"
          :command="{ fullPath: tag.fullPath }"
          :aria-current="isCurrent(tag) ? 'page' : undefined"
        >
          <span class="tab-list-marker" aria-hidden="true">
            <el-icon v-if="isCurrent(tag)"><Check /></el-icon>
          </span>
          <span class="tab-list-label">{{ $t(tag.title) }}</span>
        </el-dropdown-item>
        <el-dropdown-item
          :disabled="!isCurrent(itemData)"
          :command="Eaction.reload"
        >
          <el-icon><Refresh /></el-icon>重新加载
        </el-dropdown-item>
        <el-dropdown-item
          class="sperate-line"
          :disabled="index === 0"
          :command="Eaction.current"
        >
          <el-icon><Close /></el-icon>关闭当前标签页
        </el-dropdown-item>
        <el-dropdown-item :disabled="index < 2" :command="Eaction.left">
          <el-icon><DArrowLeft /></el-icon>关闭左侧标签页
        </el-dropdown-item>
        <el-dropdown-item
          class="sperate-line"
          :disabled="index === tagList.length - 1"
          :command="Eaction.right"
        >
          <el-icon><DArrowRight /></el-icon>关闭右侧标签页
        </el-dropdown-item>
        <el-dropdown-item :command="Eaction.others">
          <el-icon><Switch /></el-icon>关闭其它标签页
        </el-dropdown-item>
        <el-dropdown-item :command="Eaction.all">
          <el-icon><FolderDelete /></el-icon>关闭全部标签页
        </el-dropdown-item>
      </el-dropdown-menu>
    </template>
  </el-dropdown>
</template>

<script lang="ts" setup>
  import { computed } from 'vue';
  import { useRouter, useRoute } from 'vue-router';
  import {
    Check,
    Close,
    DArrowLeft,
    DArrowRight,
    FolderDelete,
    MoreFilled,
    Refresh,
    Switch,
  } from '@element-plus/icons-vue';
  import { getTabKey, type ShellTab } from '@peanut-admin/ui-vue/tabs';
  import { useTabBarStore } from '@/store';
  import { DEFAULT_ROUTE_NAME, REDIRECT_ROUTE_NAME } from '@/router/constants';

  const props = withDefaults(
    defineProps<{
      itemData: ShellTab;
      index: number;
      showTabs?: boolean;
    }>(),
    { showTabs: false }
  );

  // eslint-disable-next-line no-shadow
  enum Eaction {
    reload = 'reload',
    current = 'current',
    left = 'left',
    right = 'right',
    others = 'others',
    all = 'all',
  }

  const router = useRouter();
  const route = useRoute();
  const tabBarStore = useTabBarStore();
  const tagList = computed(() => tabBarStore.getTabList);
  const isCurrent = (tag: ShellTab) => getTabKey(tag) === getTabKey(route);

  const closeCurrent = () => {
    if (props.index === 0) return;
    const active = isCurrent(props.itemData);
    tabBarStore.deleteTag(props.index, props.itemData);
    if (active) router.push(tagList.value[props.index - 1].fullPath);
  };

  const actionSelect = async (value: Eaction | { fullPath: string }) => {
    if (typeof value !== 'string') {
      router.push(value.fullPath);
      return;
    }
    const { itemData, index } = props;
    const copyTagList = [...tagList.value];
    const currentRouteIdx = tagList.value.findIndex(isCurrent);
    if (value === Eaction.current) {
      closeCurrent();
    } else if (value === Eaction.left) {
      copyTagList.splice(1, index - 1);
      tabBarStore.freshTabList(copyTagList);
      if (currentRouteIdx < index) router.push(itemData.fullPath);
    } else if (value === Eaction.right) {
      copyTagList.splice(index + 1);
      tabBarStore.freshTabList(copyTagList);
      if (currentRouteIdx > index) router.push(itemData.fullPath);
    } else if (value === Eaction.others) {
      tabBarStore.freshTabList(
        tagList.value.filter((_tag, idx) => idx === 0 || idx === index)
      );
      router.push(itemData.fullPath);
    } else if (value === Eaction.reload) {
      tabBarStore.deleteCache(itemData);
      await router.push({
        name: REDIRECT_ROUTE_NAME,
        params: { path: route.fullPath },
      });
      tabBarStore.addCache(getTabKey(itemData));
    } else if (value === Eaction.all) {
      tabBarStore.resetTabList();
      router.push({ name: DEFAULT_ROUTE_NAME });
    }
  };
</script>

<style scoped lang="less">
  .tab-actions-trigger {
    width: 32px;
    height: 32px;
    padding: 0;
  }
  .sperate-line {
    border-bottom: 1px solid var(--el-border-color-lighter);
  }
  .tab-list-marker {
    display: inline-flex;
    width: 14px;
    height: 14px;
    flex: 0 0 14px;
    align-items: center;
    justify-content: center;
    margin-right: 10px;
  }
  :global(.tab-bar-actions-popper .tab-list-menu) {
    width: min(320px, calc(100vw - 24px));
    max-height: min(60vh, 480px);
    overflow-x: hidden;
    overflow-y: auto;
    box-sizing: border-box;
  }
  :global(.tab-bar-actions-popper .tab-list-label) {
    display: block;
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
</style>
