<template>
  <footer
    v-if="sidebar && menuEnabled"
    class="footer sidebar-footer"
    :class="{ 'is-compact': compact }"
  >
    <el-dropdown
      class="footer-actions"
      trigger="click"
      :placement="appStore.device === 'mobile' ? 'top-start' : 'right-end'"
      :offset="4"
      :show-arrow="false"
      @command="handleAction"
    >
      <el-button
        class="footer-menu-trigger"
        :aria-label="menuTitle"
        :title="compact ? menuTitle : undefined"
        text
      >
        <span v-if="!compact" class="footer-menu-title">{{ menuTitle }}</span>
        <el-icon class="footer-menu-more"><MoreFilled /></el-icon>
      </el-button>
      <template #dropdown>
        <el-dropdown-menu class="footer-dropdown-menu">
          <el-dropdown-item v-if="showSettings" command="settings"
            ><el-icon><Setting /></el-icon
            >{{ $t('settings.title') }}</el-dropdown-item
          >
          <el-dropdown-item v-if="showLicense" command="license"
            ><el-icon><Key /></el-icon
            >{{ $t('footer.license') }}</el-dropdown-item
          >
          <el-dropdown-item
            v-if="showAbout"
            command="about"
            :divided="showSettings || showLicense"
            ><el-icon><InfoFilled /></el-icon
            >{{ $t('footer.about') }}</el-dropdown-item
          >
        </el-dropdown-menu>
      </template>
    </el-dropdown>
    <el-dialog
      v-model="licenseVisible"
      :title="$t('footer.license')"
      width="min(540px, calc(100vw - 24px))"
      append-to-body
      destroy-on-close
    >
      <p class="license-description">{{ $t('footer.license.description') }}</p>
      <TenantLicense v-if="licenseVisible" />
    </el-dialog>
    <el-dialog
      v-model="aboutVisible"
      :title="$t('footer.about')"
      width="min(360px, calc(100vw - 24px))"
      append-to-body
    >
      <dl class="about-details">
        <dt>{{ $t('footer.brand') }}</dt>
        <dd>{{ brandStore.website.name }}</dd>
        <dt>{{ $t('footer.version') }}</dt>
        <dd>
          {{ version }}
          <span v-if="isDevelopment" class="environment">
            DEV · {{ $t('footer.development') }}
          </span>
        </dd>
        <dt>{{ $t('footer.copyright') }}</dt>
        <dd>
          © {{ new Date().getFullYear() }} {{ brandStore.website.copyright }}
        </dd>
      </dl>
    </el-dialog>
  </footer>
  <footer v-else-if="!sidebar" class="footer">
    © {{ new Date().getFullYear() }} {{ brandStore.website.copyright }}
  </footer>
</template>

<script lang="ts" setup>
  import { computed, ref } from 'vue';
  import { useI18n } from 'vue-i18n';
  import {
    InfoFilled,
    Key,
    MoreFilled,
    Setting,
  } from '@element-plus/icons-vue';
  import { useAppStore, useBrandStore } from '@/store';
  import TenantLicense from '@/components/tenant-license/index.vue';
  import packageJson from '../../../package.json';

  withDefaults(defineProps<{ sidebar?: boolean; compact?: boolean }>(), {
    sidebar: false,
    compact: false,
  });

  const brandStore = useBrandStore();
  const appStore = useAppStore();
  const { t } = useI18n();
  const emit = defineEmits<{ action: [] }>();
  const showSettings = computed(
    () => brandStore.website.sidebar_footer_settings
  );
  const showLicense = computed(() => brandStore.website.sidebar_footer_license);
  const showAbout = computed(() => brandStore.website.sidebar_footer_about);
  const menuEnabled = computed(
    () =>
      brandStore.website.sidebar_footer_enabled &&
      (showSettings.value || showLicense.value || showAbout.value)
  );
  const menuTitle = computed(
    () => brandStore.website.sidebar_footer_title || t('footer.tools')
  );
  const aboutVisible = ref(false);
  const licenseVisible = ref(false);
  const isDevelopment = import.meta.env.DEV;
  const { version } = packageJson;
  const handleAction = (action: 'settings' | 'license' | 'about') => {
    emit('action');
    if (action === 'settings')
      appStore.updateSettings({ globalSettings: true });
    else if (action === 'license') licenseVisible.value = true;
    else aboutVisible.value = true;
  };
</script>

<style lang="less" scoped>
  .footer {
    display: flex;
    align-items: center;
    justify-content: center;
    height: 40px;
    color: var(--el-text-color-secondary);
    text-align: center;
  }
  .sidebar-footer {
    flex: 0 0 auto;
    height: auto;
    min-height: 40px;
    padding: 8px 12px;
    border-top: 1px solid var(--el-border-color-extra-light);
    .footer-actions {
      width: 100%;
    }
    .footer-menu-trigger {
      width: 100%;
      min-height: 32px;
      padding-inline: 12px;
      justify-content: flex-start;
      color: var(--el-text-color-secondary);

      :deep(> span) {
        display: flex;
        align-items: center;
        gap: 12px;
        width: 100%;
        min-width: 0;
      }
    }
    &.is-compact .footer-menu-trigger {
      justify-content: center;
      padding-inline: 0;
      :deep(> span) {
        justify-content: center;
      }
    }
  }
  .footer-menu-title {
    flex: 1;
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    text-align: left;
  }
  .footer-menu-more {
    flex: 0 0 auto;
  }
  .footer-dropdown-menu {
    min-width: 180px;
  }
  .license-description {
    margin: 0 0 16px;
    color: var(--el-text-color-secondary);
    line-height: 22px;
  }
  .about-details {
    display: grid;
    grid-template-columns: max-content 1fr;
    gap: 12px;
    margin: 0;
    dt {
      color: var(--el-text-color-secondary);
    }
    dd {
      min-width: 0;
      margin: 0;
      overflow-wrap: anywhere;
    }
  }
  .environment {
    display: block;
    margin-top: 4px;
    color: var(--el-color-warning);
    font-size: 12px;
  }
</style>
