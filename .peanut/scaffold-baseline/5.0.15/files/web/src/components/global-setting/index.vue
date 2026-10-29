<template>
  <div v-if="!appStore.navbar" class="fixed-settings" @click="setVisible">
    <el-button type="primary">
      <template #icon>
        <Setting />
      </template>
    </el-button>
  </div>
  <el-drawer
    v-model="visible"
    size="300px"
    destroy-on-close
    :title="$t('settings.title')"
  >
    <Block :options="contentOpts" :title="$t('settings.content')" />
    <Block :options="othersOpts" :title="$t('settings.otherSettings')" />
    <el-alert>{{ $t('settings.alertContent') }}</el-alert>
    <template #footer>
      <el-button @click="cancel">{{ $t('settings.close') }}</el-button>
      <el-button type="primary" @click="copySettings">
        {{ $t('settings.copySettings') }}
      </el-button>
    </template>
  </el-drawer>
</template>

<script lang="ts" setup>
  import { computed } from 'vue';
  import { ElMessage } from 'element-plus';
  import { Setting } from '@element-plus/icons-vue';
  import { useI18n } from 'vue-i18n';
  import { useClipboard } from '@vueuse/core';
  import { useAppStore } from '@/store';
  import Block from './block.vue';

  const emit = defineEmits(['cancel']);

  const appStore = useAppStore();
  const { t } = useI18n();
  const { copy } = useClipboard();
  const contentOpts = computed(() => [
    { name: 'settings.navbar', key: 'navbar', defaultVal: appStore.navbar },
    {
      name: 'settings.menu',
      key: 'menu',
      defaultVal: appStore.menu,
    },
    {
      name: 'settings.topMenu',
      key: 'topMenu',
      defaultVal: appStore.topMenu,
    },
    { name: 'settings.footer', key: 'footer', defaultVal: appStore.footer },
    { name: 'settings.tabBar', key: 'tabBar', defaultVal: appStore.tabBar },
    {
      name: 'settings.menuFromServer',
      key: 'menuFromServer',
      defaultVal: appStore.menuFromServer,
    },
    {
      name: 'settings.menuWidth',
      key: 'menuWidth',
      defaultVal: appStore.menuWidth,
      type: 'number',
    },
  ]);
  const othersOpts = computed(() => [
    {
      name: 'settings.colorWeak',
      key: 'colorWeak',
      defaultVal: appStore.colorWeak,
    },
  ]);

  const cancel = () => {
    appStore.updateSettings({ globalSettings: false });
    emit('cancel');
  };
  const visible = computed({
    get: () => appStore.globalSettings,
    set: (value: boolean) => {
      if (value) {
        appStore.updateSettings({ globalSettings: true });
      } else {
        cancel();
      }
    },
  });
  const copySettings = async () => {
    const text = JSON.stringify(appStore.$state, null, 2);
    await copy(text);
    ElMessage.success(t('settings.copySettings.message'));
  };
  const setVisible = () => {
    appStore.updateSettings({ globalSettings: true });
  };
</script>

<style scoped lang="less">
  .fixed-settings {
    position: fixed;
    top: 280px;
    right: 0;

    svg {
      font-size: 18px;
      vertical-align: -4px;
    }
  }
</style>
