import axios from 'axios';

/** 菜单类型：M 目录 / C 菜单 / A 按钮 */
export type MenuType = 'M' | 'C' | 'A';

export interface MenuRecord {
  id: number;
  menu_key: string;
  parent_key: string | null;
  module_key: string;
  source: 'module' | 'system' | 'custom';
  managed: boolean;
  status: 'active' | 'retired';
  type: MenuType;
  name: string;
  icon: string;
  sort: number;
  perms: string;
  paths: string;
  component: string;
  is_cache: number;
  is_show: number;
  is_disable: number;
  children?: MenuRecord[];
}

/** 新增/编辑提交体：编辑以 menu_key 寻址，id 仅为数据库记录信息 */
export type MenuForm = Partial<MenuRecord> & { id?: number };

/** 树形全量列表（含禁用项，供后台管理） */
export function getMenuList() {
  return axios.get<MenuRecord[]>('/adminapi/menu/lists');
}

/** 精简树（menu_key/parent_key/name），供上级菜单选择器 */
export function getMenuAll() {
  return axios.get<MenuRecord[]>('/adminapi/menu/all');
}

export function getMenuDetail(menuKey: string) {
  return axios.get<MenuRecord>('/adminapi/menu/detail', {
    params: { menu_key: menuKey },
  });
}

export function addMenu(data: MenuForm) {
  return axios.post('/adminapi/menu/add', data);
}

export function editMenu(data: MenuForm) {
  return axios.post('/adminapi/menu/edit', data);
}

export function deleteMenu(menuKey: string) {
  return axios.post('/adminapi/menu/delete', { menu_key: menuKey });
}

export function updateMenuStatus(menuKey: string, isDisable: number) {
  return axios.post('/adminapi/menu/status', {
    menu_key: menuKey,
    is_disable: isDisable,
  });
}
