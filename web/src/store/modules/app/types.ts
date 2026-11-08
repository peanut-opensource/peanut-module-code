import type { RouteRecordRaw } from 'vue-router';
import type { ThemeColorPresetName, ThemeTone } from '@/layout/shell-presets';
import type {
  ShellLayout,
  ShellNavigationMode,
  ShellDensity,
} from '@peanut-admin/ui-vue';

export interface ServerMenuRecord {
  id: number;
  pid: number;
  parent_key: string | null;
  type: 'M' | 'C';
  name: string;
  icon?: string;
  sort?: number;
  perms?: string;
  paths: string;
  component?: string;
  is_cache?: number;
  is_show?: number;
  is_disable?: number;
  module_key?: string;
  menu_key: string;
  required_permission?: string;
  children?: ServerMenuRecord[];
}

/** Editable presentation settings; registered menus use the menu actions. */
export interface AppSettings {
  theme: string;
  colorWeak: boolean;
  navbar: boolean;
  menu: boolean;
  shellPreset?: 'admin' | 'workspace';
  shellLayout?: ShellLayout;
  shellNavigationMode?: ShellNavigationMode;
  shellDensity?: ShellDensity;
  shellColorPreset?: ThemeColorPresetName;
  hideMenu: boolean;
  menuCollapse: boolean;
  footer: boolean;
  themeColor: string;
  themeColors?: Partial<Record<Exclude<ThemeTone, 'primary'>, string>>;
  menuWidth: number;
  globalSettings: boolean;
  device: string;
  tabBar: boolean;
  menuFromServer: boolean;
}

export interface AppState extends AppSettings {
  themeColorOverride?: string;
  semanticThemeColors: Partial<Record<Exclude<ThemeTone, 'primary'>, string>>;
  serverMenuLoaded: boolean;
  serverMenu: RouteRecordRaw[];
  enabledTenantModules: string[];
  [key: string]: unknown;
}
