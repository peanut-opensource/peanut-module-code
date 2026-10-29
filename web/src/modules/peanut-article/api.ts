import axios from 'axios';
import type { PageData } from '@/types/global';
import type { components } from '@/generated/openapi';

export type ArticleBodyEditor =
  components['schemas']['ArticleBodyEditorResponse']['data'];
/** Same-row owner metadata: image is writable file_key; image_url is display only. */
export type ArticleBodyMetadata = ArticleBodyEditor['article_metadata'];
export type ArticleBodyTransport =
  components['schemas']['ArticleBodyTransportResponse']['data'];
export type ArticleBodySaveInput =
  components['schemas']['ArticleBodySaveRequest'];
export type ArticleBodySaved =
  components['schemas']['ArticleBodySaveResponse']['data'];
export type ArticleBodyOperation =
  components['schemas']['ArticleBodySaveOperationResponse']['data'];

export const ensureArticleBody = (
  id: number,
  requestId: string,
  signal: AbortSignal
) =>
  axios.post<components['schemas']['DocumentMetadataResponse']['data']>(
    '/adminapi/peanut.article.document',
    { id, request_id: requestId },
    { signal }
  );
export const getArticleBodyEditor = (id: number, signal: AbortSignal) =>
  axios.get<ArticleBodyEditor>('/adminapi/peanut.article.document.editor', {
    params: { id },
    signal,
  });
export const getArticleBodyTransport = (id: number, signal: AbortSignal) =>
  axios.post<ArticleBodyTransport>(
    '/adminapi/peanut.article.document.transport',
    { id },
    { signal }
  );
export const saveArticleBody = (
  input: ArticleBodySaveInput,
  signal: AbortSignal
) =>
  axios.post<ArticleBodySaved>(
    '/adminapi/peanut.article.document.save',
    input,
    { signal }
  );
export const getArticleBodyOperation = (
  id: number,
  requestId: string,
  signal: AbortSignal
) =>
  axios.get<ArticleBodyOperation>(
    '/adminapi/peanut.article.document.operation',
    { params: { id, request_id: requestId }, signal }
  );

export type ArticleExportInfo = components['schemas']['ExportPageInfo'];
export type ArticleExportResult = components['schemas']['ExportFile'];
export interface ArticleExportRange {
  page_type?: 0 | 1;
  page_start?: number;
  page_end?: number;
  file_name?: string;
}

// ─── 文章分类 ──────────────────────────────────────────────────────────────
export interface ArticleCateRecord {
  id: number;
  name: string;
  sort: number;
  is_show: number;
  article_count?: number;
  create_time?: string | number;
  update_time?: string | number | null;
  delete_time?: string | number | null;
}

export interface ArticleCateOption {
  id: number;
  name: string;
}

export interface ArticleCateListParams {
  name?: string;
  is_show?: number | string;
  page_no?: number;
  page_size?: number;
  page_type?: 0 | 1;
  field?: 'create_time' | 'id';
  order_by?: 'asc' | 'desc';
}

export type ArticleCateListRes = PageData<ArticleCateRecord>;

export function getArticleCateList(
  params: ArticleCateListParams,
  signal?: AbortSignal
) {
  return axios.get<ArticleCateListRes>(
    '/adminapi/peanut.article.category.list',
    { params, signal }
  );
}

// Reuse the existing export=1/2 protocol without making list callers handle a union.
export function getArticleCateExportInfo(
  params: ArticleCateListParams = {},
  recycled = false
) {
  const path = recycled
    ? 'peanut.article.category.recycle.list'
    : 'peanut.article.category.list';
  return axios.get<ArticleExportInfo>(`/adminapi/${path}`, {
    params: { ...params, export: 1 },
  });
}

export function exportArticleCategories(
  params: ArticleCateListParams & ArticleExportRange = {},
  recycled = false
) {
  const path = recycled
    ? 'peanut.article.category.recycle.list'
    : 'peanut.article.category.list';
  return axios.get<ArticleExportResult>(`/adminapi/${path}`, {
    params: { ...params, export: 2 },
  });
}

export function getArticleCateAll(signal?: AbortSignal) {
  return axios.get<ArticleCateOption[]>(
    '/adminapi/peanut.article.category.all',
    { signal }
  );
}

export function getArticleCateDetail(id: number, signal?: AbortSignal) {
  return axios.get<ArticleCateRecord>(
    '/adminapi/peanut.article.category.detail',
    { params: { id }, signal }
  );
}

export function addArticleCate(
  data: Partial<ArticleCateRecord>,
  signal?: AbortSignal
) {
  return axios.post('/adminapi/peanut.article.category.add', data, {
    signal,
  });
}

export function editArticleCate(
  data: Partial<ArticleCateRecord>,
  signal?: AbortSignal
) {
  return axios.post('/adminapi/peanut.article.category.edit', data, {
    signal,
  });
}

export function deleteArticleCate(id: number, signal?: AbortSignal) {
  return axios.post(
    '/adminapi/peanut.article.category.delete',
    { id },
    { signal }
  );
}

export function updateArticleCateStatus(
  id: number,
  isShow: number,
  signal?: AbortSignal
) {
  return axios.post(
    '/adminapi/peanut.article.category.update-status',
    {
      id,
      is_show: isShow,
    },
    { signal }
  );
}

// ─── 文章 ─────────────────────────────────────────────────────────────────
export interface ArticleRecord {
  id: number;
  cid: number;
  cate_name?: string;
  title: string;
  desc: string;
  abstract: string;
  image: string;
  author: string;
  content: string;
  click_virtual: number;
  click_actual: number;
  click: number;
  sort: number;
  is_show: number;
  create_time?: string | number;
  update_time?: string | number | null;
}

export type ArticleCreateRequest = Pick<
  ArticleRecord,
  | 'cid'
  | 'title'
  | 'desc'
  | 'abstract'
  | 'image'
  | 'author'
  | 'content'
  | 'click_virtual'
  | 'sort'
  | 'is_show'
>;

export type ArticleUpdateRequest = ArticleCreateRequest &
  Pick<ArticleRecord, 'id'>;

export interface ArticleListParams {
  page_no?: number;
  page_size?: number;
  page_type?: 0 | 1;
  title?: string;
  cid?: number | string;
  is_show?: number | string;
  field?: 'create_time' | 'id';
  order_by?: 'asc' | 'desc';
}

export type ArticleListRes = PageData<ArticleRecord>;

export function getArticleList(
  params?: ArticleListParams,
  signal?: AbortSignal
) {
  return axios.get<ArticleListRes>('/adminapi/peanut.article.list', {
    params: params ?? {},
    signal,
  });
}

export function getArticleExportInfo(
  params: ArticleListParams = {},
  recycled = false
) {
  const path = recycled
    ? 'peanut.article.recycle.list'
    : 'peanut.article.list';
  return axios.get<ArticleExportInfo>(`/adminapi/${path}`, {
    params: { ...params, export: 1 },
  });
}

export function exportArticles(
  params: ArticleListParams & ArticleExportRange = {},
  recycled = false
) {
  const path = recycled
    ? 'peanut.article.recycle.list'
    : 'peanut.article.list';
  return axios.get<ArticleExportResult>(`/adminapi/${path}`, {
    params: { ...params, export: 2 },
  });
}

export function getArticleDetail(id: number, signal?: AbortSignal) {
  return axios.get<components['schemas']['ArticleDetailResponse']['data']>(
    '/adminapi/peanut.article.detail',
    {
      params: { id },
      signal,
    }
  );
}

export function addArticle(data: ArticleCreateRequest, signal?: AbortSignal) {
  return axios.post('/adminapi/peanut.article.add', data, { signal });
}

export function editArticle(data: ArticleUpdateRequest, signal?: AbortSignal) {
  return axios.post('/adminapi/peanut.article.edit', data, { signal });
}

export function deleteArticle(id: number, signal?: AbortSignal) {
  return axios.post('/adminapi/peanut.article.delete', { id }, { signal });
}

export function updateArticleStatus(
  id: number,
  isShow: number,
  signal?: AbortSignal
) {
  return axios.post(
    '/adminapi/peanut.article.update-status',
    {
      id,
      is_show: isShow,
    },
    { signal }
  );
}
