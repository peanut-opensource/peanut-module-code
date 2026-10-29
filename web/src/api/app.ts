import axios from 'axios';

// ─── 热门搜索 ────────────────────────────────────────────────────────────────
export interface HotSearchItem {
  id?: number;
  name: string;
  sort: number;
}

export interface HotSearchConfig {
  status: number; // 0 关 1 开
  data: HotSearchItem[];
}

export function getHotSearchConfig() {
  return axios.get<HotSearchConfig>('/adminapi/setting/hot-search/config');
}

export function saveHotSearchConfig(data: HotSearchConfig) {
  return axios.post('/adminapi/setting/hot-search/save', data);
}

// ─── 交易设置 ────────────────────────────────────────────────────────────────
export interface TransactionConfig {
  cancel_unpaid_orders: number;
  cancel_unpaid_orders_times: number;
  verification_orders: number;
  verification_orders_times: number;
}

export function getTransactionConfig() {
  return axios.get<TransactionConfig>('/adminapi/setting/transaction/config');
}

export function saveTransactionConfig(data: TransactionConfig) {
  return axios.post('/adminapi/setting/transaction/save', data);
}
