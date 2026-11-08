<script lang="tsx">
  import {
    computed,
    defineComponent,
    h,
    nextTick,
    ref,
    type Component,
    type VNode,
  } from 'vue';
  import { useI18n } from 'vue-i18n';
  import { useRoute, useRouter, RouteRecordRaw } from 'vue-router';
  import type { RouteMeta } from 'vue-router';
  import {
    ElButton,
    ElIcon,
    ElMenu,
    ElMenuItem,
    ElMenuItemGroup,
    ElSubMenu,
    type MenuInstance,
  } from 'element-plus';
  import {
    Back,
    Bell,
    Briefcase,
    Brush,
    Clock,
    Connection,
    CreditCard,
    DataAnalysis,
    Document,
    Folder,
    Grid,
    Key,
    Lightning,
    Menu as MenuIcon,
    Monitor,
    Notebook,
    Postcard,
    PriceTag,
    Search,
    Service,
    Setting,
    Share,
    Tools,
    User,
  } from '@element-plus/icons-vue';
  import { useAppStore } from '@/store';
  import { listenerRouteChange } from '@/utils/route-listener';
  import { openWindow, regexUrl } from '@/utils';
  import useMenuTree from './use-menu-tree';

  const iconMap: Record<string, Component> = {
    'icon-apps': Grid,
    'icon-bar-chart': DataAnalysis,
    'icon-brush': Brush,
    'icon-code': Tools,
    'icon-clock-circle': Clock,
    'icon-customer-service': Service,
    'icon-dashboard': Monitor,
    'icon-desktop': Monitor,
    'icon-file': Document,
    'icon-fingerprint': Key,
    'icon-folder': Folder,
    'icon-history': Clock,
    'icon-idcard': Postcard,
    'icon-menu': MenuIcon,
    'icon-mind-mapping': Connection,
    'icon-notification': Bell,
    'icon-palette': Brush,
    'icon-payment': CreditCard,
    'icon-search': Search,
    'icon-settings': Setting,
    'icon-share-alt': Share,
    'icon-storage': Folder,
    'icon-tag': PriceTag,
    'icon-thunderbolt': Lightning,
    'icon-tool': Tools,
    'icon-undo': Back,
    'icon-user': User,
    'icon-user-group': Briefcase,
    'icon-book': Notebook,
  };

  export default defineComponent({
    props: {
      navigationMode: { type: String, default: 'tree' },
    },
    emits: ['collapse'],
    setup(props) {
      const { t, te } = useI18n();
      const appStore = useAppStore();
      const router = useRouter();
      const route = useRoute();
      const { menuTree } = useMenuTree();
      const menuRef = ref<MenuInstance>();
      const collapsed = computed({
        get() {
          if (appStore.device === 'desktop' && props.navigationMode !== 'top')
            return appStore.menuCollapse;
          return false;
        },
        set(value: boolean) {
          appStore.updateSettings({ menuCollapse: value });
        },
      });

      const topMenu = computed(
        () => props.navigationMode === 'top' && appStore.device !== 'mobile'
      );
      const openKeys = ref<string[]>([]);
      const selectedKey = ref<string[]>([]);
      const submenuKeys = new Set<string>();
      const showCollapseButton = computed(
        () => appStore.device !== 'mobile' && !topMenu.value
      );

      const updateSelectedKey = (key?: string) => {
        selectedKey.value = key ? [key] : [];
        nextTick(() => {
          if (key) menuRef.value?.updateActiveIndex(key);
        });
      };

      const syncMenuState = () => {
        nextTick(() => {
          const activeKey = selectedKey.value[0];
          if (activeKey) menuRef.value?.updateActiveIndex(activeKey);
          if (!collapsed.value) {
            openKeys.value.forEach((key) => {
              if (submenuKeys.has(key)) menuRef.value?.open(key);
            });
          }
        });
      };

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

      const findMenuOpenKeys = (target: string) => {
        const result: string[] = [];
        let isFind = false;
        const backtrack = (item: RouteRecordRaw, keys: string[]) => {
          if (item.name === target) {
            isFind = true;
            result.push(...keys);
            return;
          }
          if (item.children?.length) {
            item.children.forEach((el) => {
              backtrack(el, [...keys, el.name as string]);
            });
          }
        };
        menuTree.value.forEach((el: RouteRecordRaw) => {
          if (isFind) return; // Performance optimization
          backtrack(el, [el.name as string]);
        });
        return result;
      };

      listenerRouteChange((newRoute) => {
        const { requiresAuth, activeMenu, hideInMenu } = newRoute.meta;
        if (requiresAuth && (!hideInMenu || activeMenu)) {
          const menuOpenKeys = findMenuOpenKeys(
            (activeMenu || newRoute.name) as string
          );

          const keySet = new Set([...menuOpenKeys, ...openKeys.value]);
          openKeys.value = [...keySet];

          updateSelectedKey(
            (activeMenu || menuOpenKeys[menuOpenKeys.length - 1]) as
              | string
              | undefined
          );
          syncMenuState();
        }
      }, true);

      const setCollapse = (val: boolean) => {
        if (appStore.device === 'desktop') {
          collapsed.value = val;
          syncMenuState();
        }
      };

      const handleOpen = (key: string) => {
        if (!openKeys.value.includes(key)) {
          openKeys.value = [...openKeys.value, key];
        }
      };

      const handleClose = (key: string) => {
        openKeys.value = openKeys.value.filter((item) => item !== key);
      };

      const renderIcon = (iconName?: unknown) => {
        if (!iconName) return null;
        const icon = iconMap[String(iconName)] || MenuIcon;
        return h(ElIcon, { class: 'menu-icon' }, { default: () => h(icon) });
      };

      const renderLabel = (item: RouteRecordRaw) => [
        renderIcon(item.meta?.icon),
        h(
          'span',
          null,
          (() => {
            const locale = String(item.meta?.locale || '');
            if (locale && te(locale)) return t(locale);
            return String(item.meta?.title || locale || item.name || '');
          })()
        ),
      ];

      const renderSubMenu = () => {
        submenuKeys.clear();
        function travel(_route: RouteRecordRaw[]): VNode[] {
          if (!_route) return [];
          return _route.map((element) => {
            const index = element.name as string;
            if (element.children?.length) {
              if (props.navigationMode === 'grouped') {
                return h(
                  ElMenuItemGroup,
                  { key: index },
                  {
                    title: () => renderLabel(element),
                    default: () => travel(element.children as RouteRecordRaw[]),
                  }
                );
              }
              submenuKeys.add(index);
              return h(
                ElSubMenu,
                { key: index, index },
                {
                  title: () => renderLabel(element),
                  default: () => travel(element.children as RouteRecordRaw[]),
                }
              );
            }
            return h(
              ElMenuItem,
              {
                key: index,
                index,
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
              class: 'peanut-menu',
              mode: topMenu.value ? 'horizontal' : 'vertical',
              collapse: collapsed.value,
              defaultOpeneds: openKeys.value,
              defaultActive: selectedKey.value[0] || '',
              collapseTransition: false,
              style: { height: '100%', width: '100%' },
              onOpen: handleOpen,
              onClose: handleClose,
            },
            { default: () => renderSubMenu() }
          ),
          showCollapseButton.value
            ? h(
                ElButton,
                {
                  'class': 'collapse-button',
                  'text': true,
                  'title': t(
                    collapsed.value
                      ? 'settings.menu.expand'
                      : 'settings.menu.collapse'
                  ),
                  'aria-label': t(
                    collapsed.value
                      ? 'settings.menu.expand'
                      : 'settings.menu.collapse'
                  ),
                  'aria-expanded': !collapsed.value,
                  'onClick': () => setCollapse(!collapsed.value),
                },
                {
                  default: () =>
                    h(ElIcon, null, {
                      default: () => h(collapsed.value ? MenuIcon : Back),
                    }),
                }
              )
            : null,
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
  }

  .collapse-button {
    width: 100%;
    min-height: 40px;
    border-radius: 0;
    color: var(--el-text-color-secondary);
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
</style>
