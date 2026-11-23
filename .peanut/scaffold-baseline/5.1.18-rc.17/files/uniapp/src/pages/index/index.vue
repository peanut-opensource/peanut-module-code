<template>
  <view>
    <ModuleSlot v-if="hasModuleSlot('home')" name="home" />
    <view v-else class="home-brand">
      <text>{{ name }}</text>
    </view>
  </view>
</template>
<script setup lang="ts">
  import { computed } from 'vue';
  import ModuleSlot from '@/components/ModuleSlot.vue';
  import { hasModuleSlot } from '@/module-contributions';
  import { useAppStore } from '@/store/app';
  import { resolveBrandName } from '@/utils/brand';
  const appStore = useAppStore();
  const name = computed(() => resolveBrandName(appStore.config?.website));
</script>
<style scoped>
  .home-brand {
    padding: 80rpx 40rpx;
    font-size: 40rpx;
  }
</style>
