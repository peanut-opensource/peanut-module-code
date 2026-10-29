import { reactive } from 'vue';
import { getToken } from '@/utils/auth';
import { parseAssetList } from './contracts';
import type { AssetCandidate } from './contracts';

export const FILE_ASSET_MODULE_KEY = 'peanut.file' as const;
export const FILE_ASSET_ROUTE_NAME = 'peanut.file.assets' as const;
export const FILE_ASSET_ROUTE_PATH = '/system/file/assets' as const;
export const FILE_ASSET_READ_PERMISSION = 'peanut.file.list' as const;

export interface FileAssetError {
  readonly message: string;
  readonly requestId: string | null;
  readonly status: number | null;
}

export interface FileAssetState {
  items: AssetCandidate[];
  selectedFileKey: string | null;
  page: number;
  pageSize: number;
  total: number;
  loading: boolean;
  error: FileAssetError | null;
}

export interface FileAssetRuntime {
  readonly state: FileAssetState;
  load: (page?: number) => Promise<void>;
  select: (asset: AssetCandidate) => void;
  dispose: () => void;
}

export interface FileAssetRuntimeOptions {
  readonly canRead: () => boolean;
  readonly request?: (
    page: number,
    pageSize: number,
    signal: AbortSignal
  ) => Promise<Response>;
}

const defaultRequest = (
  page: number,
  pageSize: number,
  signal: AbortSignal
): Promise<Response> => {
  const token = getToken();
  return fetch(
    `/adminapi/api/v1/files/assets?page_no=${page}&page_size=${pageSize}`,
    {
      method: 'GET',
      credentials: 'same-origin',
      headers: token ? { Authorization: `Bearer ${token}` } : {},
      signal,
    }
  );
};

export const createFileAssetRuntime = (
  options: FileAssetRuntimeOptions
): FileAssetRuntime => {
  const state = reactive<FileAssetState>({
    items: [],
    selectedFileKey: null,
    page: 1,
    pageSize: 24,
    total: 0,
    loading: false,
    error: null,
  });
  let generation = 0;
  let controller: AbortController | null = null;
  const load = async (page = state.page): Promise<void> => {
    generation += 1;
    const current = generation;
    controller?.abort();
    controller = new AbortController();
    state.loading = true;
    state.error = null;
    try {
      if (!options.canRead()) {
        state.error = {
          message: '你没有查看图片素材的权限。',
          requestId: null,
          status: 403,
        };
        return;
      }
      const response = await (options.request ?? defaultRequest)(
        page,
        state.pageSize,
        controller.signal
      );
      const body: unknown = await response.json().catch(() => null);
      if (current !== generation) return;
      if (!response.ok) {
        const problem =
          typeof body === 'object' && body !== null
            ? (body as Record<string, unknown>)
            : {};
        state.error = {
          message:
            typeof problem.msg === 'string'
              ? problem.msg
              : `图片素材加载失败（${response.status}）。`,
          requestId: response.headers.get('X-Request-Id'),
          status: response.status,
        };
        return;
      }
      const result = parseAssetList(body);
      state.items = [...result.items];
      state.page = result.page;
      state.pageSize = result.pageSize;
      state.total = result.total;
      if (
        state.selectedFileKey !== null &&
        !state.items.some((item) => item.fileKey === state.selectedFileKey)
      ) {
        state.selectedFileKey = null;
      }
    } catch (error) {
      if (
        current === generation &&
        !(error instanceof DOMException && error.name === 'AbortError')
      ) {
        state.error = {
          message: '暂时无法连接图片素材服务。',
          requestId: null,
          status: null,
        };
      }
    } finally {
      if (current === generation) state.loading = false;
    }
  };
  return {
    state,
    load,
    select(asset) {
      state.selectedFileKey = asset.fileKey;
    },
    dispose() {
      generation += 1;
      controller?.abort();
      controller = null;
      state.items = [];
      state.selectedFileKey = null;
    },
  };
};
