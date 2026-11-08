<template>
  <el-breadcrumb class="container-breadcrumb" separator="/">
    <el-breadcrumb-item>
      <el-icon><Grid /></el-icon>
    </el-breadcrumb-item>
    <el-breadcrumb-item v-for="item in items" :key="item">
      {{ $t(item) }}
    </el-breadcrumb-item>
  </el-breadcrumb>
</template>

<script lang="ts" setup>
  import type { PropType } from 'vue';
  import { Grid } from '@element-plus/icons-vue';

  defineProps({
    items: {
      type: Array as PropType<string[]>,
      default() {
        return [];
      },
    },
  });
</script>

<style scoped lang="less">
  .container-breadcrumb {
    margin: 16px 0;
    :deep(.el-breadcrumb__inner) {
      color: var(--el-text-color-secondary);
      &:last-child {
        color: var(--el-text-color-primary);
      }
    }
  }
</style>
