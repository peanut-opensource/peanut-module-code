import { createSessionSync, type SessionSyncType } from './session-sync';
import {
  newBrowserSessionIdentity,
  readBrowserSession,
  writeBrowserSession,
} from './session-storage';

const TOKEN_KEY = 'token';
// Modules use this identity-only stamp in durable draft and recovery keys.
const SESSION_KEY = 'peanut-admin-session';
const SESSION_STATE_KEY = 'peanut-admin-session-state';
const readSession = () =>
  readBrowserSession(SESSION_STATE_KEY, TOKEN_KEY, SESSION_KEY);
let sessionGeneration = 0;
let acceptedIdentity =
  typeof window === 'undefined' ? null : readSession().identity;
let remoteIdentityChanged = false;

// Switch to @peanut-admin/client after the locked release exports this helper.
const sessionSync = createSessionSync('admin', ({ type }) => {
  if (type !== 'session-refreshed') window.location.reload();
});
import.meta.hot?.dispose(sessionSync.dispose);

const getToken = () => {
  const { identity, token } = readSession();
  if (identity !== acceptedIdentity) {
    if (!remoteIdentityChanged) sessionGeneration += 1;
    remoteIdentityChanged = true;
  }
  // Old tenant UI cannot borrow a new bearer while its reload event is pending.
  return remoteIdentityChanged ? null : token;
};

const isLogin = () => getToken() !== null;
const getSessionSnapshot = () => {
  const token = getToken();
  return { generation: sessionGeneration, token };
};

const assertSessionGeneration = (expected: number) => {
  if (getSessionSnapshot().generation !== expected || remoteIdentityChanged) {
    throw new Error('The request session has changed.');
  }
};

const commit = (token: string | null, type: SessionSyncType) => {
  assertSessionGeneration(sessionGeneration);
  const transitionsIdentity = type !== 'session-refreshed';
  const identity = transitionsIdentity
    ? newBrowserSessionIdentity()
    : acceptedIdentity;
  writeBrowserSession(SESSION_STATE_KEY, TOKEN_KEY, { identity, token });
  if (identity !== null && transitionsIdentity) {
    localStorage.setItem(SESSION_KEY, identity);
    acceptedIdentity = identity;
    sessionGeneration += 1;
  }
  sessionSync.publish(type);
};

const setToken = (token: string, type: Exclude<SessionSyncType, 'logout'>) =>
  commit(token, type);
const clearToken = () => commit(null, 'logout');

export {
  SESSION_KEY,
  assertSessionGeneration,
  isLogin,
  getToken,
  setToken,
  clearToken,
  getSessionSnapshot,
};
