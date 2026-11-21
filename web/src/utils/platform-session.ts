// @peanut-admin/client exports: createPlatformSession, PlatformSessionSnapshot.
// The 5.x hosts use this source until their dependency locks adopt these exports.
import { createSessionSync } from './session-sync.js';
import {
  newBrowserSessionIdentity,
  readBrowserSession,
  writeBrowserSession,
} from './session-storage.js';

const tokenKey = 'peanut-platform-token';
const sessionKey = 'peanut-platform-session';
const cookieLock = 'peanut-platform-session-cookie';

export interface PlatformSessionSnapshot {
  generation: string;
  token: string | null;
}

/** Used by the Platform shell and the Web module-governance client. */
export function createPlatformSession(reload: () => void) {
  let accepted =
    typeof window === 'undefined'
      ? null
      : readBrowserSession(sessionKey, tokenKey).identity;
  let queue: Promise<void> = Promise.resolve();
  const sync = createSessionSync('platform', ({ type }) => {
    if (type !== 'session-refreshed') reload();
  });

  const current = (): PlatformSessionSnapshot => {
    const { identity, token } = readBrowserSession(sessionKey, tokenKey);
    return {
      generation: identity ?? '',
      token: identity === accepted ? token : null,
    };
  };

  const sameIdentity = (snapshot: PlatformSessionSnapshot): boolean =>
    (accepted ?? '') === snapshot.generation &&
    readBrowserSession(sessionKey, tokenKey).identity === accepted;

  const matches = (snapshot: PlatformSessionSnapshot): boolean =>
    sameIdentity(snapshot) && current().token === snapshot.token;

  const setToken = (token: string | null, transition = true) => {
    if (readBrowserSession(sessionKey, tokenKey).identity !== accepted)
      throw new Error('The platform session has changed.');
    const identity = transition ? newBrowserSessionIdentity() : accepted;
    writeBrowserSession(sessionKey, tokenKey, { identity, token });
    accepted = identity;
    sync.publish(
      token === null
        ? 'logout'
        : transition
        ? 'tenant-switched'
        : 'session-refreshed'
    );
  };

  function withCookieLock<T>(
    operation: () => Promise<T>,
    snapshot: PlatformSessionSnapshot,
    requireWebLocks = false
  ): Promise<T> {
    const pending = queue.then(async () => {
      const run = () => {
        if (!sameIdentity(snapshot))
          throw new Error('The platform session has changed.');
        return operation();
      };
      const locks =
        typeof navigator === 'undefined' ? undefined : navigator.locks;
      if (locks) return await locks.request(cookieLock, run);
      if (requireWebLocks)
        throw new Error('Platform session refresh requires Web Locks.');
      return run();
    });
    queue = pending.then(
      () => undefined,
      () => undefined
    );
    return pending;
  }

  return {
    current,
    sameIdentity,
    matches,
    setToken,
    withCookieLock,
    dispose: sync.dispose,
  };
}
