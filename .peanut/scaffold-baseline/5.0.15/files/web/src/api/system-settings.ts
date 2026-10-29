import axios from 'axios';

export interface UserSetting {
  default_avatar: string;
}

export interface LoginSetting {
  login_way: number[];
  coerce_mobile: number;
  login_agreement: number;
  third_auth: number;
  wechat_auth: number;
}

export const getUserSetting = () =>
  axios.get<UserSetting>('/adminapi/config/user');
export const saveUserSetting = (data: UserSetting) =>
  axios.post('/adminapi/config/user/save', data);
export const getLoginSetting = () =>
  axios.get<LoginSetting>('/adminapi/config/login');
export const saveLoginSetting = (data: LoginSetting) =>
  axios.post('/adminapi/config/login/save', data);
