import axios from 'axios';
import { getToken } from '@/utils/auth';

export interface GeneratorListRes<T> {
  lists: T[];
  count: number;
  pageNo: number;
  pageSize: number;
}

export interface GeneratorSourceTable {
  table_name: string;
  table_comment: string;
  engine?: string;
  table_rows?: number | string;
  create_time?: string | null;
}

export interface GeneratorColumn {
  id: number;
  table_id: number;
  column_name: string;
  column_comment: string;
  column_type: string;
  php_type: string;
  is_required: number;
  is_pk: number;
  is_insert: number;
  is_update: number;
  is_lists: number;
  is_query: number;
  query_type: string;
  view_type: string;
  dict_type: string;
  sort: number;
}

export interface GeneratorRelation {
  target_table_id: number;
  name: string;
  type: 'belongsTo' | 'hasOne' | 'hasMany';
  local_key: string;
  foreign_key: string;
  module?: string;
  model?: string;
  data_owner?: 'tenant-orm' | 'platform' | 'instance' | 'shared' | '';
  target_edition?: 'standalone' | 'multi-tenant' | '';
}

export interface GeneratorSoftDeleteConfig {
  enabled: boolean;
  field: string;
}

export interface GeneratorTreeConfig {
  id_field?: string;
  parent_field?: string;
  name_field?: string;
  soft_delete?: GeneratorSoftDeleteConfig;
}

export interface GeneratorRecord {
  id: number;
  admin_id: number;
  table_name: string;
  table_comment: string;
  module_name: string;
  entity_name: string;
  template_type: 'crud' | 'tree' | string;
  data_owner: 'tenant-orm' | 'platform' | 'instance' | 'shared' | '';
  target_edition: 'standalone' | 'multi-tenant' | '';
  author: string;
  tree_config: GeneratorTreeConfig;
  relations: GeneratorRelation[];
  soft_delete: GeneratorSoftDeleteConfig;
  columns?: GeneratorColumn[];
  create_time?: string;
  update_time?: string;
}

export interface GeneratorListParams {
  keyword?: string;
  page_no?: number;
  page_size?: number;
}

export interface GeneratorUpdateForm {
  id: number;
  table_comment: string;
  module_name: string;
  entity_name: string;
  template_type: 'crud' | 'tree';
  data_owner: 'tenant-orm' | 'platform' | 'instance' | 'shared';
  target_edition: 'standalone' | 'multi-tenant';
  author?: string;
  tree_config?: Record<string, string>;
  relations?: GeneratorRelation[];
  soft_delete: GeneratorSoftDeleteConfig;
  columns: Array<Partial<GeneratorColumn> & { id: number }>;
}

export interface GeneratorPreviewFile {
  path: string;
  language: string;
  content: string;
}

export interface GeneratorGenerateResult {
  download_token: string;
  file_name: string;
  expires_in: number;
}

export interface GeneratorModel {
  id: number;
  module_name: string;
  entity_name: string;
  table_name: string;
  data_owner: 'tenant-orm' | 'platform' | 'instance' | 'shared' | '';
  target_edition: 'standalone' | 'multi-tenant' | '';
}

export function getGeneratorSourceTables(params: GeneratorListParams) {
  return axios.get<GeneratorListRes<GeneratorSourceTable>>(
    '/adminapi/generator/source-tables',
    { params }
  );
}

export function getGeneratorList(params: GeneratorListParams) {
  return axios.get<GeneratorListRes<GeneratorRecord>>(
    '/adminapi/generator/lists',
    { params }
  );
}

export function getGeneratorDetail(id: number) {
  return axios.get<GeneratorRecord>('/adminapi/generator/detail', {
    params: { id },
  });
}

// The backend contract intentionally uses the canonical snake_case field.
// eslint-disable-next-line camelcase
export function importGeneratorTables(table_names: string[]) {
  // eslint-disable-next-line camelcase
  return axios.post('/adminapi/generator/import', { table_names });
}

export function syncGenerator(id: number) {
  return axios.post('/adminapi/generator/sync', { id });
}

export function updateGenerator(data: GeneratorUpdateForm) {
  return axios.post('/adminapi/generator/update', data);
}

export function deleteGenerator(ids: number[]) {
  return axios.post('/adminapi/generator/delete', { ids });
}

export function previewGenerator(id: number) {
  return axios.post<GeneratorPreviewFile[]>('/adminapi/generator/preview', {
    id,
  });
}

export function generateGenerator(ids: number[]) {
  return axios.post<GeneratorGenerateResult>('/adminapi/generator/generate', {
    ids,
  });
}

export function getGeneratorModels() {
  return axios.get<GeneratorModel[]>('/adminapi/generator/models');
}

/** 下载只允许消费服务端签发的一次性令牌。 */
export async function downloadGenerator(token: string): Promise<Blob> {
  const baseUrl = (import.meta.env.VITE_API_BASE_URL || '').replace(/\/$/, '');
  const response = await fetch(
    `${baseUrl}/adminapi/generator/download?token=${encodeURIComponent(token)}`,
    { headers: { Authorization: `Bearer ${getToken() || ''}` } }
  );
  const contentType = response.headers.get('content-type') || '';
  if (!response.ok || contentType.includes('application/json')) {
    throw new Error('下载令牌无效或已过期');
  }
  return response.blob();
}
