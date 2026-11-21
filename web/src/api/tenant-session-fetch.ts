import { ElMessage } from 'element-plus';
import { assertSessionGeneration, getSessionSnapshot } from '@/utils/auth';
import { formatRequestError } from '@/utils/request-trace';
import { createSessionFetch } from '@peanut-admin/client';
import { tenantSessionRecovery } from '@/api/tenant-session-refresh';

/** Only module authentication middleware may authorize automatic replay. */
const fetchWithSession = createSessionFetch({
  recovery: tenantSessionRecovery,
  isAllowedRequest: (request) => {
    const api = new URL(
      import.meta.env.VITE_API_BASE_URL || '/',
      window.location.href
    );
    const url = new URL(request.url);
    return (
      url.origin === api.origin && url.pathname.startsWith('/adminapi/api/v1/')
    );
  },
  isExpired: async (response, request) => {
    if (
      response.status !== 401 ||
      !new URL(request.url).pathname.startsWith('/adminapi/api/v1/')
    )
      return false;
    let body: unknown;
    try {
      body = await response.clone().json();
    } catch {
      return false;
    }
    return (
      typeof body === 'object' &&
      body !== null &&
      'code' in body &&
      body.code === 40100
    );
  },
});

export async function tenantSessionFetch(request: Request): Promise<Response> {
  assertSessionGeneration(getSessionSnapshot().generation);
  const response = await fetchWithSession(request);
  if (!response.ok && response.headers.has('X-Trace-Id')) {
    ElMessage.error(
      formatRequestError('请求失败，请稍后重试', {
        status: response.status,
        headers: { 'X-Trace-Id': response.headers.get('X-Trace-Id') },
      })
    );
  }
  return response;
}

export default tenantSessionFetch;
