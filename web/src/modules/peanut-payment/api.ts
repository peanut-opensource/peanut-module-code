import axios from 'axios';
import type { PageData } from '@/types/global';

export interface PayConfig {
  wx_pay_status: number;
  wx_pay_appid: string;
  wx_pay_mch_id: string;
  wx_pay_secret: string;
  wx_pay_secret_configured?: boolean;
  wx_pay_cert_path: string;
  wx_pay_cert_key_path: string;
  wx_pay_platform_cert_path: string;
  ali_pay_status: number;
  ali_pay_app_id: string;
  ali_pay_private_key: string;
  ali_pay_private_key_configured?: boolean;
  ali_pay_public_key: string;
  ali_pay_seller_id: string;
}

export interface RechargeScene {
  terminal: number;
  pay_way: number;
  status: number;
  is_default: number;
}

export interface RechargeSetting {
  status: number;
  min_amount: string;
  max_amount: string;
  scenes: RechargeScene[];
}

export const getPayConfig = () =>
  axios.get<PayConfig>('/adminapi/peanut.payment.settings.detail');
export const savePayConfig = (data: PayConfig) =>
  axios.post('/adminapi/peanut.payment.settings.save', data);
export const getRechargeSetting = () =>
  axios.get<RechargeSetting>(
    '/adminapi/peanut.payment.recharge-settings.detail'
  );
export const saveRechargeSetting = (data: RechargeSetting) =>
  axios.post('/adminapi/peanut.payment.recharge-settings.save', data);

// ─── 充值订单 ────────────────────────────────────────────────────────────────
export interface RechargeRecord {
  id: number;
  sn: string;
  order_amount: string;
  pay_way: 1 | 2 | 3;
  pay_time: string;
  pay_status: 0 | 1;
  create_time: string;
  refund_status: 0 | 1;
  refunded_amount: string;
  refundable_amount: string;
  avatar: string;
  nickname: string;
  account: string;
  pay_status_text: string;
  pay_way_text: string;
}

export interface RechargeParams {
  sn?: string;
  user_info?: string;
  pay_way?: string | number;
  pay_status?: string | number;
  start_time?: string;
  end_time?: string;
  page_no?: number;
  page_size?: number;
  export?: 1 | 2;
  page_type?: 0 | 1;
  page_start?: number;
  page_end?: number;
  file_name?: string;
}

export type RechargeListRes = PageData<RechargeRecord> & {
  extend: [];
};

export interface RechargeExportInfo {
  count: number;
  page_size: number;
  sum_page: number;
  max_page: number;
  all_max_size: number;
  page_start: number;
  page_end: number;
  file_name: string;
}

export interface RechargeExportResult {
  url: string;
  file_name?: string;
}

export function getRechargeList(params: RechargeParams) {
  return axios.get<RechargeListRes>(
    '/adminapi/peanut.payment.recharge.list',
    {
      params,
    }
  );
}

export function getRechargeExportInfo(params: RechargeParams) {
  return axios.get<RechargeExportInfo>(
    '/adminapi/peanut.payment.recharge.list',
    {
      params: { ...params, export: 1 },
    }
  );
}

export function exportRecharge(params: RechargeParams) {
  return axios.get<RechargeExportResult>(
    '/adminapi/peanut.payment.recharge.list',
    { params: { ...params, export: 2 } }
  );
}

export function refundRecharge(
  rechargeId: number,
  refundAmount?: number | string,
  idempotencyKey = crypto.randomUUID()
) {
  return axios.post(
    '/adminapi/peanut.payment.recharge.refund',
    {
      recharge_id: rechargeId,
      ...(refundAmount === undefined ? {} : { refund_amount: refundAmount }),
    },
    { headers: { 'Idempotency-Key': idempotencyKey } }
  );
}

// ─── 退款模块 ────────────────────────────────────────────────────────────────
export interface RefundStat {
  total: number;
  ing: number;
  success: number;
  error: number;
}

export interface RefundRecord {
  id: number;
  sn: string;
  user_id: number;
  nickname: string;
  avatar: string;
  order_id: number;
  order_sn: string;
  order_type: string;
  order_amount: string;
  refund_amount: string;
  transaction_id: string;
  refund_way: number;
  refund_type: number;
  refund_type_text: string;
  refund_way_text: string;
  refund_status: number;
  refund_status_text: string;
  create_time: string;
  update_time?: number | string | null;
}

export interface RefundListExtend {
  total: number;
  ing: number;
  success: number;
  error: number;
}

export type RefundListRes = PageData<RefundRecord> & {
  extend: RefundListExtend;
};

export interface RefundParams {
  sn?: string;
  order_sn?: string;
  user_info?: string;
  refund_type?: string | number;
  refund_status?: string | number;
  start_time?: string;
  end_time?: string;
  page_no?: number;
  page_size?: number;
}

export interface RefundLogRecord {
  id: number;
  sn: string;
  record_id: number;
  user_id: number;
  handle_id: number;
  order_amount: string;
  refund_amount: string;
  refund_status: number;
  refund_status_text: string;
  handler: string;
  create_time: string;
  update_time?: number | string | null;
}

export function getRefundStat() {
  return axios.get<RefundStat>('/adminapi/peanut.payment.refund.stat');
}

export function getRefundRecords(params: RefundParams) {
  return axios.get<RefundListRes>('/adminapi/peanut.payment.refund.list', {
    params,
  });
}

export function getRefundLog(recordId: number) {
  return axios.get<RefundLogRecord[]>('/adminapi/peanut.payment.refund.log', {
    params: { record_id: recordId },
  });
}

export function refundAgain(recordId: number) {
  return axios.post('/adminapi/peanut.payment.refund.retry', {
    record_id: recordId,
  });
}
