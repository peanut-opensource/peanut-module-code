<template>
  <el-dropdown trigger="contextmenu" @command="actionSelect">
    <el-tag
      :class="{ 'link-activated': itemData.fullPath === route.fullPath }"
      :closable="index !== 0"
      @click="goto(itemData)"
      @close="tagClose(itemData, index)"
    >
      <span class="tag-link">
        {{ $t(itemData.title) }}
      </span>
    </el-tag>
    <template #dropdown>
      <el-dropdown-menu>
        <el-dropdown-item :disabled="disabledReload" :command="Eaction.reload">
          <el-icon><Refresh /></el-icon>
          <span>重新加载</span>
        </el-dropdown-item>
        <el-dropdown-item
          class="sperate-line"
          :disabled="disabledCurrent"
          :command="Eaction.current"
        >
          <el-icon><Close /></el-icon>
          <span>关闭当前标签页</span>
        </el-dropdown-item>
        <el-dropdown-item :disabled="disabledLeft" :command="Eaction.left">
          <el-icon><DArrowLeft /></el-icon>
          <span>关闭左侧标签页</span>
        </el-dropdown-item>
        <el-dropdown-item
          class="sperate-line"
          :disabled="disabledRight"
          :command="Eaction.right"
        >
          <el-icon><DArrowRight /></el-icon>
          <span>关闭右侧标签页</span>
        </el-dropdown-item>
        <el-dropdown-item :command="Eaction.others">
          <el-icon><Switch /></el-icon>
          <span>关闭其它标签页</span>
        </el-dropdown-item>
        <el-dropdown-item :command="Eaction.all">
          <el-icon><FolderDelete /></el-icon>
          <span>关闭全部标签页</span>
        </el-dropdown-item>
      </el-dropdown-menu>
    </template>
  </el-dropdown>
</template>

<script lang="ts" setup>
  import { PropType, computed } from 'vue';
  import { useRouter, useRoute } from 'vue-router';
  import {
    Close,
    DArrowLeft,
    DArrowRight,
    FolderDelete,
    Refresh,
    Switch,
  } from '@element-plus/icons-vue';
  import type { ShellTab as TagProps } from '@peanut-admin/ui-vue';
  import { useTabBarStore } from '@/store';
  import { DEFAULT_ROUTE_NAME, REDIRECT_ROUTE_NAME } from '@/router/constants';

  // eslint-disable-next-line no-shadow
  enum Eaction {
    reload = 'reload',
    current = 'current',
    left = 'left',
    right = 'right',
    others = 'others',
    all = 'all',
  }

  const props = defineProps({
    itemData: {
      type: Object as PropType<TagProps>,
      default() {
        return {};
      },
    },
    index: {
      type: Number,
      default: 0,
    },
  });

  const router = useRouter();
  const route = useRoute();
  const tabBarStore = useTabBarStore();

  const goto = (tag: TagProps) => {
    router.push(tag.fullPath);
  };
  const tagList = computed(() => {
    return tabBarStore.getTabList;
  });

  const disabledReload = computed(() => {
    return props.itemData.fullPath !== route.fullPath;
  });

  const disabledCurrent = computed(() => {
    return props.index === 0;
  });

  const disabledLeft = computed(() => {
    return [0, 1].includes(props.index);
  });

  const disabledRight = computed(() => {
    return props.index === tagList.value.length - 1;
  });

  const tagClose = (tag: TagProps, idx: number) => {
    tabBarStore.deleteTag(idx, tag);
    if (props.itemData.fullPath === route.fullPath) {
      const latest = tagList.value[idx - 1]; // 获取队列的前一个tab
      router.push({ name: latest.name });
    }
  };

  const findCurrentRouteIndex = () => {
    return tagList.value.findIndex((el) => el.fullPath === route.fullPath);
  };
  const actionSelect = async (value: unknown) => {
    const { itemData, index } = props;
    const copyTagList = [...tagList.value];
    if (value === Eaction.current) {
      tagClose(itemData, index);
    } else if (value === Eaction.left) {
      const currentRouteIdx = findCurrentRouteIndex();
      copyTagList.splice(1, props.index - 1);

      tabBarStore.freshTabList(copyTagList);
      if (currentRouteIdx < index) {
        router.push({ name: itemData.name });
      }
    } else if (value === Eaction.right) {
      const currentRouteIdx = findCurrentRouteIndex();
      copyTagList.splice(props.index + 1);

      tabBarStore.freshTabList(copyTagList);
      if (currentRouteIdx > index) {
        router.push({ name: itemData.name });
      }
    } else if (value === Eaction.others) {
      const filterList = tagList.value.filter((el, idx) => {
        return idx === 0 || idx === props.index;
      });
      tabBarStore.freshTabList(filterList);
      router.push({ name: itemData.name });
    } else if (value === Eaction.reload) {
      tabBarStore.deleteCache(itemData);
      await router.push({
        name: REDIRECT_ROUTE_NAME,
        params: {
          path: route.fullPath,
        },
      });
      tabBarStore.addCache(itemData.name);
    } else {
      tabBarStore.resetTabList();
      router.push({ name: DEFAULT_ROUTE_NAME });
    }
  };
</script>

<style scoped lang="less">
  .tag-link {
    color: var(--el-text-color-regular);
    text-decoration: none;
  }
  .link-activated {
    color: var(--el-color-primary);
    .tag-link {
      color: var(--el-color-primary);
    }
    :deep(.el-tag__close) {
      color: var(--el-color-primary);
    }
  }
  :deep(.el-dropdown-menu__item .el-icon) {
    margin-right: 10px;
  }
  .sperate-line {
    border-bottom: 1px solid var(--el-border-color-lighter);
  }
</style>
