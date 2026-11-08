import { isTenantAccessToken } from '@peanut-admin/vue';
import { refreshTenantSession } from '@/api/tenant-session';
import { getSessionSnapshot } from '@/utils/auth';

export interface TenantSessionSnapshot {
  generation: number;
  token: string | null;
}

type RequestAbortSignal = Pick<
  AbortSignal,
  'aborted' | 'addEventListener' | 'removeEventListener'
> & { readonly reason?: unknown };

const refreshRequests = new Map<string, Promise<string>>();

const refreshKey = (snapshot: TenantSessionSnapshot): string =>
  JSON.stringify([snapshot.generation, snapshot.token]);

function awaitRefresh(
  pending: Promise<string>,
  signal?: RequestAbortSignal
): Promise<string> {
  if (!signal) return pending;
  const aborted = () =>
    signal.reason ?? new DOMException('The request was aborted.', 'AbortError');
  if (signal.aborted) return Promise.reject(aborted());
  return new Promise<string>((resolve, reject) => {
    const onAbort = () => {
      signal.removeEventListener('abort', onAbort);
      reject(aborted());
    };
    signal.addEventListener('abort', onAbort, { once: true });
    pending.then(
      (token) => {
        signal.removeEventListener('abort', onAbort);
        resolve(token);
      },
      (error: unknown) => {
        signal.removeEventListener('abort', onAbort);
        reject(error);
      }
    );
  });
}

/** Shares an in-flight refresh; each issuing request decides whether to retry once. */
export async function refreshTenantAccessToken(
  snapshot: TenantSessionSnapshot,
  signal?: RequestAbortSignal
): Promise<string> {
  if (signal?.aborted) {
    throw (
      signal.reason ??
      new DOMException('The request was aborted.', 'AbortError')
    );
  }
  const current = getSessionSnapshot();
  const requestToken = snapshot.token;
  if (
    snapshot.generation !== current.generation ||
    requestToken === null ||
    !isTenantAccessToken(requestToken)
  ) {
    throw new Error('The request session has changed.');
  }

  const key = refreshKey(snapshot);
  if (current.token !== requestToken) {
    // Login, logout and tenant switch advance generation; a same-generation
    // tenant token may have been refreshed by another tab.
    if (current.token !== null && isTenantAccessToken(current.token)) {
      return current.token;
    }
    throw new Error('The request session has changed.');
  }

  let pending = refreshRequests.get(key);
  if (!pending) {
    pending = refreshTenantSession(requestToken, snapshot.generation).finally(
      () => refreshRequests.delete(key)
    );
    refreshRequests.set(key, pending);
  }
  let token: string;
  try {
    token = await awaitRefresh(pending, signal);
  } catch (error) {
    if (signal?.aborted) throw error;
    const latest = getSessionSnapshot();
    // Another tab may have completed the same session's rotation while this
    // request's refresh failed. The business request still retries only once.
    if (
      latest.generation === snapshot.generation &&
      latest.token !== requestToken &&
      latest.token !== null &&
      isTenantAccessToken(latest.token)
    ) {
      return latest.token;
    }
    throw error;
  }
  const latest = getSessionSnapshot();
  if (signal?.aborted) {
    throw (
      signal.reason ??
      new DOMException('The request was aborted.', 'AbortError')
    );
  }
  if (latest.generation !== snapshot.generation) {
    throw new Error('The request session has changed.');
  }
  if (latest.token === token) return token;
  if (latest.token !== null && isTenantAccessToken(latest.token)) {
    return latest.token;
  }
  throw new Error('The request session has changed.');
}
