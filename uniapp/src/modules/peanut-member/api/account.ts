import { http } from '@/utils/request';

export interface RegisterParams {
  account: string;
  password: string;
  password_confirm: string;
}

export interface LoginParams {
  account: string;
  password: string;
}

export interface LoginResult {
  token: string;
  id: number;
  sn: string;
  nickname: string;
  avatar: string;
  mobile: string;
}

/** POST api/login/register */
export function register(data: RegisterParams) {
  return http.post<void>('api/login/register', { ...data }, false);
}

/** POST api/login/account */
export function loginByAccount(data: LoginParams) {
  return http.post<LoginResult>('api/login/account', { ...data }, false);
}

/** POST api/login/logout */
export function logout() {
  return http.post('api/login/logout');
}
