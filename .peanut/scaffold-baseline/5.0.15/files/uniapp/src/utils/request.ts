import {
  ClientRequestError,
  createClient,
  type ClientDecodeResult,
} from '@peanut-admin/client';
import { createUniAppClientTransport } from '@peanut-admin/uniapp';
import { useUserStore } from '@/store/user';

const configuredBaseUrl = import.meta.env.VITE_APP_BASE_URL || '';

interface RequestOptions {
  url: string;
  method?: 'GET' | 'POST' | 'PUT' | 'DELETE';
  data?: Record<string, unknown>;
  header?: Record<string, string>;
  /** skip auth check — for login/register/public routes */
  auth?: boolean;
  signal?: AbortSignal;
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function isRequestMethod(
  value: string
): value is NonNullable<UniNamespace.RequestOptions['method']> {
  switch (value) {
    case 'OPTIONS':
    case 'GET':
    case 'HEAD':
    case 'POST':
    case 'PUT':
    case 'DELETE':
    case 'TRACE':
    case 'CONNECT':
      return true;
    default:
      return false;
  }
}

function baseUrl(): string {
  if (configuredBaseUrl) return configuredBaseUrl;
  const runtime: unknown = globalThis;
  if (!isRecord(runtime) || !isRecord(runtime.location)) return '';
  const { origin } = runtime.location;
  return typeof origin === 'string' ? origin : '';
}

function decodeResponse(response: unknown): ClientDecodeResult {
  if (
    !isRecord(response) ||
    typeof response.code !== 'number' ||
    !Number.isFinite(response.code) ||
    (response.msg !== undefined && typeof response.msg !== 'string') ||
    (response.code === 20000 && !('data' in response))
  ) {
    return {
      kind: 'business',
      code: 'API_RESPONSE_INVALID',
      message: '响应格式无效',
    };
  }
  // Only the shared envelope is validated here; endpoint-specific data remains unknown.
  if (response.code === 20000) {
    return { kind: 'success', data: response.data };
  }
  if (response.code === 40100) {
    return {
      kind: 'unauthorized',
      code: 'AUTH_REQUIRED',
      message: response.msg || '请先登录',
    };
  }
  return {
    kind: 'business',
    code: 'BUSINESS_REJECTED',
    message: response.msg || '请求失败',
  };
}

const transport = createUniAppClientTransport({
  baseUrl: baseUrl(),
  request: (options) => {
    if (!isRequestMethod(options.method)) {
      throw new Error('UNIAPP_REQUEST_METHOD_INVALID');
    }
    if (options.data !== undefined && !isRecord(options.data)) {
      throw new Error('UNIAPP_REQUEST_DATA_INVALID');
    }
    return uni.request({
      url: options.url,
      method: options.method,
      data: options.data,
      header: options.header,
      timeout: 20_000,
      success: (response) => options.success?.({ data: response.data }),
      fail: options.fail,
    });
  },
});

const createSessionClient = (generation: number) => {
  let clearedGeneration: number | null = null;
  return createClient({
    transport,
    session: {
      accessToken: () =>
        useUserStore().sessionGeneration === generation
          ? useUserStore().token
          : null,
      clear: (expectedToken?: string | null) => {
        if (expectedToken === undefined) return;
        const userStore = useUserStore();
        if (userStore.sessionGeneration !== generation) return;
        if (userStore.clearIfToken(expectedToken))
          clearedGeneration = userStore.sessionGeneration;
      },
    },
    decoder: decodeResponse,
    hooks: {
      unauthorized: () => {
        const userStore = useUserStore();
        if (
          clearedGeneration === userStore.sessionGeneration &&
          !userStore.token
        ) {
          uni.reLaunch({ url: '/pages/login/login' });
        }
      },
      businessError: (error) =>
        uni.showToast({ title: error.message || '请求失败', icon: 'none' }),
    },
  });
};

async function request<T = unknown>(options: RequestOptions): Promise<T> {
  try {
    const client = createSessionClient(useUserStore().sessionGeneration);
    return await client.request<T>({
      path: options.url,
      method: options.method,
      data: options.data,
      headers: {
        'Content-Type': 'application/json',
        ...options.header,
      },
      auth: options.auth,
      signal: options.signal,
    });
  } catch (error) {
    if (error instanceof ClientRequestError && error.kind === 'transport') {
      uni.showToast({ title: '网络错误，请稍后重试', icon: 'none' });
    }
    throw error;
  }
}

export interface UploadedImage {
  id: number;
  cid: number;
  type: number;
  name: string;
  file_key: string;
  uri: string;
  url: string;
}

export function uploadImage(filePath: string): Promise<UploadedImage> {
  const store = useUserStore();
  const generation = store.sessionGeneration;
  const token = store.token;
  if (!token) return Promise.reject(new Error('请先登录'));

  return new Promise((resolve, reject) => {
    uni.uploadFile({
      url: `${baseUrl()}/api/upload/image`,
      filePath,
      name: 'file',
      header: { Authorization: `Bearer ${token}` },
      timeout: 20_000,
      success: (response) => {
        let envelope: unknown;
        try {
          envelope = typeof response.data === 'string'
            ? JSON.parse(response.data)
            : response.data;
        } catch {
          reject(new Error('上传响应格式无效'));
          return;
        }
        if (!isRecord(envelope) || typeof envelope.code !== 'number') {
          reject(new Error('上传响应格式无效'));
          return;
        }
        if (envelope.code === 40100) {
          if (store.sessionGeneration === generation && store.clearIfToken(token))
            uni.reLaunch({ url: '/pages/login/login' });
          reject(new Error(typeof envelope.msg === 'string' ? envelope.msg : '请先登录'));
          return;
        }
        if (response.statusCode < 200 || response.statusCode >= 300 || envelope.code !== 20000 || !isRecord(envelope.data) || typeof envelope.data.url !== 'string') {
          reject(new Error(typeof envelope.msg === 'string' ? envelope.msg : '图片上传失败'));
          return;
        }
        resolve(envelope.data as unknown as UploadedImage);
      },
      fail: () => reject(new Error('网络错误，请稍后重试')),
    });
  });
}

export const http = {
  get<T = unknown>(
    url: string,
    data?: Record<string, unknown>,
    auth = true,
    signal?: AbortSignal
  ) {
    return request<T>({ url, method: 'GET', data, auth, signal });
  },
  post<T = unknown>(
    url: string,
    data?: Record<string, unknown>,
    auth = true,
    signal?: AbortSignal
  ) {
    return request<T>({ url, method: 'POST', data, auth, signal });
  },
};
