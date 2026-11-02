<template>
  <component
    :is="workspaceShell"
    class="tenant-shell"
    :theme="theme"
    :layout="appearance.layout"
    :navigation-mode="navigationMode"
  >
    <template #header>
      <NavBar
        v-if="appStore.navbar"
        :navigation-mode="navigationMode"
        :brand="appearance.brand"
      >
        <template #header-context
          ><ShellContributions name="header-context"
        /></template>
        <template #header-actions
          ><ShellContributions name="header-actions"
        /></template>
      </NavBar>
    </template>
    <template #sidebar>
      <aside
        v-if="appStore.menu && !isTop && !appStore.hideMenu"
        class="tenant-sidebar"
        :class="{
          'is-collapsed': appStore.menuCollapse,
        }"
      >
        <Menu :navigation-mode="navigationMode" />
        <ShellContributions name="sidebar-footer" />
      </aside>
      <el-drawer
        v-if="appStore.hideMenu"
        v-model="drawerVisible"
        direction="ltr"
        :size="`${appStore.menuWidth}px`"
        :with-header="false"
      >
        <Menu
          :navigation-mode="navigationMode === 'top' ? 'tree' : navigationMode"
        />
        <ShellContributions name="sidebar-footer" />
      </el-drawer>
    </template>
    <template #breadcrumb>
      <el-breadcrumb v-if="appearance.breadcrumb" class="tenant-breadcrumb">
        <el-breadcrumb-item v-for="item in breadcrumbs" :key="item.path">{{
          item.label
        }}</el-breadcrumb-item>
      </el-breadcrumb>
    </template>
    <template #tabs><TabBar v-if="appStore.tabBar" /></template>
    <TenantLicense />
    <div class="tenant-page-tools"
      ><ShellContributions name="page-tools"
    /></div>
    <main class="tenant-content"><PageLayout /></main>
    <Footer v-if="appStore.footer" />
  </component>
</template>

<script lang="ts" setup>
  import { ref, computed, watch, provide } from 'vue';
  import { useRouter, useRoute } from 'vue-router';
  import { useI18n } from 'vue-i18n';
  import { useAppStore, useUserStore } from '@/store';
  import { workspaceShell } from '@/core/runtime';
  import NavBar from '@/components/navbar/index.vue';
  import Menu from '@/components/menu/index.vue';
  import Footer from '@/components/footer/index.vue';
  import TabBar from '@/components/tab-bar/index.vue';
  import usePermission from '@/hooks/permission';
  import useResponsive from '@/hooks/responsive';
  import TenantLicense from '@/components/tenant-license/index.vue';
  import PageLayout from './page-layout.vue';
  import ShellContributions from './shell-contributions.vue';

  const appStore = useAppStore();
  const userStore = useUserStore();
  const router = useRouter();
  const route = useRoute();
  const permission = usePermission();
  const { t } = useI18n();
  useResponsive(true);
  const appearance = computed(() => appStore.shellAppearance);
  const navigationMode = computed(() => appearance.value.navigationMode);
  const isTop = computed(
    () => navigationMode.value === 'top' && appStore.device !== 'mobile'
  );
  const theme = computed(() => ({
    ...appearance.value.theme,
    colors: { primary: appStore.themeColor, ...appearance.value.theme.colors },
    tokens: {
      '--pa-shell-sidebar-width': `${appStore.menuWidth}px`,
      ...appearance.value.theme.tokens,
    },
  }));
  const breadcrumbs = computed(() =>
    route.matched
      .filter((item) => item.meta.locale || item.meta.title)
      .map((item) => {
        const locale = String(item.meta.locale || '');
        return {
          path: item.path,
          label: locale
            ? t(locale)
            : String(item.meta.title || item.name || ''),
        };
      })
  );
  watch(
    () => userStore.role,
    (roleValue) => {
      if (roleValue && !permission.accessRouter(route))
        router.push({ name: 'notFound' });
    }
  );
  const drawerVisible = ref(false);
  provide('toggleDrawerMenu', () => {
    drawerVisible.value = !drawerVisible.value;
  });
</script>

<style lang="less">
  .tenant-shell {
    min-height: 100vh;
    background: var(--el-bg-color-page);
    .navbar {
      height: var(--pa-shell-header-height);
    }
    .pa-shell__workspace {
      display: flex;
      min-height: calc(100vh - var(--pa-shell-header-height));
    }
    .pa-shell__main {
      flex: 1;
      min-width: 0;
      padding: var(--pa-shell-spacing);
    }
    .tenant-sidebar {
      width: var(--pa-shell-sidebar-width);
      flex-shrink: 0;
      background: var(--pa-shell-surface-color);
      border-right: 1px solid var(--pa-shell-border-color);
      transition: width var(--pa-shell-motion-duration);
      &.is-collapsed {
        width: var(--pa-shell-sidebar-collapsed-width);
        .el-menu-item-group__title {
          display: none;
        }
      }
      .menu-container {
        height: auto;
        min-height: 100%;
      }
    }
    .tenant-breadcrumb {
      margin-bottom: var(--pa-shell-spacing);
    }
    .tenant-page-tools:empty {
      display: none;
    }
    .tenant-content {
      min-width: 0;
    }
    &.pa-shell--layout-workspace .pa-shell__main {
      max-width: var(--pa-shell-content-max-width);
      margin-inline: auto;
    }
    &.pa-shell--navigation-top .pa-shell__workspace {
      flex-direction: column;
    }
    a:focus-visible,
    button:focus-visible {
      outline-color: var(--pa-shell-focus-color);
    }
  }
  @media (max-width: 768px) {
    .tenant-shell .pa-shell__main {
      padding: var(--pa-shell-gap);
    }
  }
</style>
