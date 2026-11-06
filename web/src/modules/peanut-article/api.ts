import axios from 'axios';
import type { PageData } from '@/types/global';
import type { components } from '@/generated/openapi';
import type { RichTextDocumentValue } from '@peanut-admin/peanut-rich-text/document';

export interface ArticleHtmlPreview {
  article_id: number;
  source_html: string;
  source_sha256: string;
  owner_revision: string;
}
export interface ArticleHtmlMigrationInput {
  id: number;
  request_id: string;
  confirmed: true;
  source_sha256: string;
  owner_revision: string;
  structured_content: RichTextDocumentValue;
}
export interface ArticleHtmlMigrated {
  article_id: number;
  request_id: string;
  document_id: number;
  state_seq: 1;
  source_sha256: string;
  phase: 'completed';
  published: false;
}
export class ArticleHtmlMigrationRejected extends Error {
  constructor() {
    super('原文、资讯状态或正文绑定已改变，请重新预览当前来源。');
  }
}
const htmlResponse = (value: unknown): Record<string, unknown> => {
  if (!value || typeof value !== 'object' || Array.isArray(value))
    throw new Error('ARTICLE_HTML_RESPONSE_INVALID');
  return value as Record<string, unknown>;
};
const htmlId = (value: unknown): value is number =>
  typeof value === 'number' && Number.isSafeInteger(value) && value > 0;
const htmlDigest = (value: unknown): value is string =>
  typeof value === 'string' && /^[0-9a-f]{64}$/.test(value);
export const previewArticleHtml = async (id: number, signal: AbortSignal) => {
  const response = await axios.get<unknown>(
    '/adminapi/peanut.article.document.html-preview',
    { params: { id }, signal }
  );
  const value = htmlResponse(response.data);
  if (
    !htmlId(value.article_id) ||
    typeof value.source_html !== 'string' ||
    !htmlDigest(value.source_sha256) ||
    !htmlDigest(value.owner_revision)
  )
    throw new Error('ARTICLE_HTML_RESPONSE_INVALID');
  return {
    ...response,
    data: {
      article_id: value.article_id,
      source_html: value.source_html,
      source_sha256: value.source_sha256,
      owner_revision: value.owner_revision,
    } satisfies ArticleHtmlPreview,
  };
};
export const migrateArticleHtml = async (
  input: ArticleHtmlMigrationInput,
  signal: AbortSignal
) => {
  let rejected = false;
  const defaults = axios.defaults.transformResponse;
  const transforms = Array.isArray(defaults) ? [...defaults] : [];
  if (typeof defaults === 'function') transforms.push(defaults);
  const response = await axios
    .post<unknown>('/adminapi/peanut.article.document.html-migrate', input, {
      signal,
      // Preserve only exact pre-write conflicts before the shared interceptor reduces them to messages.
      transformResponse: [
        ...transforms,
        (value: unknown, _headers: unknown, status?: number) => {
          if (
            status === 409 &&
            value &&
            typeof value === 'object' &&
            'code' in value &&
            value.code === 40900 &&
            'data' in value &&
            value.data &&
            typeof value.data === 'object' &&
            'error_code' in value.data &&
            typeof value.data.error_code === 'string'
          ) {
            rejected = [
              'ARTICLE_HTML_SOURCE_CHANGED',
              'ARTICLE_HTML_MIGRATION_UNAVAILABLE',
              'ARTICLE_HTML_DOCUMENT_EXISTS',
            ].includes(value.data.error_code);
          }
          return value;
        },
      ],
    })
    .catch((error: unknown) => {
      if (rejected && !signal.aborted) throw new ArticleHtmlMigrationRejected();
      throw error;
    });
  const value = htmlResponse(response.data);
  if (
    !htmlId(value.article_id) ||
    typeof value.request_id !== 'string' ||
    !value.request_id ||
    !htmlId(value.document_id) ||
    value.state_seq !== 1 ||
    !htmlDigest(value.source_sha256) ||
    value.phase !== 'completed' ||
    value.published !== false
  )
    throw new Error('ARTICLE_HTML_RESPONSE_INVALID');
  return {
    ...response,
    data: {
      article_id: value.article_id,
      request_id: value.request_id,
      document_id: value.document_id,
      state_seq: value.state_seq,
      source_sha256: value.source_sha256,
      phase: value.phase,
      published: value.published,
    } satisfies ArticleHtmlMigrated,
  };
};

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
export class ArticleCollaborationDisabled extends Error {
  constructor() {
    super('当前租户未开通多人协作，请联系平台管理员确认协作权益。');
  }
}

export const getArticleBodyTransport = async (
  id: number,
  signal: AbortSignal
) => {
  let disabled = false;
  const defaults = axios.defaults.transformResponse;
  const transforms = Array.isArray(defaults) ? [...defaults] : [];
  if (typeof defaults === 'function') transforms.push(defaults);
  try {
    return await axios.post<ArticleBodyTransport>(
      '/adminapi/peanut.article.document.transport',
      { id },
      {
        signal,
        // Retain this pre-transport guard rejection before the shared interceptor reduces it to a message.
        transformResponse: [
          ...transforms,
          (value: unknown, _headers: unknown, status?: number) => {
            disabled =
              status === 403 &&
              typeof value === 'object' &&
              value !== null &&
              'code' in value &&
              value.code === 40300 &&
              'data' in value &&
              typeof value.data === 'object' &&
              value.data !== null &&
              'error_code' in value.data &&
              value.data.error_code === 'MODULE_TENANT_DISABLED';
            return value;
          },
        ],
      }
    );
  } catch (error) {
    if (disabled && !signal.aborted) throw new ArticleCollaborationDisabled();
    throw error;
  }
};
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
  body_document_id?: number | null;
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
  const path = recycled ? 'peanut.article.recycle.list' : 'peanut.article.list';
  return axios.get<ArticleExportInfo>(`/adminapi/${path}`, {
    params: { ...params, export: 1 },
  });
}

export function exportArticles(
  params: ArticleListParams & ArticleExportRange = {},
  recycled = false
) {
  const path = recycled ? 'peanut.article.recycle.list' : 'peanut.article.list';
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
