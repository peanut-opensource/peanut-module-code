import axios from 'axios';
import type { PageData } from '@/types/global';

// ─── 渠道配置 ─────────────────────────────────────────────────────────────────

export interface SmsAliyunConfig {
  access_key_id: string;
  access_key_secret: string;
  sign_name: string;
  status: number;
}

export interface SmsTencentConfig {
  secret_id: string;
  secret_key: string;
  sdk_app_id: string;
  sign_name: string;
  region: string;
  status: number;
}

export interface NoticeChannelDetail {
  sms_default: 'aliyun' | 'tencent' | '';
  sms_aliyun: SmsAliyunConfig;
  sms_tencent: SmsTencentConfig;
  status: { sms: boolean };
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function isChannelConfiguration(
  value: unknown,
  stringFields: readonly string[]
): boolean {
  return (
    isRecord(value) &&
    stringFields.every((field) => typeof value[field] === 'string') &&
    (value.status === 0 || value.status === 1)
  );
}

function isNoticeChannelDetail(value: unknown): value is NoticeChannelDetail {
  return (
    isRecord(value) &&
    (value.sms_default === '' ||
      value.sms_default === 'aliyun' ||
      value.sms_default === 'tencent') &&
    isChannelConfiguration(value.sms_aliyun, [
      'access_key_id',
      'access_key_secret',
      'sign_name',
    ]) &&
    isChannelConfiguration(value.sms_tencent, [
      'secret_id',
      'secret_key',
      'sdk_app_id',
      'sign_name',
      'region',
    ]) &&
    isRecord(value.status) &&
    typeof value.status.sms === 'boolean'
  );
}

// ─── 固定业务场景 ───────────────────────────────────────────────────────────

export interface NoticeSceneRecord {
  id: number;
  code: string;
  name: string;
  description: string;
  recipient: string;
  variables: string[];
  sms_template_id: string;
  sms_content: string;
  sms_status: number;
  update_time: number;
}

export function getNoticeSceneList() {
  return axios.get<{ list: NoticeSceneRecord[]; total: number }>(
    '/adminapi/peanut.notification.scene.list'
  );
}

export function getNoticeSceneDetail(id: number) {
  return axios.get<NoticeSceneRecord>(
    '/adminapi/peanut.notification.scene.detail',
    {
      params: { id },
    }
  );
}

export function saveNoticeScene(
  data: Pick<
    NoticeSceneRecord,
    'id' | 'sms_template_id' | 'sms_content' | 'sms_status'
  >
) {
  return axios.post('/adminapi/peanut.notification.scene.save', data);
}

export type ChannelSection = 'sms_default' | 'sms_aliyun' | 'sms_tencent';

export async function getNoticeChannelDetail() {
  const response = await axios.get<unknown>(
    '/adminapi/peanut.notification.channel.detail'
  );
  if (!isNoticeChannelDetail(response.data)) {
    // Do not include raw channel configuration or secret fields in errors.
    throw new Error('NOTIFICATION_CHANNEL_RESPONSE_INVALID');
  }
  return { ...response, data: response.data };
}

export function saveNoticeChannel(
  section: ChannelSection,
  data: Record<string, unknown>
) {
  return axios.post('/adminapi/peanut.notification.channel.save', {
    section,
    ...data,
  });
}

// ─── 发送日志 ─────────────────────────────────────────────────────────────────

export interface NoticeLogRecord {
  id: number;
  template_id: number;
  template_name: string;
  template_code: string;
  scene_id: number;
  scene_name: string;
  scene_code: string;
  channel: number;
  receiver: string;
  title: string;
  content: string;
  status: number; // 0待发 1成功 2失败
  provider: string;
  is_verified: number;
  check_count: number;
  verified_time: number;
  error: string;
  send_time: number;
  create_time: number;
}

export function getNoticeLogList(params?: {
  receiver?: string;
  channel?: string;
  status?: string;
  scene_id?: string;
  start_time?: number;
  end_time?: number;
  page_no?: number;
  page_size?: number;
}) {
  return axios.get<PageData<NoticeLogRecord>>(
    '/adminapi/peanut.notification.log.list',
    {
      params,
    }
  );
}

export function getNoticeLogDetail(id: number) {
  return axios.get<NoticeLogRecord>(
    '/adminapi/peanut.notification.log.detail',
    {
      params: { id },
    }
  );
}
