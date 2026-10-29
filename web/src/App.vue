<template>
  <el-config-provider :locale="locale">
    <router-view />
    <global-setting />
  </el-config-provider>
</template>

<script lang="ts" setup>
  import { computed } from 'vue';
  import enUS from 'element-plus/es/locale/lang/en';
  import zhCN from 'element-plus/es/locale/lang/zh-cn';
  import GlobalSetting from '@/components/global-setting/index.vue';
  import useLocale from '@/hooks/locale';
  import { useBrandStore } from '@/store';

  const { currentLocale } = useLocale();
  useBrandStore().load();
  const locale = computed(() => {
    switch (currentLocale.value) {
      case 'zh-CN':
        return zhCN;
      case 'en-US':
        return enUS;
      default:
        return enUS;
    }
  });
</script>
