import axios from 'axios';
import type { AxiosRequestConfig, AxiosResponse } from 'axios';
import { ElMessage } from 'element-plus';
import { formatRequestError } from '@/utils/request-trace';
import {
  assertSessionGeneration,
  getSessionSnapshot,
  getToken,
} from '@/utils/auth';
import i18n from '@/locale';
import { isTenantAccessToken } from '@peanut-admin/vue';
import {
  refreshTenantAccessToken,
  invalidateTenantSession,
  type TenantSessionSnapshot,
} from '@/api/tenant-session-refresh';

export interface HttpResponse<T = unknown> {
  status: number;
  msg: string;
  code: number;
  data: T;
}

if (import.meta.env.VITE_API_BASE_URL) {
  axios.defaults.baseURL = import.meta.env.VITE_API_BASE_URL;
}

type SessionRequestConfig = AxiosRequestConfig & {
  tenantRefreshRetried?: boolean;
  tenantSession?: TenantSessionSnapshot;
};

/** Captures the issuing session so old responses cannot affect a later login. */
axios.interceptors.request.use(
  (config: AxiosRequestConfig) => {
    const requestConfig = config as SessionRequestConfig;
    const snapshot = requestConfig.tenantSession ?? getSessionSnapshot();
    assertSessionGeneration(snapshot.generation);
    requestConfig.tenantSession = snapshot;
    const token = getToken();
    if (token) {
      if (!config.headers) {
        config.headers = {};
      }
      config.headers.Authorization = `Bearer ${token}`;
    }
    return config;
  },
  (error) => {
    return Promise.reject(error);
  }
);

/** Handles each response only while its captured session is still current. */
const handleResponse = async (response: AxiosResponse<HttpResponse>) => {
  const res = response.data;
  const retryConfig = response.config as SessionRequestConfig;
  const snapshot = retryConfig.tenantSession ?? getSessionSnapshot();
  retryConfig.tenantSession = snapshot;
  const currentSession = getSessionSnapshot();
  if (snapshot.generation !== currentSession.generation) {
    return Promise.reject(new Error('The request session has changed.'));
  }
  const canRefresh =
    res.code === 40100 &&
    isTenantAccessToken(snapshot.token) &&
    !retryConfig.tenantRefreshRetried &&
    retryConfig.url !== '/adminapi/tenant/session/refresh' &&
    retryConfig.url !== '/adminapi/tenant/session/logout';
  if (snapshot.token !== currentSession.token && !canRefresh) {
    return Promise.reject(new Error('The request session has changed.'));
  }
  // The legacy export endpoint uses code 2 to signal a generated file response.
  if (![20000, 2].includes(res.code)) {
    if (canRefresh) {
      retryConfig.tenantRefreshRetried = true;
      try {
        const refreshedToken = await refreshTenantAccessToken(
          snapshot,
          retryConfig.signal
        );
        retryConfig.tenantSession = { ...snapshot, token: refreshedToken };
        retryConfig.headers = retryConfig.headers || {};
        retryConfig.headers.Authorization = `Bearer ${refreshedToken}`;
        return axios.request(retryConfig);
      } catch (error) {
        // Core clears only definitive refresh rejection. Network failure preserves the session.
        return Promise.reject(error);
      }
    }
    if (
      snapshot.generation !== getSessionSnapshot().generation ||
      snapshot.token !== getToken()
    ) {
      return Promise.reject(new Error('The request session has changed.'));
    }
    const message = formatRequestError(
      res.msg || '请求失败，请稍后重试',
      response
    );
    if (res.code === 40100) await invalidateTenantSession(snapshot);
    else ElMessage.error({ message, duration: 5 * 1000 });
    return Promise.reject(new Error(message));
  }
  return res;
};

axios.interceptors.response.use(handleResponse, (error) => {
  if (axios.isCancel(error)) return Promise.reject(error);
  const response = axios.isAxiosError(error)
    ? (error.response as AxiosResponse<HttpResponse> | undefined)
    : undefined;
  if (response?.data && typeof response.data.code === 'number') {
    return handleResponse(response);
  }
  let message = '请求失败，请稍后重试';
  if (axios.isAxiosError(error) && !error.response) {
    const translate = (i18n.global as unknown as { t: (key: string) => string })
      .t;
    if (error.code === 'ECONNABORTED' || error.code === 'ETIMEDOUT') {
      message = translate('tenantSession.requestTimeout');
    } else if (error.code === 'ERR_NETWORK' || error.request) {
      message = translate('tenantSession.networkError');
    }
  }
  message = formatRequestError(message, response);
  ElMessage.error({ message, duration: 5 * 1000 });
  if (axios.isAxiosError(error)) error.message = message;
  return Promise.reject(error);
});
