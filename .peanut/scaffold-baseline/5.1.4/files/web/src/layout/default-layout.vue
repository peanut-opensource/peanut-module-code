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
        <template #breadcrumb>
          <el-breadcrumb v-if="appearance.breadcrumb" class="tenant-breadcrumb">
            <el-breadcrumb-item v-for="item in breadcrumbs" :key="item.path">{{
              item.label
            }}</el-breadcrumb-item>
          </el-breadcrumb>
        </template>
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
        <div class="tenant-sidebar__menu">
          <Menu :navigation-mode="navigationMode" />
        </div>
        <div class="tenant-sidebar__contributions">
          <ShellContributions name="sidebar-footer" />
        </div>
        <Footer
          v-if="appStore.footer"
          sidebar
          :compact="appStore.menuCollapse"
        />
      </aside>
      <el-drawer
        v-if="appStore.hideMenu"
        class="tenant-navigation-drawer"
        v-model="drawerVisible"
        direction="ltr"
        :size="`${appStore.menuWidth}px`"
        :with-header="false"
      >
        <div class="tenant-drawer__menu">
          <Menu
            :navigation-mode="
              navigationMode === 'top' ? 'tree' : navigationMode
            "
          />
        </div>
        <div class="tenant-drawer__contributions">
          <ShellContributions name="sidebar-footer" />
        </div>
        <Footer v-if="appStore.footer" sidebar @action="drawerVisible = false" />
      </el-drawer>
    </template>
    <template #tabs><TabBar v-if="appStore.tabBar" /></template>
    <div class="tenant-page-body">
      <div class="tenant-page-tools"
        ><ShellContributions name="page-tools"
      /></div>
      <main class="tenant-content"><PageLayout /></main>
      <Footer v-if="appStore.footer" />
    </div>
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
  import useMenuTree, {
    findMenuRoutePath,
  } from '@/components/menu/use-menu-tree';
  import PageLayout from './page-layout.vue';
  import ShellContributions from './shell-contributions.vue';

  const appStore = useAppStore();
  const userStore = useUserStore();
  const router = useRouter();
  const route = useRoute();
  const permission = usePermission();
  const { t } = useI18n();
  const { menuTree } = useMenuTree();
  useResponsive(true);
  const appearance = computed(() => appStore.shellAppearance);
  const navigationMode = computed(() => appearance.value.navigationMode);
  const isTop = computed(
    () => navigationMode.value === 'top' && appStore.device !== 'mobile'
  );
  const theme = computed(() => ({
    ...appearance.value.theme,
    tokens: {
      '--pa-shell-sidebar-width': `${appStore.menuWidth}px`,
      ...appearance.value.theme.tokens,
    },
  }));
  const breadcrumbs = computed(() =>
    (findMenuRoutePath(menuTree.value, route) ?? route.matched)
      .filter((item) => item.meta?.locale || item.meta?.title)
      .map((item) => {
        const locale = String(item.meta?.locale || '');
        return {
          path: item.path,
          label: item.meta?.title ? String(item.meta.title) : t(locale),
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
  watch(
    () => route.fullPath,
    () => {
      drawerVisible.value = false;
    }
  );
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
      width: 100%;
      padding: 0;
    }
    .tenant-sidebar {
      width: var(--pa-shell-sidebar-width);
      flex-shrink: 0;
      background: var(--pa-shell-surface-color);
      border-right: 1px solid var(--el-border-color-extra-light);
      box-shadow: var(--pa-shell-sidebar-shadow);
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
      display: flex;
      flex-direction: column;
      height: calc(100vh - var(--pa-shell-header-height));
      min-height: 0;
      overflow: hidden;
      .tenant-sidebar__menu {
        flex: 1 1 auto;
        min-height: 0;
        overflow-y: auto;
      }
      .tenant-sidebar__contributions {
        flex: 0 0 auto;
      }
    }
    .tenant-drawer__menu {
      flex: 1 1 auto;
      min-height: 0;
      overflow-y: auto;
    }
    .tenant-drawer__contributions {
      flex: 0 0 auto;
    }
    .tenant-navigation-drawer .el-drawer__body {
      display: flex;
      flex-direction: column;
      min-height: 0;
      padding: 0;
      overflow: hidden;
    }
    .tenant-page-body {
      --pa-page-inset: var(--pa-shell-spacing);
      padding: var(--pa-page-inset);
    }
    .tenant-breadcrumb {
      min-width: 0;
      overflow: hidden;
      font-size: 13px;
      line-height: 20px;
      white-space: nowrap;
      .el-breadcrumb__inner {
        color: var(--el-text-color-secondary);
        font-weight: 400;
      }
      .el-breadcrumb__item:last-child .el-breadcrumb__inner {
        color: var(--el-text-color-primary);
      }
    }
    .tenant-page-tools:empty {
      display: none;
    }
    .tenant-content {
      --pa-page-header-offset-top: 0px;
      min-width: 0;
      width: 100%;
      > * {
        min-width: 0;
        max-width: 100%;
      }
    }
    .tenant-page-tools:empty + .tenant-content {
      --pa-page-header-offset-top: calc(-1 * var(--pa-page-inset));
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
  @media (max-width: 991px) {
    .tenant-shell .tenant-breadcrumb {
      display: none;
    }
  }
  @media (max-width: 768px) {
    .tenant-shell .tenant-page-body {
      --pa-page-inset: max(12px, var(--pa-shell-gap));
    }
  }
</style>
