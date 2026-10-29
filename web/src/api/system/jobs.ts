import axios from 'axios';

export interface JobsRecord {
  id: number;
  name: string;
  code: string;
  sort: number;
  status: number;
  status_desc: string;
  is_disable: number;
  remark: string;
  create_time?: string;
  update_time?: string;
}

/** 新增/编辑提交体：id 仅编辑时带 */
export type JobsForm = Partial<JobsRecord> & { id?: number };

export interface JobsListParams {
  name?: string;
  code?: string;
  status?: number | '';
  page_no?: number;
  page_size?: number;
  export?: 1 | 2;
  page_type?: 0 | 1;
  page_start?: number;
  page_end?: number;
  file_name?: string;
}

export interface JobsListRes {
  lists: JobsRecord[];
  count: number;
  pageNo: number;
  pageSize: number;
}

export interface JobsExportInfo {
  count: number;
  page_size: number;
  sum_page: number;
  max_page: number;
  all_max_size: number;
  page_start: number;
  page_end: number;
  file_name: string;
}

export interface JobsExportResult {
  url: string;
  file_name: string;
}

/** 分页列表（含禁用项，供后台管理） */
export function getJobsList(params: JobsListParams) {
  return axios.get<JobsListRes>('/adminapi/jobs/lists', { params });
}

export function getJobsExportInfo(params: JobsListParams) {
  return axios.get<JobsExportInfo>('/adminapi/jobs/lists', {
    params: { ...params, export: 1 },
  });
}

export function exportJobs(params: JobsListParams) {
  return axios.get<JobsExportResult>('/adminapi/jobs/lists', {
    params: { ...params, export: 2 },
  });
}

/** 全量启用岗位（供下拉选择） */
export function getJobsAll() {
  return axios.get<JobsRecord[]>('/adminapi/jobs/all');
}

export function getJobsDetail(id: number) {
  return axios.get<JobsRecord>('/adminapi/jobs/detail', { params: { id } });
}

export function addJobs(data: JobsForm) {
  return axios.post('/adminapi/jobs/add', data);
}

export function editJobs(data: JobsForm) {
  return axios.post('/adminapi/jobs/edit', data);
}

export function deleteJobs(id: number) {
  return axios.post('/adminapi/jobs/delete', { id });
}

export function updateJobsStatus(id: number, status: number) {
  return axios.post('/adminapi/jobs/status', { id, status });
}
