<template>
  <TabBarMenu v-slot="{ closeCurrent }" :item-data="itemData" :index="index">
    <el-tag
      :class="{ 'link-activated': getTabKey(itemData) === getTabKey(route) }"
      :closable="index !== 0"
      @click="router.push(itemData.fullPath)"
      @close="closeCurrent"
    >
      <el-icon v-if="icon" class="tab-icon" aria-hidden="true">
        <component :is="resolveMenuIcon(icon)" />
      </el-icon>
      <span class="tag-link">{{ $t(itemData.title) }}</span>
    </el-tag>
  </TabBarMenu>
</template>

<script lang="ts" setup>
  import { useRouter, useRoute } from 'vue-router';
  import { getTabKey, type ShellTab } from '@peanut-admin/ui-vue/tabs';
  import resolveMenuIcon from '@/components/menu/menu-icons';
  import TabBarMenu from './TabBarMenu.vue';

  defineProps<{ itemData: ShellTab; index: number; icon?: string }>();
  const router = useRouter();
  const route = useRoute();
</script>

<style scoped lang="less">
  .tab-icon {
    margin-inline-end: 6px;
    font-size: 14px;
    flex-shrink: 0;
  }
  .el-tag {
    padding-inline: 16px;
    font-size: 13px;
    border: 0;
    border-bottom: 2px solid transparent;
    border-radius: 0;
    background: transparent;
    :deep(.el-tag__content) {
      display: inline-flex;
      align-items: center;
      min-width: 0;
      max-width: 100%;
    }
  }
  .tag-link {
    min-width: 0;
    color: var(--el-text-color-regular);
    text-decoration: none;
  }
  .link-activated {
    color: var(--el-color-primary);
    border-bottom-color: var(--el-color-primary);
    background: var(--el-color-primary-light-9);
    .tag-link {
      color: var(--el-color-primary);
      font-weight: 500;
    }
    :deep(.el-tag__close) {
      color: var(--el-color-primary);
    }
  }
</style>
