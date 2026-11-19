<script lang="tsx">
  import {
    computed,
    defineComponent,
    h,
    nextTick,
    ref,
    watch,
    type VNode,
  } from 'vue';
  import { useI18n } from 'vue-i18n';
  import { useRoute, useRouter, RouteRecordRaw } from 'vue-router';
  import type { RouteMeta } from 'vue-router';
  import {
    ElIcon,
    ElMenu,
    ElMenuItem,
    ElMenuItemGroup,
    ElSubMenu,
    type MenuInstance,
  } from 'element-plus';
  import { Grid } from '@element-plus/icons-vue';
  import { useAppStore } from '@/store';
  import { openWindow, regexUrl } from '@/utils';
  import resolveMenuIcon from './menu-icons';
  import useMenuTree, { findMenuRoutePath } from './use-menu-tree';

  export default defineComponent({
    props: {
      navigationMode: { type: String, default: 'tree' },
    },
    emits: ['collapse'],
    setup(props) {
      const { t, getLocaleMessage } = useI18n();
      const appStore = useAppStore();
      const router = useRouter();
      const route = useRoute();
      const { menuTree } = useMenuTree();
      const menuRef = ref<MenuInstance>();
      const collapsed = computed(
        () =>
          appStore.device === 'desktop' &&
          props.navigationMode !== 'top' &&
          appStore.menuCollapse
      );

      const topMenu = computed(
        () => props.navigationMode === 'top' && appStore.device !== 'mobile'
      );
      const openKeys = ref<string[]>([]);
      const selectedKey = ref<string[]>([]);
      const submenuKeys = new Set<string>();
      const updateSelectedKey = (key?: string) => {
        selectedKey.value = key ? [key] : [];
        nextTick(() => {
          menuRef.value?.updateActiveIndex(key || '');
        });
      };

      const syncMenuState = () => {
        nextTick(() => {
          const activeKey = selectedKey.value[0];
          if (activeKey) menuRef.value?.updateActiveIndex(activeKey);
          if (!collapsed.value && !topMenu.value) {
            openKeys.value.forEach((key) => {
              if (submenuKeys.has(key)) menuRef.value?.open(key);
            });
          }
        });
      };

      watch([collapsed, () => props.navigationMode], syncMenuState, {
        flush: 'post',
      });

      const goto = (item: RouteRecordRaw) => {
        // Open external link
        if (regexUrl.test(item.path)) {
          openWindow(item.path, {
            target: item.meta?.target === '_self' ? '_self' : '_blank',
            noopener: true,
            noreferrer: true,
          });
          updateSelectedKey(item.name as string);
          return;
        }
        // Eliminate external link side effects
        const { hideInMenu, activeMenu } = item.meta as RouteMeta;
        if (route.name === item.name && !hideInMenu && !activeMenu) {
          updateSelectedKey(item.name as string);
          return;
        }
        // Trigger router change
        router.push({
          name: item.name,
        });
      };

      watch(
        [() => route.fullPath, menuTree],
        () => {
          const menuPath = findMenuRoutePath(menuTree.value, route);
          const keys = menuPath?.map((item) => String(item.name ?? '')) ?? [];
          const keySet = new Set([...keys, ...openKeys.value]);
          openKeys.value = [...keySet];
          updateSelectedKey(keys[keys.length - 1]);
          syncMenuState();
        },
        { immediate: true, flush: 'post' }
      );

      const handleOpen = (key: string) => {
        if (!openKeys.value.includes(key)) {
          openKeys.value = [...openKeys.value, key];
        }
      };

      const handleClose = (key: string) => {
        openKeys.value = openKeys.value.filter((item) => item !== key);
      };

      const renderIcon = (iconName?: unknown) => {
        if (!iconName && !collapsed.value) return null;
        if (!iconName)
          return h(ElIcon, { class: 'menu-icon' }, { default: () => h(Grid) });
        const icon = resolveMenuIcon(iconName);
        return h(ElIcon, { class: 'menu-icon' }, { default: () => h(icon) });
      };

      const renderLabel = (item: RouteRecordRaw, showIcon = true) => [
        showIcon ? renderIcon(item.meta?.icon) : null,
        h(
          'span',
          null,
          (() => {
            const locale = String(item.meta?.locale || '');
            const translated =
              locale &&
              (Object.prototype.hasOwnProperty.call(
                getLocaleMessage('zh-CN'),
                locale
              ) ||
                Object.prototype.hasOwnProperty.call(
                  getLocaleMessage('en-US'),
                  locale
                ))
                ? t(locale)
                : '';
            if (translated && translated !== locale) return translated;
            return String(item.meta?.title || locale || item.name || '');
          })()
        ),
      ];

      const renderSubMenu = () => {
        submenuKeys.clear();
        function travel(_route: RouteRecordRaw[], depth = 0): VNode[] {
          if (!_route) return [];
          return _route.map((element) => {
            const index = element.name as string;
            if (element.children?.length) {
              if (props.navigationMode === 'grouped' && !collapsed.value) {
                return h(
                  ElMenuItemGroup,
                  { key: index, class: { 'menu-section-nested': depth > 0 } },
                  {
                    title: () => renderLabel(element, false),
                    default: () =>
                      travel(element.children as RouteRecordRaw[], depth + 1),
                  }
                );
              }
              submenuKeys.add(index);
              return h(
                ElSubMenu,
                { key: index, index },
                {
                  title: () => renderLabel(element),
                  default: () =>
                    travel(element.children as RouteRecordRaw[], depth + 1),
                }
              );
            }
            return h(
              ElMenuItem,
              {
                key: index,
                index,
                class: {
                  'menu-entry-root': depth === 0,
                  'menu-entry-child': depth === 1,
                  'menu-entry-grouped':
                    props.navigationMode === 'grouped' && depth > 0,
                },
                onClick: () => goto(element),
              },
              () => renderLabel(element)
            );
          });
        }
        return travel(menuTree.value);
      };

      return () =>
        h('div', { class: 'menu-container' }, [
          h(
            ElMenu,
            {
              ref: menuRef,
              class: ['peanut-menu', `peanut-menu--${props.navigationMode}`],
              mode: topMenu.value ? 'horizontal' : 'vertical',
              collapse: collapsed.value,
              defaultOpeneds:
                collapsed.value || topMenu.value ? [] : openKeys.value,
              defaultActive: selectedKey.value[0] || '',
              collapseTransition: false,
              style: { height: '100%', width: '100%' },
              onOpen: handleOpen,
              onClose: handleClose,
            },
            { default: () => renderSubMenu() }
          ),
        ]);
    },
  });
</script>

<style lang="less" scoped>
  .menu-container {
    display: flex;
    width: 100%;
    height: 100%;
    flex-direction: column;
  }

  .peanut-menu {
    min-height: 0;
    overflow-x: hidden;
    overflow-y: auto;
    flex: 1;
    border-right: none;

    &:not(.el-menu--horizontal) {
      --el-menu-item-height: calc(var(--el-component-size) + 8px);
      --el-menu-sub-item-height: calc(var(--el-component-size) + 8px);
      padding-block: 12px;
    }
  }

  :deep(.el-menu.el-menu--collapse) {
    .el-menu-item {
      margin-inline: 0;
      padding-inline: 20px;
      border-radius: 0;
    }
    .el-sub-menu__title {
      padding-inline: 20px;
    }
  }

  :deep(.el-menu--horizontal) {
    border-bottom: none;
  }

  :deep(.el-menu-item),
  :deep(.el-sub-menu__title) {
    .el-icon {
      font-size: 18px;
    }
  }

  :deep(.peanut-menu--grouped:not(.el-menu--collapse) .el-menu-item-group) {
    margin-block: 4px 16px;
  }

  :deep(
      .peanut-menu--grouped:not(.el-menu--collapse) .el-menu-item-group__title
    ) {
    padding: 8px 24px;
    color: var(--el-text-color-secondary);
    font-size: 12px;
    font-weight: 600;
    line-height: 20px;
  }

  :deep(
      .peanut-menu:not(.el-menu--horizontal):not(.el-menu--collapse)
        .el-menu-item
    ) {
    margin: 2px 12px;
    border-radius: 6px;

    .el-icon {
      color: var(--el-text-color-secondary);
      font-size: 16px;
    }
  }

  :deep(
      .peanut-menu--grouped:not(.el-menu--horizontal):not(.el-menu--collapse)
        .menu-entry-grouped
    ) {
    margin-inline: 16px 12px;
    padding-inline: 16px 12px;
    font-weight: 400;
  }

  :deep(
      .peanut-menu:not(.el-menu--horizontal):not(.el-menu--collapse)
        > .menu-entry-root
    ),
  :deep(
      .peanut-menu:not(.el-menu--horizontal):not(.el-menu--collapse)
        > .el-sub-menu
        > .el-sub-menu__title
    ) {
    margin: 2px 12px;
    padding-inline: 12px;
    border-radius: 6px;
    font-weight: 500;
  }

  :deep(.peanut-menu--tree:not(.el-menu--collapse) .menu-entry-child) {
    padding-inline: 24px 12px;
  }

  :deep(.peanut-menu--grouped:not(.el-menu--collapse) .menu-section-nested) {
    margin-left: 12px;
  }

  :deep(
      .peanut-menu--grouped:not(.el-menu--collapse)
        .el-menu-item-group
        .el-menu-item.is-active
    ) {
    color: var(--el-color-primary);
    background-color: var(--el-color-primary-light-9);

    .el-icon {
      color: inherit;
    }
  }
</style>
