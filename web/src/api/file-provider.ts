import type { UploadRequestOptions } from 'element-plus';
import contributions from 'virtual:peanut-module-contributions';

// 文件类型：10 图片 / 20 视频 / 30 文件
export type FileType = 10 | 20 | 30;

export const defaultFileUploadAccept: Record<FileType, string> = {
  10: 'image/*',
  20: 'video/*',
  30: '.zip,.rar,.txt,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.csv,.7z,.gz,.mp3,.wav,.ogg',
};

export interface FileCateRecord {
  id: number;
  pid: number;
  type: FileType;
  name: string;
  create_time?: string;
  children?: FileCateRecord[];
}

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
  can_download_original?: boolean;
  create_time?: string;
}

export interface FileListParams {
  type: FileType;
  cid?: number | '';
  name?: string;
  source?: number | '';
  page_no?: number;
  page_size?: number;
}

export interface FileListResult {
  lists: FileRecord[];
  count: number;
  pageNo: number;
  pageSize: number;
}

export interface FileProvider {
  fileUploadAccept?: Record<FileType, string>;
  getFileCateList: (
    type: FileType
  ) => Promise<{ data: FileCateRecord[] }>;
  getFileList: (
    params: FileListParams,
    signal?: AbortSignal
  ) => Promise<{ data: FileListResult }>;
  uploadFile: (
    type: FileType,
    options: UploadRequestOptions
  ) => Promise<FileRecord>;
}

/** Resolve the installed File capability from locked module contributions, if any. */
export function resolveFileProvider(): FileProvider | null {
  for (const contribution of contributions) {
    if (contribution.fileProvider) {
      return contribution.fileProvider;
    }
  }
  return null;
}
