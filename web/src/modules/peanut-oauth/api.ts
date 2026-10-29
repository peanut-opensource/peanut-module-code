import axios from 'axios';
import type { PageData } from '@/types/global';

export interface WebPageConfig {
  status: 0 | 1;
  page_status: 0 | 1;
  page_url: string;
  url: string;
}

export function getWebPageConfig() {
  return axios.get<WebPageConfig>('/adminapi/peanut.oauth.web-page.config');
}

export function saveWebPageConfig(data: Omit<WebPageConfig, 'url'>) {
  return axios.post('/adminapi/peanut.oauth.web-page.save', data);
}

export interface MiniProgramConfig {
  name: string;
  original_id: string;
  qr_code: string;
  app_id: string;
  app_secret: string;
  request_domain: string;
  socket_domain: string;
  upload_file_domain: string;
  download_file_domain: string;
  udp_domain: string;
  business_domain: string;
}

export function getMiniProgramConfig() {
  return axios.get<MiniProgramConfig>(
    '/adminapi/peanut.oauth.mini-program.config'
  );
}

export function saveMiniProgramConfig(
  data: Pick<
    MiniProgramConfig,
    'name' | 'original_id' | 'qr_code' | 'app_id' | 'app_secret'
  >
) {
  return axios.post('/adminapi/peanut.oauth.mini-program.save', data);
}

/** 微信公众号配置（敏感 AppSecret 与回调 Token 只返回固定掩码）。 */
export interface OfficialAccountConfig {
  name: string;
  original_id: string;
  qr_code: string;
  app_id: string;
  app_secret: string;
  app_secret_configured: boolean;
  url: string;
  token: string;
  token_configured: boolean;
  business_domain: string;
  js_secure_domain: string;
  web_auth_domain: string;
  /** 当前 Peanut 回调处理能力固定为明文。 */
  callback_mode: 'plaintext' | string;
}

export type OfficialAccountConfigForm = Pick<
  OfficialAccountConfig,
  'name' | 'original_id' | 'qr_code' | 'app_id' | 'app_secret' | 'token'
>;

export function getOfficialAccountConfig() {
  return axios.get<OfficialAccountConfig>(
    '/adminapi/peanut.oauth.official-account.config'
  );
}

export function saveOfficialAccountConfig(data: OfficialAccountConfigForm) {
  return axios.post('/adminapi/peanut.oauth.official-account.save', data);
}

export type OfficialAccountMenuType = 'click' | 'view' | 'miniprogram';

export interface OfficialAccountMenuItem {
  name: string;
  type?: OfficialAccountMenuType;
  key?: string;
  url?: string;
  appid?: string;
  pagepath?: string;
  sub_button?: OfficialAccountMenuItem[];
}

export interface OfficialAccountMenuResponse {
  menu: OfficialAccountMenuItem[];
}

export function getOfficialAccountMenu() {
  return axios.get<OfficialAccountMenuResponse>(
    '/adminapi/peanut.oauth.official-account.menu.detail'
  );
}

export function saveOfficialAccountMenu(menu: OfficialAccountMenuItem[]) {
  return axios.post('/adminapi/peanut.oauth.official-account.menu.save', {
    menu,
  });
}

export function publishOfficialAccountMenu(menu: OfficialAccountMenuItem[]) {
  return axios.post('/adminapi/peanut.oauth.official-account.menu.publish', {
    menu,
  });
}

export type OfficialAccountReplyType = 1 | 2 | 3;
export type OfficialAccountMatchingType = 1 | 2;

export interface OfficialAccountReplyRecord {
  id: number;
  name: string;
  keyword: string;
  reply_type: OfficialAccountReplyType;
  matching_type: OfficialAccountMatchingType;
  content_type: 1;
  content: string;
  status: 0 | 1;
  sort: number;
  create_time?: number;
  update_time?: number;
}

export interface OfficialAccountReplyListParams {
  reply_type?: OfficialAccountReplyType;
  page_no?: number;
  page_size?: number;
}

export type OfficialAccountReplyListResponse =
  PageData<OfficialAccountReplyRecord>;

export type OfficialAccountReplyForm = Pick<
  OfficialAccountReplyRecord,
  | 'name'
  | 'keyword'
  | 'reply_type'
  | 'matching_type'
  | 'content_type'
  | 'content'
  | 'status'
  | 'sort'
> & { id?: number };

export function getOfficialAccountReplyList(
  params: OfficialAccountReplyListParams
) {
  return axios.get<OfficialAccountReplyListResponse>(
    '/adminapi/peanut.oauth.official-account.reply.list',
    { params }
  );
}

export function getOfficialAccountReplyDetail(id: number) {
  return axios.get<OfficialAccountReplyRecord>(
    '/adminapi/peanut.oauth.official-account.reply.detail',
    { params: { id } }
  );
}

export function addOfficialAccountReply(data: OfficialAccountReplyForm) {
  return axios.post(
    '/adminapi/peanut.oauth.official-account.reply.add',
    data
  );
}

export function editOfficialAccountReply(data: OfficialAccountReplyForm) {
  return axios.post(
    '/adminapi/peanut.oauth.official-account.reply.edit',
    data
  );
}

export function deleteOfficialAccountReply(id: number) {
  return axios.post('/adminapi/peanut.oauth.official-account.reply.delete', {
    id,
  });
}

export function updateOfficialAccountReplyStatus(id: number, status: 0 | 1) {
  return axios.post(
    '/adminapi/peanut.oauth.official-account.reply.update-status',
    {
      id,
      status,
    }
  );
}

export interface OpenPlatformConfig {
  app_id: string;
  app_secret: string;
  app_secret_configured: boolean;
}

export type OpenPlatformConfigForm = Pick<
  OpenPlatformConfig,
  'app_id' | 'app_secret'
>;

export function getOpenPlatformConfig() {
  return axios.get<OpenPlatformConfig>(
    '/adminapi/peanut.oauth.open-platform.config'
  );
}

export function saveOpenPlatformConfig(data: OpenPlatformConfigForm) {
  return axios.post('/adminapi/peanut.oauth.open-platform.save', data);
}
