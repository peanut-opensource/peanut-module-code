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
  permission_keys?: string[];
}

/** 角色详情中的独立权限包授权。 */
export interface RoleDetail extends RoleRecord {
  permission_keys: string[];
}

/** 角色基本信息提交体：id 仅编辑时带 */
export interface RoleBaseForm {
  id?: number;
  name: string;
  desc: string;
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

/** 角色详情。权限授权通过独立的 permissions 接口修改。 */
export function getRoleDetail(id: number) {
  return axios.get<RoleDetail>('/adminapi/role/detail', { params: { id } });
}

export function addRole(data: RoleBaseForm) {
  return axios.post('/adminapi/role/add', data);
}

export function editRole(data: RoleBaseForm) {
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

function readPermissionSnapshot(value: unknown): RolePermissionSnapshot {
  if (
    typeof value !== 'object' ||
    value === null ||
    !('role_id' in value) ||
    typeof value.role_id !== 'string' ||
    !('key' in value) ||
    typeof value.key !== 'string' ||
    !('revision' in value) ||
    typeof value.revision !== 'string' ||
    !('permission_keys' in value) ||
    !Array.isArray(value.permission_keys) ||
    !value.permission_keys.every(
      (key): key is string => typeof key === 'string'
    )
  )
    throw new Error('Invalid role permission response');
  return {
    role_id: value.role_id,
    key: value.key,
    revision: value.revision,
    permission_keys: value.permission_keys,
  };
}

export async function getRolePermissions(roleId: number) {
  const response = await axios.get<unknown>('/adminapi/role/permissions', {
    params: { role_id: roleId },
  });
  return { ...response, data: readPermissionSnapshot(response.data) };
}

export async function getRolePermissionCatalog(params: {
  page_no: number;
  page_size: number;
}) {
  const response = await axios.get<unknown>(
    '/adminapi/role/permissions/catalog',
    { params }
  );
  const value = response.data;
  if (
    typeof value !== 'object' ||
    value === null ||
    !('items' in value) ||
    !Array.isArray(value.items) ||
    !('total' in value) ||
    typeof value.total !== 'number' ||
    !Number.isSafeInteger(value.total) ||
    value.total < 0
  )
    throw new Error('Invalid permission catalog response');
  const items = value.items.map((item: unknown): RolePermissionOption => {
    if (
      typeof item !== 'object' ||
      item === null ||
      !('key' in item) ||
      typeof item.key !== 'string' ||
      !('name' in item) ||
      typeof item.name !== 'string' ||
      !('module_key' in item) ||
      typeof item.module_key !== 'string'
    )
      throw new Error('Invalid permission catalog item');
    return { key: item.key, name: item.name, module_key: item.module_key };
  });
  return { ...response, data: { items, total: value.total } };
}

export function replaceRolePermissions(data: RolePermissionWrite) {
  return axios.put<RolePermissionSnapshot>('/adminapi/role/permissions', data);
}
