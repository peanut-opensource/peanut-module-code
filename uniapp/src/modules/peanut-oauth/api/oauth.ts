import { http } from '@/utils/request';

export type WechatOAuthScene = 'mnp' | 'oa' | 'open_pc';

export interface OAuthMember {
  id: number;
  sn: string;
  nickname: string;
  avatar: string;
  mobile: string;
}

export interface OAuthResult {
  completed: boolean;
  token?: string;
  member: OAuthMember;
  completion_ticket?: string;
  expires_in?: number;
  need_profile?: boolean;
  need_mobile?: boolean;
  return_path?: string;
}

export interface OAuthCompletionState {
  ticket: string;
  need_profile: boolean;
  need_mobile: boolean;
  return_path: string;
}

const OAUTH_COMPLETION_STORAGE_KEY = 'peanut_oauth_completion';

function getSessionStorage(): Storage | null {
  try {
    const storage = (globalThis as { sessionStorage?: Storage }).sessionStorage;
    if (
      !storage ||
      typeof storage.getItem !== 'function' ||
      typeof storage.setItem !== 'function' ||
      typeof storage.removeItem !== 'function'
    ) {
      return null;
    }
    return storage;
  } catch (_) {
    return null;
  }
}

function hasUniStorage() {
  return (
    typeof uni !== 'undefined' &&
    typeof uni.getStorageSync === 'function' &&
    typeof uni.setStorageSync === 'function' &&
    typeof uni.removeStorageSync === 'function'
  );
}

function parseOAuthCompletionState(raw: unknown): OAuthCompletionState | null {
  let value = raw;
  if (typeof value === 'string') {
    try {
      value = JSON.parse(value);
    } catch (_) {
      return null;
    }
  }
  if (!value || typeof value !== 'object') return null;

  const data = value as Record<string, unknown>;
  const ticket = typeof data.ticket === 'string' ? data.ticket : '';
  if (!ticket) return null;

  return {
    ticket,
    need_profile:
      data.need_profile === true ||
      data.need_profile === '1' ||
      data.need_profile === 'true',
    need_mobile:
      data.need_mobile === true ||
      data.need_mobile === '1' ||
      data.need_mobile === 'true',
    return_path:
      typeof data.return_path === 'string' && data.return_path
        ? data.return_path
        : '/pages/user/user',
  };
}

export function stashOAuthCompletion(
  result: Pick<
    OAuthResult,
    'completion_ticket' | 'need_profile' | 'need_mobile' | 'return_path'
  >,
  returnPath = result.return_path || '/pages/user/user'
): OAuthCompletionState {
  const ticket = String(result.completion_ticket || '').trim();
  if (!ticket) throw new Error('微信登录补全票据缺失');

  const state: OAuthCompletionState = {
    ticket,
    need_profile: Boolean(result.need_profile),
    need_mobile: Boolean(result.need_mobile),
    return_path: returnPath || '/pages/user/user',
  };
  const serialized = JSON.stringify(state);

  const sessionStorage = getSessionStorage();
  if (sessionStorage) {
    try {
      sessionStorage.setItem(OAUTH_COMPLETION_STORAGE_KEY, serialized);
      if (hasUniStorage()) {
        try {
          uni.removeStorageSync(OAUTH_COMPLETION_STORAGE_KEY);
        } catch (_) {}
      }
      return state;
    } catch (_) {
      // Fall back to Uni storage when sessionStorage is unavailable or full.
    }
  }

  if (!hasUniStorage()) throw new Error('微信登录补全暂存不可用');
  try {
    uni.setStorageSync(OAUTH_COMPLETION_STORAGE_KEY, serialized);
  } catch (_) {
    throw new Error('微信登录补全暂存不可用');
  }
  return state;
}

export function consumeOAuthCompletion(): OAuthCompletionState | null {
  const sessionStorage = getSessionStorage();
  if (sessionStorage) {
    try {
      const raw = sessionStorage.getItem(OAUTH_COMPLETION_STORAGE_KEY);
      sessionStorage.removeItem(OAUTH_COMPLETION_STORAGE_KEY);
      if (raw) return parseOAuthCompletionState(raw);
    } catch (_) {
      // Fall back to Uni storage when sessionStorage is unavailable.
    }
  }

  if (!hasUniStorage()) return null;
  let raw: unknown = null;
  try {
    raw = uni.getStorageSync(OAUTH_COMPLETION_STORAGE_KEY);
  } finally {
    try {
      uni.removeStorageSync(OAUTH_COMPLETION_STORAGE_KEY);
    } catch (_) {
      // The value has still been read and will not be reused by this helper.
    }
  }
  return parseOAuthCompletionState(raw);
}

export interface OAuthBeginResult {
  authorization_url: string;
  expires_in: number;
}

export function beginWechatOAuth(data: {
  scene: 'oa' | 'open_pc';
  return_path: string;
}) {
  return http.post<OAuthBeginResult>('api/oauth/wechat/begin', data, false);
}

export function callbackWechatOAuth(data: {
  scene: 'oa' | 'open_pc';
  code: string;
  state: string;
}) {
  return http.post<OAuthResult>('api/oauth/wechat/callback', data, false);
}

export function loginWechatMiniProgram(code: string) {
  return http.post<OAuthResult>(
    'api/oauth/wechat/mini-program',
    { code },
    false
  );
}

export function completeWechatOAuth(data: {
  ticket: string;
  nickname?: string;
  avatar?: string;
  mobile?: string;
  verification_code?: string;
}) {
  return http.post<OAuthResult>('api/oauth/wechat/complete', data, false);
}

export function bindWechatIdentity(scene: 'mnp' | 'oa', code: string) {
  return http.post('api/oauth/wechat/bind', { scene, code });
}
