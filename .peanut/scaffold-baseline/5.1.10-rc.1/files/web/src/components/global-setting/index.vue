<template>
  <div v-if="!appStore.navbar" class="fixed-settings" @click="setVisible">
    <el-button type="primary" :aria-label="$t('settings.title')">
      <template #icon><Setting /></template>
    </el-button>
  </div>
  <el-drawer
    v-model="visible"
    class="pa-shell-settings"
    size="min(400px, 100vw)"
    destroy-on-close
    :title="$t('settings.title')"
  >
    <Block
      :title="$t('settings.appearance')"
      :description="$t('settings.appearance.description')"
    >
      <el-form label-position="top">
        <el-form-item
          v-if="shellUserOptions.presets.length > 1"
          :label="$t('settings.shellPreset')"
        >
          <el-select
            :model-value="appearance.preset"
            @change="appStore.selectShellPreset"
          >
            <el-option
              v-for="name in shellUserOptions.presets"
              :key="name"
              :value="name"
              :label="$t(`settings.preset.${name}`)"
            />
          </el-select>
        </el-form-item>
        <el-form-item
          v-if="shellUserOptions.modes.length > 1"
          :label="$t('settings.theme')"
        >
          <el-select
            :model-value="appearance.theme.mode"
            @change="(value: ShellThemeMode) => appStore.toggleTheme(value === 'dark')"
          >
            <el-option
              v-for="value in shellUserOptions.modes"
              :key="value"
              :value="value"
              :label="$t(`settings.theme.${value}`)"
            />
          </el-select>
        </el-form-item>
        <el-form-item
          v-if="shellColorPresetOptions.length > 1"
          :label="$t('settings.colorPreset')"
        >
          <el-select
            :model-value="appearance.colorPreset"
            @change="appStore.selectColorPreset"
          >
            <el-option
              v-for="name in shellColorPresetOptions"
              :key="name"
              :value="name"
              :label="$t(`settings.colorPreset.${name}`)"
            />
          </el-select>
        </el-form-item>
        <div class="settings-tones" :aria-label="$t('settings.semanticColors')">
          <div v-for="tone in themeTones" :key="tone" class="settings-tone-row">
            <span>{{ $t(`settings.tone.${tone}`) }}</span>
            <code>{{ effectiveTheme[`--el-color-${tone}`] }}</code>
            <el-color-picker
              v-if="!toneFixed(tone)"
              :model-value="effectiveTheme[`--el-color-${tone}`]"
              :aria-label="$t(`settings.tone.${tone}`)"
              @change="(value: string | null) => appStore.setThemeColor(value, tone)"
            />
            <span v-else class="fixed-theme-color">{{
              $t('settings.color.fixed')
            }}</span>
          </div>
          <el-button link @click="appStore.resetThemeColors">{{
            $t('settings.color.reset')
          }}</el-button>
          <el-space wrap>
            <el-tag v-for="tone in themeTones" :key="tone" :type="tone">{{
              $t(`settings.tone.${tone}`)
            }}</el-tag>
          </el-space>
        </div>
        <el-form-item
          v-if="shellUserOptions.densities.length > 1"
          :label="$t('settings.density')"
        >
          <el-select
            :model-value="appearance.theme.density"
            @change="(value: ShellDensity) => appStore.updateSettings({ shellDensity: value })"
          >
            <el-option
              v-for="value in shellUserOptions.densities"
              :key="value"
              :value="value"
              :label="$t(`settings.density.${value}`)"
            />
          </el-select>
        </el-form-item>
      </el-form>
    </Block>
    <Block
      :title="$t('settings.navigationSection')"
      :description="$t('settings.navigationSection.description')"
      :options="navigationOpts"
    >
      <el-form label-position="top">
        <el-form-item
          v-if="shellUserOptions.layouts.length > 1"
          :label="$t('settings.shellLayout')"
        >
          <el-select
            :model-value="appearance.layout"
            @change="(value: ShellLayout) => appStore.updateSettings({ shellLayout: value })"
          >
            <el-option
              v-for="value in shellUserOptions.layouts"
              :key="value"
              :value="value"
              :label="$t(`settings.layout.${value}`)"
            />
          </el-select>
        </el-form-item>
        <el-form-item
          v-if="shellUserOptions.navigationModes.length > 1"
          :label="$t('settings.navigationMode')"
        >
          <el-select
            :model-value="appearance.navigationMode"
            @change="
            (value: ShellNavigationMode) =>
              appStore.updateSettings({
                shellNavigationMode: value,
                menuCollapse: false,
              })
          "
          >
            <el-option
              v-for="value in shellUserOptions.navigationModes"
              :key="value"
              :value="value"
              :label="$t(`settings.navigation.${value}`)"
            />
          </el-select>
        </el-form-item>
      </el-form>
    </Block>
    <Block
      :options="contentOpts"
      :title="$t('settings.regions')"
      :description="$t('settings.regions.description')"
    />
    <Block :options="othersOpts" :title="$t('settings.accessibility')" />
    <el-alert type="info" :closable="false">{{
      $t('settings.alertContent')
    }}</el-alert>
    <template #footer>
      <el-button @click="cancel">{{ $t('settings.close') }}</el-button>
      <el-button type="primary" @click="copySettings">{{
        $t('settings.copySettings')
      }}</el-button>
    </template>
  </el-drawer>
</template>

<script lang="ts" setup>
  import { computed } from 'vue';
  import type {
    ShellLayout,
    ShellNavigationMode,
    ShellDensity,
    ShellThemeMode,
  } from '@peanut-admin/ui-vue';
  import { ElMessage } from 'element-plus';
  import { Setting } from '@element-plus/icons-vue';
  import { useI18n } from 'vue-i18n';
  import { useClipboard } from '@vueuse/core';
  import {
    createShellTheme,
    SHELL_THEME_TOKENS,
  } from '@peanut-admin/ui-vue/theme';
  import { useAppStore } from '@/store';
  import {
    shellPresentation,
    shellUserOptions,
    shellColorPresetOptions,
  } from '@/core/runtime';
  import { themeTones, type ThemeTone } from '@/layout/shell-presets';
  import Block from './block.vue';

  const emit = defineEmits(['cancel']);

  const appStore = useAppStore();
  const { t } = useI18n();
  const { copy } = useClipboard();
  const appearance = computed(() => appStore.shellAppearance);
  const toneFixed = (tone: ThemeTone) =>
    shellPresentation.theme.colors?.[tone] !== undefined ||
    (tone === 'primary' &&
      shellPresentation.theme.tokens?.[SHELL_THEME_TOKENS.focusColor] !==
        undefined);
  const effectiveTheme = computed(() =>
    createShellTheme(appearance.value.theme)
  );
  const effectiveThemeColor = computed(
    () => effectiveTheme.value['--el-color-primary']
  );
  const contentOpts = computed(() => [
    { name: 'settings.navbar', key: 'navbar', defaultVal: appStore.navbar },
    {
      name: 'settings.menu',
      key: 'menu',
      defaultVal: appStore.menu,
    },
    { name: 'settings.footer', key: 'footer', defaultVal: appStore.footer },
    { name: 'settings.tabBar', key: 'tabBar', defaultVal: appStore.tabBar },
  ]);
  const navigationOpts = computed(() => [
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
    const text = JSON.stringify(
      {
        ...appStore.$state,
        themeColor: effectiveThemeColor.value,
        themeColors: Object.fromEntries(
          themeTones
            .filter((tone) => tone !== 'primary')
            .map((tone) => [tone, effectiveTheme.value[`--el-color-${tone}`]])
        ),
        shellColorPreset: appearance.value.colorPreset,
        semanticThemeColors: undefined,
        serverMenu: undefined,
        serverMenuLoaded: undefined,
        enabledTenantModules: undefined,
        themeColorOverride: undefined,
      },
      null,
      2
    );
    await copy(text);
    ElMessage.success(t('settings.copySettings.message'));
  };
  const setVisible = () => {
    appStore.updateSettings({ globalSettings: true });
  };
</script>

<style scoped lang="less">
  .settings-tones {
    display: grid;
    gap: 12px;
    margin-bottom: 24px;
  }
  .settings-tone-row {
    display: flex;
    gap: 12px;
    align-items: center;
    min-width: 0;
    code {
      margin-left: auto;
      color: var(--el-text-color-secondary);
      font-size: 12px;
    }
    .fixed-theme-color {
      color: var(--el-text-color-secondary);
      font-size: 12px;
    }
  }
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
