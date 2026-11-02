<template>
  <div class="navbar">
    <div class="left-side">
      <div class="left-content">
        <img
          v-if="brand?.logo || brandStore.website.web_logo"
          alt="logo"
          :src="brand?.logo || brandStore.website.web_logo"
          style="width: 28px; height: 28px"
        />
        <h5 class="brand-title" :title="brandTitle" :aria-label="brandTitle">
          {{ brandTitle }}
        </h5>
        <el-button
          v-if="!topMenu && appStore.device === 'mobile'"
          class="mobile-menu-button"
          text
          circle
          :aria-label="$t('settings.menu')"
          @click="toggleDrawerMenu"
        >
          <el-icon><Menu /></el-icon>
        </el-button>
      </div>
    </div>
    <div class="center-side">
      <MenuComponent v-if="topMenu" navigation-mode="top" />
    </div>
    <slot name="header-context" />
    <ul class="right-side">
      <li><slot name="header-actions" /></li>
      <li>
        <el-tooltip :content="$t('settings.search')">
          <el-button
            ref="menuSearchTrigger"
            class="nav-btn"
            plain
            circle
            :aria-label="$t('settings.search')"
            @click="openMenuSearch"
          >
            <el-icon><Search /></el-icon>
          </el-button>
        </el-tooltip>
      </li>
      <li class="desktop-action">
        <el-tooltip :content="$t('settings.language')">
          <el-dropdown trigger="click" @command="handleLocaleChange">
            <el-button
              class="nav-btn"
              plain
              circle
              :aria-label="$t('settings.language')"
            >
              <el-icon><Platform /></el-icon>
            </el-button>
            <template #dropdown>
              <el-dropdown-menu>
                <el-dropdown-item
                  v-for="item in locales"
                  :key="item.value"
                  :command="item.value"
                >
                  <el-icon v-if="item.value === currentLocale"
                    ><Check
                  /></el-icon>
                  <span v-else class="dropdown-icon-placeholder" />
                  {{ item.label }}
                </el-dropdown-item>
              </el-dropdown-menu>
            </template>
          </el-dropdown>
        </el-tooltip>
      </li>
      <li v-if="shellUserOptions.modes.length > 1" class="desktop-action">
        <el-tooltip
          :content="
            theme === 'light'
              ? $t('settings.navbar.theme.toDark')
              : $t('settings.navbar.theme.toLight')
          "
        >
          <el-button
            class="nav-btn"
            plain
            circle
            :aria-label="
              theme === 'light'
                ? $t('settings.navbar.theme.toDark')
                : $t('settings.navbar.theme.toLight')
            "
            @click="handleToggleTheme"
          >
            <el-icon><Moon v-if="theme === 'dark'" /><Sunny v-else /></el-icon>
          </el-button>
        </el-tooltip>
      </li>
      <li class="desktop-action">
        <el-tooltip
          :content="
            isFullscreen
              ? $t('settings.navbar.screen.toExit')
              : $t('settings.navbar.screen.toFull')
          "
        >
          <el-button
            class="nav-btn"
            plain
            circle
            :aria-label="
              isFullscreen
                ? $t('settings.navbar.screen.toExit')
                : $t('settings.navbar.screen.toFull')
            "
            @click="toggleFullScreen"
          >
            <el-icon>
              <ScaleToOriginal v-if="isFullscreen" />
              <FullScreen v-else />
            </el-icon>
          </el-button>
        </el-tooltip>
      </li>
      <li class="desktop-action">
        <el-tooltip :content="$t('settings.title')">
          <el-button
            class="nav-btn"
            plain
            circle
            :aria-label="$t('settings.title')"
            @click="setVisible"
          >
            <el-icon><Setting /></el-icon>
          </el-button>
        </el-tooltip>
      </li>
      <li v-if="appStore.device === 'mobile'">
        <el-dropdown trigger="click" @command="handleMoreCommand">
          <el-button
            class="nav-btn"
            plain
            circle
            :aria-label="$t('settings.navbar.more')"
          >
            <el-icon><MoreFilled /></el-icon>
          </el-button>
          <template #dropdown>
            <el-dropdown-menu>
              <el-dropdown-item disabled>{{
                $t('settings.language')
              }}</el-dropdown-item>
              <el-dropdown-item
                v-for="item in locales"
                :key="item.value"
                :command="`locale:${item.value}`"
              >
                <el-icon v-if="item.value === currentLocale"><Check /></el-icon>
                {{ item.label }}
              </el-dropdown-item>
              <el-dropdown-item
                v-if="shellUserOptions.modes.length > 1"
                command="theme"
                >{{
                  theme === 'light'
                    ? $t('settings.navbar.theme.toDark')
                    : $t('settings.navbar.theme.toLight')
                }}</el-dropdown-item
              >
              <el-dropdown-item command="fullscreen">{{
                isFullscreen
                  ? $t('settings.navbar.screen.toExit')
                  : $t('settings.navbar.screen.toFull')
              }}</el-dropdown-item>
              <el-dropdown-item command="settings">{{
                $t('settings.title')
              }}</el-dropdown-item>
            </el-dropdown-menu>
          </template>
        </el-dropdown>
      </li>
      <li>
        <el-dropdown trigger="click" @command="handleUserCommand">
          <button
            type="button"
            class="user-menu-trigger"
            :aria-label="$t('navbar.userMenu')"
          >
            <el-avatar :size="32" :src="avatar" alt="">
              {{ avatarInitial }}
            </el-avatar>
          </button>
          <template #dropdown>
            <el-dropdown-menu>
              <el-dropdown-item command="settings">
                <el-icon><Setting /></el-icon>
                <span>{{ $t('navbar.userSettings') }}</span>
              </el-dropdown-item>
              <el-dropdown-item
                v-if="tenantSession && userStore.canSwitchTenant"
                command="tenant-switch"
              >
                <el-icon><Switch /></el-icon>
                <span>{{ $t('navbar.tenantSwitch') }}</span>
              </el-dropdown-item>
              <el-dropdown-item command="logout">
                <el-icon><SwitchButton /></el-icon>
                <span>{{ $t('navbar.logout') }}</span>
              </el-dropdown-item>
            </el-dropdown-menu>
          </template>
        </el-dropdown>
      </li>
    </ul>
    <el-dialog
      v-model="menuSearchVisible"
      :title="$t('settings.search')"
      width="min(420px, calc(100vw - 32px))"
      destroy-on-close
      @opened="menuSearchInput?.focus()"
      @closed="menuSearchTrigger?.ref?.focus({ preventScroll: true })"
    >
      <el-input
        ref="menuSearchInput"
        v-model="menuSearchQuery"
        :placeholder="$t('settings.search')"
        clearable
        autofocus
        @keyup.enter="openFirstMenuSearchResult"
      />
      <div v-if="menuSearchResults.length" class="menu-search-results">
        <el-button
          v-for="item in menuSearchResults"
          :key="String(item.name)"
          class="menu-search-result"
          text
          @click="openMenuSearchResult(item)"
        >
          {{ getMenuLabel(item) }}
        </el-button>
      </div>
      <p
        v-else-if="menuSearchQuery.trim()"
        class="menu-search-empty"
        role="status"
      >
        {{ $t('settings.search.empty') }}
      </p>
    </el-dialog>
    <el-dialog
      v-model="tenantSwitchVisible"
      :title="$t('navbar.tenantSwitch')"
      width="min(420px, calc(100vw - 32px))"
      destroy-on-close
    >
      <el-select
        v-model="selectedTenantId"
        :placeholder="$t('navbar.tenantSelect')"
        style="width: 100%"
      >
        <el-option
          v-for="tenant in tenantChoices"
          :key="tenant.tenant_id"
          :label="tenant.tenant_name"
          :value="tenant.tenant_id"
        />
      </el-select>
      <template #footer>
        <el-button @click="tenantSwitchVisible = false">
          {{ $t('navbar.cancel') }}
        </el-button>
        <el-button
          type="primary"
          :loading="tenantSwitching"
          @click="confirmTenantSwitch"
        >
          {{ $t('navbar.confirm') }}
        </el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script lang="ts" setup>
  import { computed, inject, ref } from 'vue';
  import type { ButtonInstance, InputInstance } from 'element-plus';
  import { useI18n } from 'vue-i18n';
  import { useFullscreen } from '@vueuse/core';
  import { useRouter } from 'vue-router';
  import type { RouteRecordRaw } from 'vue-router';
  import {
    Check,
    FullScreen,
    Menu,
    MoreFilled,
    Moon,
    Platform,
    ScaleToOriginal,
    Search,
    Setting,
    Sunny,
    Switch,
    SwitchButton,
  } from '@element-plus/icons-vue';
  import { useAppStore, useBrandStore, useUserStore } from '@/store';
  import { shellUserOptions } from '@/peanut.overrides';
  import { LOCALE_OPTIONS } from '@/locale';
  import useLocale from '@/hooks/locale';
  import useUser from '@/hooks/user';
  import MenuComponent from '@/components/menu/index.vue';
  import useMenuTree from '@/components/menu/use-menu-tree';
  import { selectTenant, tenantSwitch } from '@/api/tenant-session';
  import { disposeTenantState, type TenantChoice } from '@peanut-admin/vue';
  import {
    advanceSessionGeneration,
    getSessionSnapshot,
    getToken,
    setToken,
  } from '@/utils/auth';

  const appStore = useAppStore();
  const brandStore = useBrandStore();
  const userStore = useUserStore();
  const router = useRouter();
  const { logout } = useUser();
  const { t, te } = useI18n();
  const { menuTree } = useMenuTree();
  const { changeLocale, currentLocale } = useLocale();
  const { isFullscreen, toggle: toggleFullScreen } = useFullscreen();
  const locales = [...LOCALE_OPTIONS];
  const avatar = computed(
    () => userStore.avatar?.trim() || '/brand/avatar-admin.svg'
  );
  const avatarInitial = computed(() => {
    const identity = userStore.name?.trim() || userStore.email?.trim() || '?';
    return Array.from(identity)[0]?.toLocaleUpperCase() || '?';
  });
  const props = defineProps<{
    navigationMode?: 'tree' | 'grouped' | 'top';
    brand?: { name?: string; logo?: string };
  }>();
  const brandTitle = computed(
    () =>
      props.brand?.name ||
      (userStore.tenantName
        ? `${userStore.tenantName} - ${brandStore.website.name}`
        : brandStore.website.name)
  );
  const theme = computed(() => {
    return appStore.shellAppearance.theme.mode;
  });
  const topMenu = computed(
    () =>
      (props.navigationMode === undefined
        ? appStore.shellAppearance.navigationMode === 'top'
        : props.navigationMode === 'top') &&
      appStore.menu &&
      appStore.device !== 'mobile'
  );
  const tenantSession = computed(
    () => getToken()?.startsWith('pa_tat_') === true
  );
  const tenantSwitchVisible = ref(false);
  const tenantSwitching = ref(false);
  const tenantChoices = ref<TenantChoice[]>([]);
  const tenantChallenge = ref('');
  const selectedTenantId = ref<number>();
  const menuSearchVisible = ref(false);
  const menuSearchQuery = ref('');
  const menuSearchTrigger = ref<ButtonInstance>();
  const menuSearchInput = ref<InputInstance>();
  const searchableMenuItems = computed(() => {
    const items: RouteRecordRaw[] = [];
    const collect = (routes: RouteRecordRaw[]) => {
      routes.forEach((item) => {
        if (item.children?.length) collect(item.children);
        else if (item.name) items.push(item);
      });
    };
    collect(menuTree.value);
    return items;
  });
  const getMenuLabel = (item: RouteRecordRaw) => {
    const locale = String(item.meta?.locale || '');
    return locale && te(locale)
      ? t(locale)
      : String(item.meta?.title || locale || item.name || '');
  };
  const menuSearchResults = computed(() => {
    const query = menuSearchQuery.value.trim().toLocaleLowerCase();
    if (!query) return searchableMenuItems.value;
    return searchableMenuItems.value.filter((item) =>
      getMenuLabel(item).toLocaleLowerCase().includes(query)
    );
  });
  const openMenuSearch = () => {
    menuSearchQuery.value = '';
    menuSearchVisible.value = true;
  };
  const openMenuSearchResult = (item: RouteRecordRaw) => {
    menuSearchVisible.value = false;
    router.push({ name: item.name });
  };
  const openFirstMenuSearchResult = () => {
    const [first] = menuSearchResults.value;
    if (first) openMenuSearchResult(first);
  };
  const handleToggleTheme = () => {
    appStore.toggleTheme(theme.value !== 'dark');
  };
  const setVisible = () => {
    appStore.updateSettings({ globalSettings: true });
  };
  const handleLocaleChange = (value: string) => {
    changeLocale(value);
  };
  const handleMoreCommand = (command: string) => {
    if (command.startsWith('locale:')) handleLocaleChange(command.slice(7));
    else if (command === 'theme') handleToggleTheme();
    else if (command === 'fullscreen') toggleFullScreen();
    else if (command === 'settings') setVisible();
  };
  const beginTenantSwitch = async () => {
    const snapshot = getSessionSnapshot();
    const token = getToken();
    if (!token) return;
    const selection = await tenantSwitch(token);
    const current = getSessionSnapshot();
    if (
      snapshot.generation !== current.generation ||
      snapshot.token !== current.token
    )
      return;
    tenantChallenge.value = selection.challenge_token;
    tenantChoices.value = selection.tenants;
    selectedTenantId.value = selection.tenants[0]?.tenant_id;
    tenantSwitchVisible.value = true;
  };
  const confirmTenantSwitch = async () => {
    if (!tenantChallenge.value || !selectedTenantId.value) return;
    let generation = advanceSessionGeneration();
    const token = getToken();
    tenantSwitching.value = true;
    try {
      const authenticated = await selectTenant(
        tenantChallenge.value,
        selectedTenantId.value,
        generation
      );
      const beforeDispose = getSessionSnapshot();
      if (
        beforeDispose.generation !== generation ||
        beforeDispose.token !== token
      )
        return;
      await disposeTenantState();
      const beforeCommit = getSessionSnapshot();
      if (
        beforeCommit.generation !== generation ||
        beforeCommit.token !== token
      )
        return;
      generation = advanceSessionGeneration();
      setToken(authenticated.access_token);
      tenantSwitchVisible.value = false;
      userStore.resetInfo();
      appStore.clearServerMenu();
      await userStore.info();
      if (getSessionSnapshot().generation !== generation) return;
      window.location.reload();
    } finally {
      tenantSwitching.value = false;
    }
  };
  const handleUserCommand = (command: string) => {
    if (command === 'settings') {
      router.push({ name: 'Setting' });
      return;
    }
    if (command === 'tenant-switch') {
      beginTenantSwitch();
      return;
    }
    if (command === 'logout') {
      logout();
    }
  };
  const toggleDrawerMenu = inject('toggleDrawerMenu') as () => void;
</script>

<style scoped lang="less">
  .navbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    min-width: 0;
    height: 100%;
    background-color: var(--el-bg-color);
    border-bottom: 1px solid var(--el-border-color);
  }

  .left-side {
    display: flex;
    align-items: center;
    min-width: 0;
    flex: 0 1 auto;
    padding-left: 20px;
  }

  .left-content {
    display: flex;
    align-items: center;
    gap: 12px;
    min-width: 0;
    img,
    .mobile-menu-button {
      flex-shrink: 0;
    }
  }

  .brand-title {
    margin: 0;
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    color: var(--el-text-color-primary);
    font-size: 18px;
    font-weight: 600;
  }

  .mobile-menu-button {
    font-size: 22px;
    cursor: pointer;
  }

  .center-side {
    flex: 1;
    min-width: 0;
  }

  .right-side {
    display: flex;
    align-items: center;
    flex-shrink: 0;
    margin: 0;
    padding-left: 0;
    padding-right: 20px;
    list-style: none;
    li {
      display: flex;
      align-items: center;
      padding: 0 10px;
    }

    .nav-btn {
      border-color: var(--el-border-color);
      color: var(--el-text-color-regular);
      font-size: 16px;
    }

    .user-menu-trigger {
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 0;
      border: 0;
      border-radius: 50%;
      background: transparent;
      cursor: pointer;

      &:focus-visible {
        outline: 2px solid var(--el-color-primary);
        outline-offset: 2px;
      }
    }

    .dropdown-icon-placeholder {
      display: inline-block;
      width: 14px;
      height: 14px;
    }
  }
  @media (max-width: 991px) {
    .left-side {
      padding-left: 8px;
    }
    .left-content {
      gap: 6px;
    }
    .brand-title {
      font-size: 15px;
    }
    .center-side,
    .desktop-action {
      display: none;
    }
    .right-side {
      padding-right: 8px;
    }
    .right-side li {
      padding: 0 3px;
    }
  }

  .menu-search-results {
    display: flex;
    max-height: 320px;
    margin-top: 12px;
    flex-direction: column;
    align-items: stretch;
    overflow-y: auto;
  }

  .menu-search-result {
    justify-content: flex-start;
    overflow: hidden;
    text-align: left;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .menu-search-empty {
    margin: 16px 0 4px;
    color: var(--el-text-color-secondary);
    text-align: center;
  }
</style>
