const TOKEN_KEY = 'token';
const SESSION_KEY = 'peanut-admin-session';
let sessionGeneration = 0;
let observedToken: string | null = null;
let hasObservedToken = false;
let observedSession: string | null = null;

const readStoredToken = () => localStorage.getItem(TOKEN_KEY);

const rememberToken = (token: string | null) => {
  observedToken = token;
  hasObservedToken = true;
};

const isLogin = () => {
  return !!localStorage.getItem(TOKEN_KEY);
};

const getToken = () => {
  const token = readStoredToken();
  const identity = localStorage.getItem(SESSION_KEY);
  if (!hasObservedToken) {
    rememberToken(token);
    observedSession = identity;
  } else if (
    identity !== observedSession ||
    (identity === null && token !== observedToken)
  ) {
    rememberToken(token);
    observedSession = identity;
    sessionGeneration += 1;
  }
  rememberToken(token);
  return token;
};

const setToken = (token: string) => {
  localStorage.setItem(TOKEN_KEY, token);
  rememberToken(token);
};

const clearToken = () => {
  localStorage.removeItem(TOKEN_KEY);
  rememberToken(null);
  sessionGeneration += 1;
  observedSession = Array.from(
    crypto.getRandomValues(new Uint32Array(4)),
    (value) => value.toString(16)
  ).join('-');
  localStorage.setItem(SESSION_KEY, observedSession);
};

const advanceSessionGeneration = () => {
  observedSession = Array.from(
    crypto.getRandomValues(new Uint32Array(4)),
    (value) => value.toString(16)
  ).join('-');
  localStorage.setItem(SESSION_KEY, observedSession);
  sessionGeneration += 1;
  return sessionGeneration;
};

const getSessionSnapshot = () => {
  const token = getToken();
  return { generation: sessionGeneration, token };
};

export {
  isLogin,
  getToken,
  setToken,
  clearToken,
  advanceSessionGeneration,
  getSessionSnapshot,
  SESSION_KEY,
};
