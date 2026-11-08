import axios from 'axios';
import type { AxiosRequestConfig, AxiosResponse } from 'axios';
import { ElMessage, ElMessageBox } from 'element-plus';
import { useUserStore } from '@/store';
import { getSessionSnapshot, getToken } from '@/utils/auth';
import i18n from '@/locale';
import { isTenantAccessToken } from '@peanut-admin/vue';
import {
  refreshTenantAccessToken,
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
    if (snapshot.generation !== getSessionSnapshot().generation) {
      return Promise.reject(new Error('The request session has changed.'));
    }
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
    return Promise.reject(
      new Error(res.msg || 'The request session has changed.')
    );
  }
  const canRefresh =
    res.code === 40100 &&
    isTenantAccessToken(snapshot.token) &&
    !retryConfig.tenantRefreshRetried &&
    retryConfig.url !== '/adminapi/tenant/session/refresh' &&
    retryConfig.url !== '/adminapi/tenant/session/logout';
  if (snapshot.token !== currentSession.token && !canRefresh) {
    return Promise.reject(
      new Error(res.msg || 'The request session has changed.')
    );
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
        snapshot.token = refreshedToken;
        retryConfig.headers = retryConfig.headers || {};
        retryConfig.headers.Authorization = `Bearer ${refreshedToken}`;
        return axios.request(retryConfig);
      } catch (error) {
        if (retryConfig.signal?.aborted) return Promise.reject(error);
        // Continue to the existing re-login flow below.
      }
    }
    if (
      snapshot.generation !== getSessionSnapshot().generation ||
      snapshot.token !== getToken()
    ) {
      return Promise.reject(
        new Error(res.msg || 'The request session has changed.')
      );
    }
    ElMessage.error({
      message: res.msg || 'Error',
      duration: 5 * 1000,
    });
    if (
      [40100].includes(res.code) &&
      response.config.url !== '/adminapi/user/info'
    ) {
      ElMessageBox.confirm(
        'You have been logged out, you can cancel to stay on this page, or log in again',
        'Confirm logout',
        {
          confirmButtonText: 'Re-Login',
          cancelButtonText: 'Cancel',
          type: 'error',
        }
      )
        .then(async () => {
          if (
            snapshot.generation !== getSessionSnapshot().generation ||
            snapshot.token !== getToken()
          )
            return;
          const userStore = useUserStore();
          try {
            await userStore.logout(snapshot);
          } catch {
            // Local logout cleanup may finish before tenant-state disposal reports an error.
          }
          if (
            getSessionSnapshot().generation === snapshot.generation + 2 &&
            getToken() === null
          )
            window.location.reload();
        })
        .catch(() => undefined);
    }
    return Promise.reject(new Error(res.msg || 'Error'));
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
  let message = error.msg || 'Request Error';
  if (axios.isAxiosError(error) && !error.response) {
    if (error.code === 'ECONNABORTED' || error.code === 'ETIMEDOUT') {
      message = i18n.global.t('tenantSession.requestTimeout');
    } else if (error.code === 'ERR_NETWORK' || error.request) {
      message = i18n.global.t('tenantSession.networkError');
    }
  }
  ElMessage.error({
    message,
    duration: 5 * 1000,
  });
  return Promise.reject(error);
});
