import axios from 'axios';

export interface ListRes<T> {
  lists: T[];
  count: number;
  pageNo: number;
  pageSize: number;
}

// 状态：1运行 2停止 3错误
export type CrontabStatus = 1 | 2 | 3;

export interface CrontabRecord {
  id: number;
  name: string;
  type: number;
  type_desc?: string;
  command: string;
  params: string;
  status: CrontabStatus;
  status_desc?: string;
  expression: string;
  error: string;
  last_time: string;
  time: number;
  max_time: number;
  sort: number;
  remark: string;
  create_time?: string;
  update_time?: string;
}

export type CrontabForm = Partial<CrontabRecord> & { id?: number };

export interface CrontabListParams {
  name?: string;
  status?: CrontabStatus | '';
  page_no?: number;
  page_size?: number;
}

export interface ExpressionItem {
  time: number;
  date: string;
}

export function getCrontabList(params: CrontabListParams) {
  return axios.get<ListRes<CrontabRecord>>('/adminapi/peanut.task.list', {
    params,
  });
}

export function getCrontabDetail(id: number) {
  return axios.get<CrontabRecord>('/adminapi/peanut.task.detail', {
    params: { id },
  });
}

export function getCrontabExpression(expression: string) {
  return axios.get<ExpressionItem[]>('/adminapi/peanut.task.expression', {
    params: { expression },
  });
}

export function addCrontab(data: CrontabForm) {
  return axios.post('/adminapi/peanut.task.add', data);
}

export function editCrontab(data: CrontabForm) {
  return axios.post('/adminapi/peanut.task.edit', data);
}

export function deleteCrontab(id: number) {
  return axios.post('/adminapi/peanut.task.delete', { id });
}

export function operateCrontab(id: number, operate: 'start' | 'stop') {
  return axios.post('/adminapi/peanut.task.operate', { id, operate });
}
