export interface RechargeRequestClient {
  get<T = unknown>(
    url: string,
    params?: Record<string, unknown>,
    auth?: boolean
  ): Promise<T>;
  post<T = unknown>(
    url: string,
    body?: Record<string, unknown> | null,
    auth?: boolean
  ): Promise<T>;
}

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
  page_no: number;
  page_size: number;
}

export interface RechargePayment {
  channel: 'wechat' | 'alipay' | string;
  scene: 'JSAPI' | 'MWEB' | 'NATIVE' | 'APP' | 'WAP' | 'PAGE' | string;
  payload: Record<string, string>;
}

export interface RechargePrepayResult {
  order: RechargeOrder;
  payment: RechargePayment;
}

export function getRechargeConfig(
  client: RechargeRequestClient
): Promise<RechargeConfig> {
  return client.get<RechargeConfig>('api/recharge/config', { terminal: 4 });
}

export function createRechargeOrder(
  client: RechargeRequestClient,
  amount: string
): Promise<RechargeOrder> {
  return client.post<RechargeOrder>('api/recharge/create', {
    terminal: 4,
    amount,
  });
}

export function prepayRecharge(
  client: RechargeRequestClient,
  orderId: number,
  payWay: number
): Promise<RechargePrepayResult> {
  return client.post<RechargePrepayResult>('api/recharge/prepay', {
    order_id: orderId,
    pay_way: payWay,
  });
}

export function getRechargeDetail(
  client: RechargeRequestClient,
  orderId: number
): Promise<RechargeOrder> {
  return client.get<RechargeOrder>('api/recharge/detail', {
    order_id: orderId,
  });
}

export function getRechargeLists(
  client: RechargeRequestClient,
  pageNo = 1,
  pageSize = 10
): Promise<RechargeListResult> {
  return client.get<RechargeListResult>('api/recharge/lists', {
    page_no: pageNo,
    page_size: pageSize,
  });
}
