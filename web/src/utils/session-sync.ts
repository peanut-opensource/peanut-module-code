// @peanut-admin/client exports: createSessionSync, SessionSyncEvent, SessionSyncType.
// The 5.x hosts use this source until their dependency locks adopt these exports.
export type SessionSyncType = 'logout' | 'tenant-switched' | 'session-refreshed';

export interface SessionSyncEvent {
  readonly type: SessionSyncType;
  readonly originTabId: string;
}

/**
 * Same-origin notification only. Receivers must read the committed host session
 * from their existing store. Never send bearer tokens or account data here.
 */
export function createSessionSync(
  namespace: string,
  onEvent: (event: SessionSyncEvent) => void
) {
  if (typeof window === 'undefined') {
    return { publish: (_type: SessionSyncType) => {}, dispose: () => {} };
  }

  const browser = window;
  const key = `peanut:session-sync:${namespace}`;
  const originTabId = Array.from(browser.crypto.getRandomValues(new Uint32Array(4)))
    .map((value) => value.toString(16).padStart(8, '0'))
    .join('');
  let channel: BroadcastChannel | undefined;
  try {
    if (typeof BroadcastChannel !== 'undefined') channel = new BroadcastChannel(key);
  } catch {
    // Some embedded browsers expose BroadcastChannel but deny access.
  }

  const receive = (input: unknown) => {
    if (!input || typeof input !== 'object') return;
    const { type, originTabId: sender } = input as Partial<SessionSyncEvent>;
    if (
      (type !== 'logout' && type !== 'tenant-switched' && type !== 'session-refreshed') ||
      typeof sender !== 'string' || !sender || sender === originTabId
    ) return;
    onEvent({ type, originTabId: sender });
  };
  const onMessage = (event: MessageEvent) => receive(event.data);
  const onStorage = (event: StorageEvent) => {
    if (event.key !== key || !event.newValue) return;
    let input: unknown;
    try {
      if (event.storageArea !== browser.localStorage) return;
      input = JSON.parse(event.newValue);
    } catch {
      return;
    }
    receive(input);
  };

  if (channel) channel.addEventListener('message', onMessage);
  else browser.addEventListener('storage', onStorage);
  let disposed = false;

  return {
    publish(type: SessionSyncType) {
      if (disposed) return;
      const event: SessionSyncEvent = { type, originTabId };
      if (channel) {
        channel.postMessage(event);
        return;
      }
      try {
        browser.localStorage.setItem(key, JSON.stringify(event));
        // Each write must trigger an event even when a type repeats.
        browser.localStorage.removeItem(key);
      } catch {
        // A browser with neither transport can still use its local session.
      }
    },
    dispose() {
      if (disposed) return;
      disposed = true;
      if (channel) {
        channel.removeEventListener('message', onMessage);
        channel.close();
      } else browser.removeEventListener('storage', onStorage);
    },
  };
}
