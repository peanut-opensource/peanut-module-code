import { getSessionSnapshot } from '@/utils/auth';

export const base = '/adminapi/custom.ai-assistant.';

export async function current<T>(work: () => Promise<{ data: T }>): Promise<T> {
  const { generation } = getSessionSnapshot();
  const { data } = await work();
  if (generation !== getSessionSnapshot().generation)
    throw new Error('会话已切换，请重新加载');
  return data;
}
