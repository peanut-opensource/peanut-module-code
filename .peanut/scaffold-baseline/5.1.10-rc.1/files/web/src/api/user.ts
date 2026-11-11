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

export async function getUserInfo() {
  const response = await axios.post<UserState & { website: WebsiteConfig }>(
    '/adminapi/user/info'
  );
  const bindings: unknown = response.data.action_bindings;
  if (
    !Array.isArray(bindings) ||
    bindings.some(
      (item: unknown) =>
        typeof item !== 'object' ||
        item === null ||
        !('page_path' in item) ||
        typeof item.page_path !== 'string' ||
        !item.page_path.startsWith('/') ||
        !('action_key' in item) ||
        typeof item.action_key !== 'string' ||
        !item.action_key.trim() ||
        !('permission_key' in item) ||
        typeof item.permission_key !== 'string' ||
        !item.permission_key.trim()
    )
  )
    throw new Error('Invalid action permission bindings');
  return response;
}

export function getMenuList() {
  return axios.post<ServerMenuRecord[]>('/adminapi/user/menu');
}
