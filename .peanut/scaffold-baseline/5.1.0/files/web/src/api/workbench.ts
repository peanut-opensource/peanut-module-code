import axios from 'axios';

export interface VersionInfo {
  version: string;
  website: string;
  name: string;
  based: string;
  channel: {
    website: string;
    github: string;
  };
}

export interface TodayMetrics {
  time: string;
  today_sales: number;
  total_sales: number;
  today_visitor: number;
  total_visitor: number;
  today_new_user: number;
  total_new_user: number;
  order_num: number;
  order_sum: number;
}

export interface Shortcut {
  name: string;
  image: string;
  url: string;
}

export interface TrendSeries {
  date: string[];
  list: Array<{
    name: string;
    data: number[];
  }>;
}

export interface SupportItem {
  image: string;
  title: string;
  desc: string;
}

export interface WorkbenchData {
  statistics_state: 'demo' | 'unavailable';
  version: VersionInfo;
  today: TodayMetrics;
  menu: Shortcut[];
  visitor: TrendSeries;
  support: SupportItem[];
  sale: TrendSeries;
}

export function getWorkbench() {
  return axios.get<WorkbenchData>('/adminapi/workbench/index');
}
