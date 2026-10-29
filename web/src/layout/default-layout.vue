<template>
  <el-container class="layout" :class="{ mobile: appStore.hideMenu }">
    <div v-if="navbar" class="layout-navbar">
      <NavBar />
    </div>
    <el-container class="is-vertical">
      <el-container>
        <el-aside
          v-if="renderMenu"
          v-show="!hideMenu"
          class="layout-sider"
          :width="`${menuWidth}px`"
          :style="{ paddingTop: navbar ? '60px' : '' }"
        >
          <div class="menu-wrapper">
            <Menu />
          </div>
        </el-aside>
        <el-drawer
          v-if="hideMenu"
          v-model="drawerVisible"
          direction="ltr"
          :size="`${appStore.menuWidth}px`"
          :with-header="false"
          :show-close="false"
          @close="drawerCancel"
        >
          <Menu />
        </el-drawer>
        <el-container class="layout-content" :style="paddingStyle">
          <TabBar v-if="appStore.tabBar" />
          <el-main class="layout-main">
            <PageLayout />
          </el-main>
          <Footer v-if="footer" />
        </el-container>
      </el-container>
    </el-container>
  </el-container>
</template>

<script lang="ts" setup>
  import { ref, computed, watch, provide } from 'vue';
  import { useRouter, useRoute } from 'vue-router';
  import { useAppStore, useUserStore } from '@/store';
  import NavBar from '@/components/navbar/index.vue';
  import Menu from '@/components/menu/index.vue';
  import Footer from '@/components/footer/index.vue';
  import TabBar from '@/components/tab-bar/index.vue';
  import usePermission from '@/hooks/permission';
  import useResponsive from '@/hooks/responsive';
  import PageLayout from './page-layout.vue';

  const appStore = useAppStore();
  const userStore = useUserStore();
  const router = useRouter();
  const route = useRoute();
  const permission = usePermission();
  useResponsive(true);
  const navbarHeight = `60px`;
  const navbar = computed(() => appStore.navbar);
  const renderMenu = computed(
    () => appStore.menu && (!appStore.topMenu || appStore.device === 'mobile')
  );
  const hideMenu = computed(() => appStore.hideMenu);
  const footer = computed(() => appStore.footer);
  const menuWidth = computed(() => {
    return appStore.menuCollapse ? 48 : appStore.menuWidth;
  });
  const paddingStyle = computed(() => {
    const paddingLeft =
      renderMenu.value && !hideMenu.value
        ? { paddingLeft: `${menuWidth.value}px` }
        : {};
    const paddingTop = navbar.value ? { paddingTop: navbarHeight } : {};
    return { ...paddingLeft, ...paddingTop };
  });
  watch(
    () => userStore.role,
    (roleValue) => {
      if (roleValue && !permission.accessRouter(route))
        router.push({ name: 'notFound' });
    }
  );
  const drawerVisible = ref(false);
  const drawerCancel = () => {
    drawerVisible.value = false;
  };
  provide('toggleDrawerMenu', () => {
    drawerVisible.value = !drawerVisible.value;
  });
</script>

<style scoped lang="less">
  @nav-size-height: 60px;
  @layout-max-width: 1100px;

  .layout {
    width: 100%;
    height: 100%;
    flex-direction: column;

    > :deep(.el-container) {
      min-height: 0;
    }
  }

  .layout-navbar {
    position: fixed;
    top: 0;
    left: 0;
    z-index: 100;
    width: 100%;
    height: @nav-size-height;
  }

  .layout-sider {
    position: fixed;
    top: 0;
    left: 0;
    z-index: 99;
    height: 100%;
    transition: all 0.2s cubic-bezier(0.34, 0.69, 0.1, 1);
    &::after {
      position: absolute;
      top: 0;
      right: -1px;
      display: block;
      width: 1px;
      height: 100%;
      background-color: var(--el-border-color);
      content: '';
    }

    overflow-y: hidden;
  }

  .menu-wrapper {
    height: 100%;
    overflow: hidden;
    :deep(.el-menu) {
      ::-webkit-scrollbar {
        width: 12px;
        height: 4px;
      }

      ::-webkit-scrollbar-thumb {
        border: 4px solid transparent;
        background-clip: padding-box;
        border-radius: 7px;
        background-color: var(--el-text-color-placeholder);
      }

      ::-webkit-scrollbar-thumb:hover {
        background-color: var(--el-text-color-secondary);
      }
    }
  }

  .layout-content {
    min-width: 0;
    min-height: 100vh;
    overflow-y: hidden;
    flex-direction: column;
    background-color: var(--el-fill-color-light);
    transition: padding 0.2s cubic-bezier(0.34, 0.69, 0.1, 1);
  }

  .layout-main {
    min-width: 0;
    padding: 0;
  }

  :deep(.el-drawer__body) {
    padding: 0;
  }
</style>
