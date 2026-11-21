// @peanut-admin/client exports: readBrowserSession, writeBrowserSession, newBrowserSessionIdentity.
// The 5.x hosts use this source until their dependency locks adopt these exports.
interface BrowserSession {
  identity: string | null;
  token: string | null;
}

/** The active identity and bearer must change in one observable storage write. */
export function readBrowserSession(
  key: string,
  legacyTokenKey: string,
  legacyIdentityKey?: string
): BrowserSession {
  const value = localStorage.getItem(key);
  if (!value?.startsWith('{')) {
    return {
      identity: legacyIdentityKey
        ? localStorage.getItem(legacyIdentityKey)
        : value,
      token: localStorage.getItem(legacyTokenKey),
    };
  }
  try {
    const state = JSON.parse(value) as BrowserSession;
    if (
      (state.identity === null || typeof state.identity === 'string') &&
      (state.token === null || typeof state.token === 'string')
    )
      return state;
  } catch {
    // Treat a corrupted credential as unauthenticated.
  }
  return { identity: null, token: null };
}

export function writeBrowserSession(
  key: string,
  legacyTokenKey: string,
  session: BrowserSession
): void {
  localStorage.setItem(key, JSON.stringify(session));
  localStorage.removeItem(legacyTokenKey);
}

export function newBrowserSessionIdentity(): string {
  return Array.from(crypto.getRandomValues(new Uint32Array(4)), (value) =>
    value.toString(16).padStart(8, '0')
  ).join('');
}
