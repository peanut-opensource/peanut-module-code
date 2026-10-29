import axios from 'axios';

export interface AdminRoleBrief {
  id: number;
  name: string;
}

export interface AdminRecord {
  id: number;
  account: string;
  name: string;
  username: string;
  nickname: string;
  avatar: string;
  avatar_uri: string;
  root: number;
  disable: number;
  disable_desc: string;
  multipoint_login: number;
  login_time: string;
  login_ip: string;
  create_time: string;
  update_time: string;
  role_id: number[];
  role_ids: number[];
  dept_id: number[];
  jobs_id: number[];
  role_name: string;
  dept_name: string;
  jobs_name: string;
  roles: AdminRoleBrief[];
}

export type AdminDetail = AdminRecord;

export interface AdminForm {
  id?: number;
  account: string;
  name: string;
  avatar: string;
  dept_id: number[];
  jobs_id: number[];
  role_id: number[];
  password: string;
  password_confirm: string;
  disable: number;
  multipoint_login: number;
  root?: number;
}

export interface AdminListParams {
  account?: string;
  name?: string;
  role_id?: number | '';
  page_no?: number;
  page_size?: number;
  field?: 'id' | 'create_time';
  order_by?: 'asc' | 'desc';
  export?: 1 | 2;
  page_type?: 0 | 1;
  page_start?: number;
  page_end?: number;
  file_name?: string;
}

export interface AdminListResult {
  lists: AdminRecord[];
  count: number;
  pageNo: number;
  pageSize: number;
}

export interface AdminExportInfo {
  count: number;
  page_size: number;
  sum_page: number;
  max_page: number;
  all_max_size: number;
  page_start: number;
  page_end: number;
  file_name: string;
}

export interface AdminExportResult {
  url: string;
  file_name: string;
}

export interface EditSelfForm {
  nickname: string;
  avatar: string;
  password?: string;
  password_confirm?: string;
  password_old?: string;
}

export function getAdminList(params: AdminListParams = {}) {
  return axios.get<AdminListResult>('/adminapi/admin/lists', { params });
}

export function getAdminExportInfo(params: AdminListParams) {
  return axios.get<AdminExportInfo>('/adminapi/admin/lists', {
    params: { ...params, export: 1 },
  });
}

export function exportAdmins(params: AdminListParams) {
  return axios.get<AdminExportResult>('/adminapi/admin/lists', {
    params: { ...params, export: 2 },
  });
}

export function getAdminSelf() {
  return axios.get<AdminDetail>('/adminapi/admin/self');
}

export function editAdminSelf(data: EditSelfForm) {
  return axios.post('/adminapi/admin/editSelf', data);
}

export function getAdminDetail(id: number) {
  return axios.get<AdminDetail>('/adminapi/admin/detail', { params: { id } });
}

export function addAdmin(data: AdminForm) {
  return axios.post('/adminapi/admin/add', data);
}

export function editAdmin(data: AdminForm) {
  return axios.post('/adminapi/admin/edit', data);
}

export function deleteAdmin(id: number) {
  return axios.post('/adminapi/admin/delete', { id });
}

export function updateAdminStatus(id: number, disable: number) {
  return axios.post('/adminapi/admin/status', { id, disable });
}
