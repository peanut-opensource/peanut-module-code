<template>
  <el-config-provider :locale="locale">
    <router-view />
    <global-setting />
  </el-config-provider>
</template>

<script lang="ts" setup>
  import { computed, watch } from 'vue';
  import enUS from 'element-plus/es/locale/lang/en';
  import zhCN from 'element-plus/es/locale/lang/zh-cn';
  import GlobalSetting from '@/components/global-setting/index.vue';
  import useLocale from '@/hooks/locale';
  import { useAppStore, useBrandStore } from '@/store';
  import { createShellTheme } from '@peanut-admin/ui-vue/theme';

  const { currentLocale } = useLocale();
  const appStore = useAppStore();
  watch(
    () => appStore.shellAppearance.theme,
    (theme) => {
      const root = document.documentElement;
      root.classList.toggle('dark', theme.mode === 'dark');
      Object.entries(createShellTheme(theme)).forEach(([property, value]) => {
        root.style.setProperty(property, value);
      });
    },
    { immediate: true, deep: true }
  );
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
