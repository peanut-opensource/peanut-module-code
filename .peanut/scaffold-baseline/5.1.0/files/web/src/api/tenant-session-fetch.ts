import { getSessionSnapshot } from '@/utils/auth';
import { refreshTenantAccessToken } from '@/api/tenant-session-refresh';
import type { TenantSessionSnapshot } from '@/api/tenant-session-refresh';

const sessionChanged = () => new Error('The request session has changed.');

function assertCurrentSession(
  snapshot: TenantSessionSnapshot,
  token = snapshot.token
): void {
  const current = getSessionSnapshot();
  if (current.generation !== snapshot.generation || current.token !== token) {
    throw sessionChanged();
  }
}

function assertNotAborted(signal: AbortSignal): void {
  if (signal.aborted) {
    throw (
      signal.reason ??
      new DOMException('The request was aborted.', 'AbortError')
    );
  }
}

function authorizedRequest(request: Request, token: string | null): Request {
  const headers = new Headers(request.headers);
  headers.delete('Authorization');
  if (token) headers.set('Authorization', `Bearer ${token}`);
  return new Request(request, { headers });
}

async function isExpiredTenantSession(response: Response): Promise<boolean> {
  if (response.status !== 401) return false;
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
}

/** Opt-in transport for tenant module routes whose 40100 is emitted before the controller. */
export async function tenantSessionFetch(request: Request): Promise<Response> {
  const snapshot = getSessionSnapshot();
  assertNotAborted(request.signal);
  assertCurrentSession(snapshot);

  let replay: Request | null = null;
  try {
    replay = request.clone();
  } catch {
    // An unreplayable body can still be sent once, but is never retried.
  }

  const response = await fetch(authorizedRequest(request, snapshot.token));
  assertNotAborted(request.signal);
  const expired =
    new URL(request.url).pathname.startsWith('/adminapi/api/v1/') &&
    (await isExpiredTenantSession(response));
  assertNotAborted(request.signal);
  if (!expired || replay === null) {
    assertCurrentSession(snapshot);
    return response;
  }

  let token: string;
  try {
    token = await refreshTenantAccessToken(snapshot, request.signal);
  } catch {
    assertNotAborted(request.signal);
    assertCurrentSession(snapshot);
    return response;
  }
  assertNotAborted(request.signal);
  assertCurrentSession(snapshot, token);
  const retried = await fetch(authorizedRequest(replay, token));
  assertNotAborted(request.signal);
  assertCurrentSession(snapshot, token);
  return retried;
}

export default tenantSessionFetch;
