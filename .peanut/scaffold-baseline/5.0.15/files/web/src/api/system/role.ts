import axios from 'axios';

/** 角色记录 */
export interface RoleRecord {
  id: number;
  name: string;
  desc: string;
  num: number;
  status: 'active' | 'disabled' | 'archived';
  create_time: number | string;
  update_time?: number | string;
  menu_keys?: string[];
}

/** 角色详情：menu_keys 是当前角色已授权的菜单标识 列表 */
export interface RoleDetail extends RoleRecord {
  menu_keys: string[];
}

/** 角色基本信息提交体：id 仅编辑时带 */
export interface RoleBaseForm {
  id?: number;
  name: string;
  desc: string;
}

/** 分配权限提交体，沿用角色编辑接口 */
export interface RoleAuthForm extends RoleBaseForm {
  id: number;
  menu_keys: string[];
}

export interface RoleListParams {
  page_no: number;
  page_size: number;
}

export interface RoleListResult {
  lists: RoleRecord[];
  count: number;
  pageNo: number;
  pageSize: number;
}

/** 角色列表（按原生角色 ID 升序） */
export function getRoleList(params: RoleListParams) {
  return axios.get<RoleListResult>('/adminapi/role/lists', { params });
}

/** 全量角色（供下拉选择） */
export function getRoleAll() {
  return axios.get<RoleRecord[]>('/adminapi/role/all');
}

/** 角色详情（含 menu_keys，供分配权限回填权限树） */
export function getRoleDetail(id: number) {
  return axios.get<RoleDetail>('/adminapi/role/detail', { params: { id } });
}

export function addRole(data: RoleBaseForm) {
  return axios.post('/adminapi/role/add', data);
}

export function editRole(data: RoleBaseForm | RoleAuthForm) {
  return axios.post('/adminapi/role/edit', data);
}

export function deleteRole(id: number) {
  return axios.post('/adminapi/role/delete', { id });
}

export interface RolePermissionSnapshot {
  role_id: string;
  key: string;
  revision: string;
  permission_keys: string[];
}

export interface RolePermissionWrite {
  role_id: string | number;
  expected_revision: string;
  permission_keys: string[];
}

export interface RolePermissionOption {
  key: string;
  name: string;
  module_key: string;
}

export function getRolePermissions(roleId: number) {
  return axios.get<RolePermissionSnapshot>('/adminapi/role/permissions', {
    params: { role_id: roleId },
  });
}

export function getRolePermissionCatalog(params: {
  page_no: number;
  page_size: number;
}) {
  return axios.get<{ items: RolePermissionOption[]; total: number }>(
    '/adminapi/role/permissions/catalog',
    { params }
  );
}

export function replaceRolePermissions(data: RolePermissionWrite) {
  return axios.put<RolePermissionSnapshot>('/adminapi/role/permissions', data);
}
