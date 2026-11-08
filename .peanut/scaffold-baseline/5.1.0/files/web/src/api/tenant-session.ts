import axios from 'axios';
import type {
  TenantAuthentication,
  TenantSessionOutcome,
  TenantSelection,
} from '@peanut-admin/vue';
import { isTenantAccessToken } from '@peanut-admin/vue';
import {
  advanceSessionGeneration,
  getSessionSnapshot,
  setToken,
} from '@/utils/auth';
import i18n from '@/locale';

const tenantClient = axios.create({
  baseURL: import.meta.env.VITE_API_BASE_URL || undefined,
  withCredentials: true,
  timeout: 15_000,
});

tenantClient.interceptors.response.use(undefined, (error: unknown) => {
  if (axios.isCancel(error)) return Promise.reject(error);
  if (axios.isAxiosError<{ msg?: unknown }>(error)) {
    const message = error.response?.data?.msg;
    if (typeof message === 'string' && message.trim() !== '') {
      error.message = message;
    } else if (!error.response) {
      if (error.code === 'ECONNABORTED' || error.code === 'ETIMEDOUT') {
        error.message = i18n.global.t('tenantSession.requestTimeout');
      } else if (error.code === 'ERR_NETWORK' || error.request) {
        error.message = i18n.global.t('tenantSession.networkError');
      }
    }
  }
  return Promise.reject(error);
});

let sessionCookieQueue: Promise<void> = Promise.resolve();
const SESSION_COOKIE_LOCK = 'peanut-admin-tenant-session-cookie';

/** Serializes cookie changes in this tab and, when available, across same-origin tabs. */
function queueSessionCookieRequest<T>(
  operation: () => Promise<T>,
  generation = getSessionSnapshot().generation,
  requireCrossTabLock = false
): Promise<T> {
  const request = sessionCookieQueue.then(async () => {
    if (getSessionSnapshot().generation !== generation) {
      throw new Error('The tenant session has changed.');
    }
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
        if (getSessionSnapshot().generation !== generation) {
          throw new Error('The tenant session has changed.');
        }
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

function tenantData<T>(response: TenantEnvelope<T>): T {
  if (
    !response ||
    typeof response !== 'object' ||
    'code' in response ||
    response.data === null ||
    response.data === undefined
  ) {
    const failed = response as Partial<{ msg: string }> | null | undefined;
    throw new Error(failed?.msg || 'Tenant session returned no session data.');
  }
  return response.data;
}

export async function tenantLogin(
  email: string,
  password: string,
  generation?: number
) {
  return queueSessionCookieRequest(async () => {
    const response = await tenantClient.post<
      TenantEnvelope<TenantSessionOutcome>
    >('/adminapi/tenant/session/login', { email, password });
    return tenantData(response.data);
  }, generation);
}

export async function selectTenant(
  challengeToken: string,
  tenantId: number,
  generation?: number
) {
  return queueSessionCookieRequest(async () => {
    const response = await tenantClient.post<
      TenantEnvelope<TenantAuthentication>
    >('/adminapi/tenant/session/select', {
      challenge_token: challengeToken,
      tenant_id: tenantId,
    });
    return tenantData(response.data);
  }, generation);
}

export async function tenantSwitch(accessToken: string) {
  const response = await tenantClient.post<TenantEnvelope<TenantSelection>>(
    '/adminapi/tenant/session/switch',
    {},
    { headers: { Authorization: `Bearer ${accessToken}` } }
  );
  return tenantData(response.data);
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
      const authentication = tenantData(response.data);
      const latest = getSessionSnapshot();
      if (latest.generation !== generation) {
        throw new Error('The tenant session has changed.');
      }
      if (latest.token === accessToken) {
        setToken(authentication.access_token);
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

export async function tenantLogout(
  expected: { generation: number; token: string },
  onStart: (token: string, generation: number) => void
): Promise<void> {
  if (!isTenantAccessToken(expected.token)) {
    throw new Error('The tenant session has changed.');
  }
  return queueSessionCookieRequest(async () => {
    const { token } = getSessionSnapshot();
    if (token === null || !isTenantAccessToken(token)) {
      throw new Error('The tenant session has changed.');
    }
    // A refresh that held this lock first may have rotated the access token.
    const generation = advanceSessionGeneration();
    onStart(token, generation);
    await tenantClient.post(
      '/adminapi/tenant/session/logout',
      {},
      { headers: { Authorization: `Bearer ${token}` } }
    );
  }, expected.generation);
}
