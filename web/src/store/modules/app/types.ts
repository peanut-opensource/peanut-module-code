import type { RouteRecordRaw } from 'vue-router';

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
  topMenu: boolean;
  hideMenu: boolean;
  menuCollapse: boolean;
  footer: boolean;
  themeColor: string;
  menuWidth: number;
  globalSettings: boolean;
  device: string;
  tabBar: boolean;
  menuFromServer: boolean;
}

export interface AppState extends AppSettings {
  serverMenuLoaded: boolean;
  serverMenu: RouteRecordRaw[];
  enabledTenantModules: string[];
  [key: string]: unknown;
}
