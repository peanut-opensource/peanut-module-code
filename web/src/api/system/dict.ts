import axios from 'axios';

// ---- 字典类型 ----
export interface DictTypeRecord {
  id: number;
  name: string;
  type: string;
  is_disable: number;
  remark: string;
  create_time?: string;
  update_time?: string;
}

export type DictTypeForm = Partial<DictTypeRecord> & { id?: number };

export interface DictTypeOption {
  id: number;
  name: string;
  type: string;
}

export interface DictTypeListParams {
  name?: string;
  type?: string;
  is_disable?: number | '';
  page_no?: number;
  page_size?: number;
}

export interface ListRes<T> {
  lists: T[];
  count: number;
  pageNo: number;
  pageSize: number;
}

export function getDictTypeList(params: DictTypeListParams) {
  return axios.get<ListRes<DictTypeRecord>>('/adminapi/dict/type/lists', {
    params,
  });
}

export function getDictTypeAll() {
  return axios.get<DictTypeOption[]>('/adminapi/dict/type/all');
}

export function addDictType(data: DictTypeForm) {
  return axios.post('/adminapi/dict/type/add', data);
}

export function editDictType(data: DictTypeForm) {
  return axios.post('/adminapi/dict/type/edit', data);
}

export function deleteDictType(id: number) {
  return axios.post('/adminapi/dict/type/delete', { id });
}

export function updateDictTypeStatus(id: number, isDisable: number) {
  return axios.post('/adminapi/dict/type/status', {
    id,
    is_disable: isDisable,
  });
}

// ---- 字典数据 ----
export interface DictDataRecord {
  id: number;
  name: string;
  value: string;
  type_id: number;
  type_value: string;
  sort: number;
  is_disable: number;
  remark: string;
  create_time?: string;
  update_time?: string;
}

export type DictDataForm = Partial<DictDataRecord> & { id?: number };

export interface DictDataListParams {
  type_id?: number;
  name?: string;
  is_disable?: number | '';
  page_no?: number;
  page_size?: number;
}

export function getDictDataList(params: DictDataListParams) {
  return axios.get<ListRes<DictDataRecord>>('/adminapi/dict/data/lists', {
    params,
  });
}

export interface DictDataOption {
  id: number;
  name: string;
  value: string;
  sort: number;
}

export function getDictDataByType(typeValue: string) {
  return axios.get<DictDataOption[]>('/adminapi/dict/data/byType', {
    params: { type_value: typeValue },
  });
}

export function addDictData(data: DictDataForm) {
  return axios.post('/adminapi/dict/data/add', data);
}

export function editDictData(data: DictDataForm) {
  return axios.post('/adminapi/dict/data/edit', data);
}

export function deleteDictData(id: number) {
  return axios.post('/adminapi/dict/data/delete', { id });
}

export function updateDictDataStatus(id: number, isDisable: number) {
  return axios.post('/adminapi/dict/data/status', {
    id,
    is_disable: isDisable,
  });
}
