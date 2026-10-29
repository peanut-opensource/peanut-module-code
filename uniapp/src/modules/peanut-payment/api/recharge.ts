import { http } from '@/utils/request';

export interface RechargeChannel {
  pay_way: number;
  name: string;
  is_default: number;
}

export interface RechargeConfig {
  status: number;
  min_amount: string;
  balance: string;
  terminal: number;
  channels: RechargeChannel[];
}

export interface RechargeOrder {
  id: number;
  sn: string;
  pay_way: number;
  pay_way_text: string;
  pay_status: number;
  pay_status_text: string;
  order_amount: string;
  order_terminal: number;
  terminal_text: string;
  transaction_id: string;
  pay_time: string;
  create_time: string;
}

export interface RechargeListResult {
  lists: RechargeOrder[];
  count: number;
  pageNo: number;
  pageSize: number;
}

export type PaymentScene = 'JSAPI' | 'MWEB' | 'NATIVE' | 'APP' | 'WAP' | 'PAGE';

export interface PaymentResult {
  channel: 'wechat' | 'alipay' | string;
  scene: PaymentScene | string;
  // Channel payloads intentionally stay open because the payment SDKs use
  // different keys for JSAPI, APP, browser and native QR flows.
  payload: Record<string, unknown>;
}

export interface RechargePrepayResult {
  order: RechargeOrder;
  payment: PaymentResult;
}

/** The terminal is derived from the current UniApp runtime, never user input. */
export function getRechargeTerminal(): number {
  const info = (
    typeof uni !== 'undefined' && typeof uni.getSystemInfoSync === 'function'
      ? uni.getSystemInfoSync()
      : {}
  ) as { uniPlatform?: string; platform?: string };
  const runtime = String(info.uniPlatform || '');
  const platform = String(info.platform || '').toLowerCase();
  if (runtime === 'mp-weixin') return 1;
  if (platform === 'ios') return 5;
  if (platform === 'android') return 6;
  return 3;
}

export function getRechargeConfig(terminal: number) {
  return http.get<RechargeConfig>('api/recharge/config', { terminal });
}

export function createRecharge(data: { terminal: number; amount: string }) {
  return http.post<RechargeOrder>('api/recharge/create', data);
}

export function prepayRecharge(data: { order_id: number; pay_way: number }) {
  return http.post<RechargePrepayResult>('api/recharge/prepay', data);
}

export function getRechargeDetail(orderId: number) {
  return http.get<RechargeOrder>('api/recharge/detail', { order_id: orderId });
}

export function getRechargeLists(
  params: { pageNo?: number; pageSize?: number } = {}
) {
  return http.get<RechargeListResult>('api/recharge/lists', {
    page_no: params.pageNo,
    page_size: params.pageSize,
  });
}
