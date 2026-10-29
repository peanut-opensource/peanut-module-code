import { http } from '@/utils/request';

export interface AccountLog {
  id: number;
  change_type: number;
  change_amount: string;
  left_amount: string;
  remark: string;
  create_time: string;
}

export interface AccountLogResult {
  lists: AccountLog[];
  count: number;
  page_no: number;
  page_size: number;
}

/** GET api/account_log/lists */
export function getAccountLogs(
  params: { page_no?: number; page_size?: number } = {}
) {
  return http.get<AccountLogResult>(
    'api/account_log/lists',
    params as Record<string, unknown>
  );
}
