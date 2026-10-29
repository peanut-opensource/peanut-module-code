import axios, { type AxiosRequestConfig } from 'axios';
import type {
  SurfaceCapabilities,
  SurfaceState,
} from '@/modules/peanut-content-tools/src/contracts';
import type { UploadProgressEvent, UploadRequestOptions } from 'element-plus';

export interface ListRes<T> {
  lists: T[];
  count: number;
  pageNo: number;
  pageSize: number;
}

// 文件类型：10 图片 / 20 视频 / 30 文件
export type FileType = 10 | 20 | 30;

// ---- 分类 ----
export interface FileCateRecord {
  id: number;
  pid: number;
  type: FileType;
  name: string;
  create_time?: string;
  children?: FileCateRecord[];
}

export function getFileCateList(type: FileType) {
  return axios.get<FileCateRecord[]>('/adminapi/peanut.file.category.list', {
    params: { type },
  });
}

export function addFileCate(data: {
  type: FileType;
  pid?: number;
  name: string;
}) {
  return axios.post('/adminapi/peanut.file.category.add', data);
}

export function editFileCate(data: { id: number; name: string }) {
  return axios.post('/adminapi/peanut.file.category.edit', data);
}

export function deleteFileCate(id: number) {
  return axios.post('/adminapi/peanut.file.category.delete', { id });
}

// ---- 文件 ----
export interface FileRecord {
  access_type?: 'public' | 'private';
  visibility_state?: string;
  file_revision?: number;
  id: number;
  cid: number;
  type: FileType;
  name: string;
  uri: string;
  /** Short-lived authorized delivery address; never a stable content reference. */
  url: string;
  file_key?: string;
  verified_mime_type?: string | null;
  source_sha256?: string | null;
  capabilities?: SurfaceCapabilities | null;
  preview_state?: SurfaceState;
  preview_unavailable_reason?: string | null;
  authorization_revision?: string | null;
  can_download_original?: boolean;
  create_time?: string;
}

export interface FileVisibilityConfirmation {
  version: number;
  batch_key: string;
  expires_at: number;
  target: 'private';
  actor: Record<string, string | number>;
  manifest: {
    roots: string[];
    files: Record<
      string,
      {
        source: {
          file_key: string;
          original_name: string;
          revision: string;
          access_type: string;
          space_key: string;
        };
        target: { space_key: string; access_type: string };
      }
    >;
    registered_reference_count: number;
    warning: string;
  };
}

export interface FileVisibilityBatch {
  batch_key: string;
  status: 'pending' | 'running' | 'completed' | 'attention';
  revision: number;
  items: Array<{
    file_key: string;
    status: string;
    step: string;
    attempts: number;
    error_code: string | null;
  }>;
}

const visibilityBase = '/adminapi/api/v1/files/visibility';
export class FileVisibilityRejection extends Error {
  constructor(readonly status: number, readonly errorCode: string) {
    super(errorCode);
  }
}

// Keep the server's definitive rejection before the shared interceptor reduces it
// to a message. This does not retry requests or change session/refresh handling.
async function visibilityRequest<T>(config: AxiosRequestConfig) {
  let rejection: FileVisibilityRejection | undefined;
  const defaults = axios.defaults.transformResponse;
  const transforms = Array.isArray(defaults) ? [...defaults] : [];
  if (typeof defaults === 'function') transforms.push(defaults);
  try {
    return await axios.request<T>({
      ...config,
      transformResponse: [
        ...transforms,
        (value: unknown, _headers: unknown, status?: number) => {
          rejection = undefined;
          if (
            status !== undefined &&
            status >= 400 &&
            status < 500 &&
            // A 401 can trigger the shared refresh/re-dispatch path. It is not
            // the terminal outcome of this mutation and must never be retained.
            status !== 401 &&
            value &&
            typeof value === 'object' &&
            'code' in value &&
            value.code === status * 100 &&
            'data' in value &&
            value.data &&
            typeof value.data === 'object' &&
            'error_code' in value.data &&
            typeof value.data.error_code === 'string'
          ) {
            rejection = new FileVisibilityRejection(
              status,
              value.data.error_code
            );
          }
          return value;
        },
      ],
    });
  } catch (error) {
    if (axios.isCancel(error) || (axios.isAxiosError(error) && !error.response))
      throw error;
    if (rejection) throw rejection;
    throw error;
  }
}
export const previewFileVisibility = (fileKeys: string[]) =>
  axios.post<{
    confirmation: FileVisibilityConfirmation;
    signature: string;
  }>(`${visibilityBase}/preview`, { file_keys: fileKeys });
export const submitFileVisibility = (
  confirmation: FileVisibilityConfirmation,
  signature: string,
  requestKey: string
) =>
  visibilityRequest<FileVisibilityBatch>({
    method: 'POST',
    url: `${visibilityBase}/batches`,
    data: { confirmation, signature, impact_confirmed: true },
    headers: { 'Idempotency-Key': requestKey },
  });
export const getFileVisibilityBatch = (key: string) =>
  visibilityRequest<FileVisibilityBatch>({
    method: 'GET',
    url: `${visibilityBase}/batches/${encodeURIComponent(key)}`,
  });
export const resumeFileVisibilityBatch = (key: string) =>
  axios.post<FileVisibilityBatch>(
    `${visibilityBase}/batches/${encodeURIComponent(key)}/resume`,
    {}
  );
export const recentFileVisibilityBatches = () =>
  axios.get<{
    items: Array<
      Pick<FileVisibilityBatch, 'batch_key' | 'status' | 'revision'>
    >;
  }>(`${visibilityBase}/batches`);

export interface FileListParams {
  type: FileType;
  cid?: number | '';
  name?: string;
  source?: number | '';
  page_no?: number;
  page_size?: number;
}

export function getFileList(params: FileListParams, signal?: AbortSignal) {
  return axios.get<ListRes<FileRecord>>('/adminapi/peanut.file.list', {
    params,
    signal,
    timeout: 15_000,
  });
}

export function moveFile(ids: number[], cid: number) {
  return axios.post('/adminapi/peanut.file.move', { ids, cid });
}

export function renameFile(id: number, name: string) {
  return axios.post('/adminapi/peanut.file.rename', { id, name });
}

export function deleteFile(ids: number[]) {
  return axios.post('/adminapi/peanut.file.delete', { ids });
}

const uploadUrl: Record<FileType, string> = {
  10: '/adminapi/peanut.file.upload.image',
  20: '/adminapi/peanut.file.upload.video',
  30: '/adminapi/peanut.file.upload.file',
};

export function uploadFile(type: FileType, options: UploadRequestOptions) {
  const form = new FormData();
  form.append(options.filename || 'file', options.file);
  Object.entries(options.data || {}).forEach(([key, value]) =>
    form.append(key, value as string | Blob)
  );
  return axios
    .post<FileRecord>(uploadUrl[type], form, {
      onUploadProgress: (event) =>
        options.onProgress({
          ...event,
          percent: event.total ? (event.loaded / event.total) * 100 : 0,
        } as UploadProgressEvent),
    })
    .then(({ data }) => data);
}
