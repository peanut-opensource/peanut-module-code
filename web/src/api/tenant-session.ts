import axios from 'axios';
import type {
  TenantAuthentication,
  TenantSessionOutcome,
  TenantSelection,
} from '@peanut-admin/vue';
import { isTenantAccessToken } from '@peanut-admin/vue';
import {
  assertSessionGeneration,
  getSessionSnapshot,
  setToken,
} from '@/utils/auth';
import i18n from '@/locale';
import { formatRequestError } from '@peanut-admin/client';

const tenantClient = axios.create({
  baseURL: import.meta.env.VITE_API_BASE_URL || undefined,
  withCredentials: true,
  timeout: 15_000,
});

tenantClient.interceptors.response.use(undefined, (error: unknown) => {
  if (axios.isCancel(error)) return Promise.reject(error);
  if (axios.isAxiosError<{ msg?: unknown }>(error)) {
    const message = error.response?.data?.msg;
    if (error.response) error.message = '请求失败，请稍后重试';
    if (typeof message === 'string' && message.trim() !== '') {
      error.message = message;
    } else if (!error.response) {
      const translate = (
        i18n.global as unknown as { t: (key: string) => string }
      ).t;
      if (error.code === 'ECONNABORTED' || error.code === 'ETIMEDOUT') {
        error.message = translate('tenantSession.requestTimeout');
      } else if (error.code === 'ERR_NETWORK' || error.request) {
        error.message = translate('tenantSession.networkError');
      }
    }
    error.message = formatRequestError(error.message, error.response);
  }
  return Promise.reject(error);
});

let sessionCookieQueue: Promise<void> = Promise.resolve();
const SESSION_COOKIE_LOCK = 'peanut-admin-tenant-session-cookie';

/** Serializes cookie changes in this tab and, when available, across same-origin tabs. */
export function queueSessionCookieRequest<T>(
  operation: () => Promise<T>,
  generation = getSessionSnapshot().generation,
  requireCrossTabLock = false
): Promise<T> {
  const request = sessionCookieQueue.then(async () => {
    assertSessionGeneration(generation);
    const locks =
      typeof navigator === 'undefined' ? undefined : navigator.locks;
    if (!locks) {
      // A refresh token is single-use. Never refresh without cross-tab exclusion.
      if (requireCrossTabLock) {
        throw new Error('Tenant session refresh requires Web Locks.');
      }
      return operation();
    }
    const result = await locks.request<Promise<T>>(
      SESSION_COOKIE_LOCK,
      { mode: 'exclusive' },
      () => {
        assertSessionGeneration(generation);
        return operation();
      }
    );
    return result;
  });
  sessionCookieQueue = request.then(
    () => undefined,
    () => undefined
  );
  return request;
}

type TenantEnvelope<T> =
  | {
      data: T;
      meta: { request_id: string };
    }
  | {
      code: number;
      msg: string;
      data: null;
    };

function tenantData<T>(result: {
  data: TenantEnvelope<T>;
  status: number;
  headers?: Record<string, unknown>;
}): T {
  const response = result.data;
  if (
    !response ||
    typeof response !== 'object' ||
    'code' in response ||
    response.data === null ||
    response.data === undefined
  ) {
    const failed = response as Partial<{ msg: string }> | null | undefined;
    throw new Error(
      formatRequestError(
        failed?.msg || 'Tenant session returned no session data.',
        result
      )
    );
  }
  return response.data;
}

// Login, selection and logout are used inside a complete cookie transition.
export async function tenantLogin(email: string, password: string) {
  const response = await tenantClient.post<
    TenantEnvelope<TenantSessionOutcome>
  >('/adminapi/tenant/session/login', { email, password });
  return tenantData(response);
}

export async function selectTenant(challengeToken: string, tenantId: number) {
  const response = await tenantClient.post<
    TenantEnvelope<TenantAuthentication>
  >('/adminapi/tenant/session/select', {
    challenge_token: challengeToken,
    tenant_id: tenantId,
  });
  return tenantData(response);
}

export async function tenantSwitch(accessToken: string) {
  const response = await tenantClient.post<TenantEnvelope<TenantSelection>>(
    '/adminapi/tenant/session/switch',
    {},
    { headers: { Authorization: `Bearer ${accessToken}` } }
  );
  return tenantData(response);
}

export async function refreshTenantSession(
  accessToken: string,
  generation: number
): Promise<string> {
  return queueSessionCookieRequest(
    async () => {
      const current = getSessionSnapshot();
      if (current.token !== accessToken) {
        if (current.token !== null && isTenantAccessToken(current.token)) {
          return current.token;
        }
        throw new Error('The tenant session has changed.');
      }
      const response = await tenantClient.post<
        TenantEnvelope<TenantAuthentication>
      >(
        '/adminapi/tenant/session/refresh',
        {},
        { headers: { Authorization: `Bearer ${accessToken}` } }
      );
      const authentication = tenantData(response);
      const latest = getSessionSnapshot();
      if (latest.generation !== generation) {
        throw new Error('The tenant session has changed.');
      }
      if (latest.token === accessToken) {
        setToken(authentication.access_token, 'session-refreshed');
        return authentication.access_token;
      }
      if (latest.token !== null && isTenantAccessToken(latest.token)) {
        return latest.token;
      }
      throw new Error('The tenant session has changed.');
    },
    generation,
    true
  );
}

export async function tenantLogout(token: string): Promise<void> {
  await tenantClient.post(
    '/adminapi/tenant/session/logout',
    {},
    { headers: { Authorization: `Bearer ${token}` } }
  );
}
