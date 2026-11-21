import assert from 'node:assert/strict';
import { registerHooks } from 'node:module';
import { test } from 'node:test';

// Mirror Vite resolution for extensionless and emitted session imports.
registerHooks({
  resolve(specifier, context, next) {
    return next(specifier.replace(/^(\.\/session-(?:sync|storage))(?:\.js)?$/, '$1.ts'), context);
  },
});

const data = new Map();
const localStorage = {
  getItem: (key) => data.get(key) ?? null,
  setItem: (key, value) => data.set(key, value),
  removeItem: (key) => data.delete(key),
};
globalThis.localStorage = localStorage;
globalThis.BroadcastChannel = undefined;
globalThis.window = Object.assign(new EventTarget(), { crypto, localStorage });

test('web only picks up bearer rotations within the identity it loaded', async () => {
  localStorage.setItem('peanut-admin-session', 'tenant-a');
  localStorage.setItem('token', 'access-a');
  const auth = await import('../../src/utils/auth.ts');
  const initial = auth.getSessionSnapshot();
  auth.setToken('access-a-rotated', 'session-refreshed');
  assert.deepEqual(auth.getSessionSnapshot(), { generation: initial.generation, token: 'access-a-rotated' });
  assert.equal(localStorage.getItem(auth.SESSION_KEY), 'tenant-a');
  assert.equal(localStorage.getItem('token'), null);
  localStorage.setItem('peanut-admin-session-state', JSON.stringify({ identity: 'tenant-b', token: 'access-b' }));
  const stale = auth.getSessionSnapshot();
  assert.equal(stale.token, null);
  assert.throws(() => auth.assertSessionGeneration(stale.generation), /session has changed/);
});

test('platform waits for the cross-tab refresh before taking the bearer for logout', async () => {
  const { createPlatformSession } = await import('../../../platform/src/core/platform-session.ts');
  Object.defineProperty(navigator, 'locks', {
    configurable: true,
    value: {
      pending: Promise.resolve(),
      request(_name, run) {
        const result = this.pending.then(run);
        this.pending = result.catch(() => {});
        return result;
      },
    },
  });
  localStorage.setItem('peanut-platform-session', 'platform-a');
  localStorage.setItem('peanut-platform-token', 'access-a');
  const a = createPlatformSession(() => {});
  const b = createPlatformSession(() => {});
  const initial = a.current();
  const release = Promise.withResolvers();
  const acquired = Promise.withResolvers();
  const refresh = a.withCookieLock(async () => {
    acquired.resolve();
    await release.promise;
    a.setToken('access-a-rotated', false);
  }, initial, true);
  try {
    await acquired.promise;
    const logout = b.withCookieLock(async () => {
      assert.equal(b.current().token, 'access-a-rotated');
      b.setToken(null);
    }, b.current());
    assert.deepEqual(a.current(), initial);
    release.resolve();
    await Promise.all([refresh, logout]);
    const stale = a.current();
    assert.equal(stale.token, null);
    assert.equal(a.sameIdentity(stale), false);
    assert.equal(JSON.parse(localStorage.getItem('peanut-platform-session')).token, null);
    assert.equal(localStorage.getItem('peanut-platform-token'), null);
  } finally {
    a.dispose();
    b.dispose();
    delete navigator.locks;
  }
});
