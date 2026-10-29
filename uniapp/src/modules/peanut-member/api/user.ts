import { http } from '@/utils/request';

export interface UserCenter {
  id: number;
  sn: string;
  nickname: string;
  avatar: string;
  mobile: string;
  balance: string;
  points: number;
  create_time: string;
  collect_num: number;
}

export interface UserInfo {
  id: number;
  sn: string;
  account: string;
  nickname: string;
  avatar: string;
  sex: number;
  birthday: string;
  mobile: string;
  email: string;
  balance: string;
  points: number;
  create_time: string;
  has_password: boolean;
}

export interface SetInfoParams {
  field: 'nickname' | 'avatar' | 'sex' | 'birthday' | 'email';
  value: string | number;
}

export interface ChangePasswordParams {
  old_password: string;
  new_password: string;
  new_password_confirm: string;
}

export interface BindMobileParams {
  mobile: string;
}

/** GET api/user/center */
export function getUserCenter() {
  return http.get<UserCenter>('api/user/center');
}

/** GET api/user/info */
export function getUserInfo() {
  return http.get<UserInfo>('api/user/info');
}

/** POST api/user/setInfo */
export function setUserInfo(data: SetInfoParams) {
  return http.post('api/user/setInfo', { field: data.field, value: data.value });
}

/** POST api/user/changePassword */
export function changePassword(data: ChangePasswordParams) {
  return http.post('api/user/changePassword', { ...data });
}

/** POST api/user/bindMobile */
export function bindMobile(data: BindMobileParams) {
  return http.post('api/user/bindMobile', { ...data });
}
