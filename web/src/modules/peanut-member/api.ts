import axios from 'axios';

export interface MemberTagRecord {
  id: number;
  name: string;
  remark: string;
}

export interface AccountLogRecord {
  account: string;
  nickname: string;
  sn: string;
  avatar: string;
  mobile: string;
  action: 1 | 2;
  change_amount: string;
  left_amount: string;
  change_type: number;
  change_type_desc: string;
  source_sn: string;
  create_time: string;
}

export interface AccountLogParams {
  user_info?: string;
  change_type?: string | number;
  start_time?: string;
  end_time?: string;
  page_no?: number;
  page_size?: number;
}

export interface AccountLogListRes {
  lists: AccountLogRecord[];
  count: number;
  pageNo: number;
  pageSize: number;
}

export type AccountLogChangeTypeMap = Record<string, string>;

export interface MemberRecord {
  id: number;
  sn: string;
  account: string;
  nickname: string;
  avatar: string;
  mobile: string;
  email: string;
  real_name: string;
  sex: string;
  sex_value: number;
  channel: string;
  channel_value: number;
  birthday: string | null;
  status: number;
  is_disable: number;
  balance: number;
  user_money: number;
  total_recharge_amount: number;
  points: number;
  create_time: string;
  update_time: string;
  login_time: string;
  login_ip: string;
  tags?: MemberTagRecord[];
  tag_ids?: number[];
}

export interface MemberDetail {
  id: number;
  sn: string;
  account: string;
  nickname: string;
  avatar: string;
  real_name: string;
  sex: 0 | 1 | 2;
  mobile: string;
  create_time: string;
  login_time: string;
  channel: string;
  user_money: number;
  balance: number;
}

export type MemberEditableField = 'account' | 'sex' | 'mobile' | 'real_name';

export interface MemberForm {
  id?: number;
  nickname?: string;
  avatar?: string;
  mobile?: string;
  email?: string;
  sex?: number;
  birthday?: string | null;
  status?: number;
  tag_ids?: number[];
}
export type MemberTagForm = Partial<MemberTagRecord> & { id?: number };

export interface MemberListParams {
  keyword?: string;
  channel?: number | '';
  create_time_start?: string;
  create_time_end?: string;
  status?: string;
  page_no?: number;
  page_size?: number;
  export?: 1 | 2;
  page_type?: 0 | 1;
  page_start?: number;
  page_end?: number;
  file_name?: string;
}

export interface MemberListResult {
  lists: MemberRecord[];
  count: number;
  pageNo: number;
  pageSize: number;
}

export interface MemberExportInfo {
  count: number;
  page_size: number;
  sum_page: number;
  max_page: number;
  all_max_size: number;
  page_start: number;
  page_end: number;
  file_name: string;
}

export interface MemberExportResult {
  url: string;
  file_name: string;
}

export function getMemberList(params?: MemberListParams, signal?: AbortSignal) {
  return axios.get<MemberListResult>('/adminapi/peanut.member.list', {
    params,
    signal,
  });
}

export function getMemberExportInfo(
  params: MemberListParams,
  signal?: AbortSignal
) {
  return axios.get<MemberExportInfo>('/adminapi/peanut.member.list', {
    params: { ...params, export: 1 },
    signal,
  });
}

export function exportMembers(params: MemberListParams, signal?: AbortSignal) {
  return axios.get<MemberExportResult>('/adminapi/peanut.member.list', {
    params: { ...params, export: 2 },
    signal,
  });
}

export function getMemberDetail(id: number, signal?: AbortSignal) {
  return axios.get<MemberDetail>('/adminapi/peanut.member.detail', {
    params: { id },
    signal,
  });
}

export function addMember(data: MemberForm) {
  return axios.post('/adminapi/peanut.member.add', data);
}

export function updateMemberField(data: {
  id: number;
  field: MemberEditableField;
  value: string | number;
}) {
  return axios.post('/adminapi/peanut.member.edit', data);
}

export function updateMemberStatus(id: number, status: number) {
  return axios.post('/adminapi/peanut.member.update-status', { id, status });
}

export function adjustMemberMoney(
  data: {
    user_id: number;
    action: 1 | 2;
    num: number;
    remark?: string;
  },
  idempotencyKey = crypto.randomUUID()
) {
  return axios.post('/adminapi/peanut.member.balance.adjust', data, {
    headers: { 'Idempotency-Key': idempotencyKey },
  });
}

// 标签
export function getMemberTagList(signal?: AbortSignal) {
  return axios.get<MemberTagRecord[]>('/adminapi/peanut.member.tag.list', {
    signal,
  });
}

export function addMemberTag(data: MemberTagForm) {
  return axios.post('/adminapi/peanut.member.tag.add', data);
}

export function editMemberTag(data: MemberTagForm) {
  return axios.post('/adminapi/peanut.member.tag.edit', data);
}

export function deleteMemberTag(id: number) {
  return axios.post('/adminapi/peanut.member.tag.delete', { id });
}

export function getAccountLogList(params: AccountLogParams) {
  return axios.get<AccountLogListRes>(
    '/adminapi/peanut.member.account-log.list',
    {
      params,
    }
  );
}

export function getUmChangeType() {
  return axios.get<AccountLogChangeTypeMap>(
    '/adminapi/peanut.member.account-log.change-types'
  );
}
