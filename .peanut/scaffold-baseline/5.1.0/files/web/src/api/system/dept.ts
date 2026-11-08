import axios from 'axios';

export interface DeptRecord {
  id: number;
  pid: number;
  name: string;
  code?: string;
  revision?: number;
  leader: string;
  leader_member_id?: number | null;
  mobile: string;
  sort: number;
  status: number;
  status_desc?: string;
  is_disable: number;
  create_time?: number | string;
  update_time?: number | string;
  children?: DeptRecord[];
}

/** 新增/编辑提交体：id 仅编辑时带 */
export type DeptForm = Partial<DeptRecord> & { id?: number };

export interface DeptListParams {
  mode: 'children' | 'search' | 'resolve';
  pageNo: number;
  pageSize: number;
  parent_id?: number;
  ids?: string;
  name?: string;
  status?: number | string;
}

export interface DirectoryDepartment
  extends Omit<DeptRecord, 'leader' | 'mobile' | 'is_disable'> {
  leader?: string;
  mobile?: string;
  realStatus: 'active' | 'disabled' | 'archived';
  structuralOnly: boolean;
  selectable: boolean;
  hasChildren: boolean;
}

export interface DepartmentPage {
  lists: DirectoryDepartment[];
  ancestors: DirectoryDepartment[];
  count: number;
  pageNo: number;
  pageSize: number;
  hasMore: boolean;
}

/** One bounded directory page. Ancestors are structural context, not matches. */
export function getDeptList(params: DeptListParams, signal?: AbortSignal) {
  return axios.get<DepartmentPage>('/adminapi/dept/lists', { params, signal });
}

/** Active selections with expandable disabled structural containers. */
export function getDeptAll(params: DeptListParams, signal?: AbortSignal) {
  return axios.get<DepartmentPage>('/adminapi/dept/all', { params, signal });
}

/** Bounded active flat page; mode is search or resolve. */
export function getLeaderDept(params: DeptListParams) {
  return axios.get<DepartmentPage>('/adminapi/dept/leaderDept', { params });
}

export function getDeptDetail(id: number) {
  return axios.get<DeptRecord>('/adminapi/dept/detail', { params: { id } });
}

export interface DepartmentLeaderCandidate {
  id: number;
  name: string;
}

export function getDepartmentLeaderCandidates(params: {
  department_id: number;
  page_no: number;
  page_size: number;
  search: string;
}) {
  return axios.get<{
    items: DepartmentLeaderCandidate[];
    total: number;
    page_no: number;
    page_size: number;
  }>('/adminapi/dept/leader-candidates', { params });
}

export function addDept(data: DeptForm) {
  return axios.post('/adminapi/dept/add', data);
}

export function editDept(data: DeptForm) {
  return axios.post('/adminapi/dept/edit', data);
}

export function deleteDept(id: number) {
  return axios.post('/adminapi/dept/delete', { id });
}

export function updateDeptStatus(id: number, status: number) {
  return axios.post('/adminapi/dept/status', { id, status });
}
