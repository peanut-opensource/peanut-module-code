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
