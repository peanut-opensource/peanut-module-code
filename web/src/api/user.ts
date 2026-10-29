import axios from 'axios';
import { UserState } from '@/store/modules/user/types';
import type { ServerMenuRecord } from '@/store/modules/app/types';
import type { WebsiteConfig } from '@/api/system/config';

export interface LoginData {
  username: string;
  password: string;
  tenantId?: number;
  challengeToken?: string;
}

export function getUserInfo() {
  return axios.post<UserState & { website: WebsiteConfig }>(
    '/adminapi/user/info'
  );
}

export function getMenuList() {
  return axios.post<ServerMenuRecord[]>('/adminapi/user/menu');
}
