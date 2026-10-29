import axios from 'axios';
import readDecorationPage from './decoration-model';

/** The business link value object shared by all decoration surfaces. */
export type DecorationLinkType = 'shop' | 'article' | 'custom' | 'mini_program';

export interface DecorationLink {
  target_type: DecorationLinkType;
  target: string;
  query?: {
    app_id?: string;
    env_version?: 'develop' | 'trial' | 'release';
    web_url?: string;
    [key: string]: unknown;
  };
}

export interface DecorationArticleOption {
  id: number;
  title: string;
  image: string;
  abstract?: string;
}

export interface DecorationItem {
  image: string;
  name: string;
  link: DecorationLink;
  is_show?: 0 | 1;
  bg?: string;
  [key: string]: unknown;
}

export interface DecorationContent extends Record<string, unknown> {
  title?: string;
  title_img?: string;
  bg_color?: string;
  bg_image?: string;
  title_type?: number;
  bg_type?: number;
  text_color?: number;
  time?: string;
  mobile?: string;
  qrcode?: string;
  remark?: string;
  style?: number;
  bg_style?: number;
  per_line?: number;
  show_line?: number;
  enabled?: number;
  data?: DecorationItem[];
}

export interface DecorationComponent {
  title: string;
  name: string;
  disabled?: 0 | 1;
  content: DecorationContent;
  styles: Record<string, string | number>;
}

export interface DecorationPage {
  id: number;
  type: number;
  name: string;
  data: DecorationComponent[] | Record<string, unknown>;
  meta: DecorationComponent[] | Record<string, unknown>;
  update_time?: number | string;
}

export interface DecorationPageSummary {
  id: number;
  type: number;
  name: string;
  update_time?: number | string;
}

export interface DecorationSavePayload {
  id: number;
  type: number;
  data: DecorationComponent[] | Record<string, unknown>;
  meta?: DecorationComponent[] | Record<string, unknown>;
}

export interface DecorationTabbarItem {
  id?: number;
  position?: number;
  name: string;
  selected: string;
  unselected: string;
  link: DecorationLink;
  is_show: 0 | 1;
}

export interface DecorationTabbar {
  style: {
    default_color: string;
    selected_color: string;
    [key: string]: unknown;
  };
  list: DecorationTabbarItem[];
}

export function getMobileDecorationLists() {
  return axios.get<DecorationPageSummary[]>(
    '/adminapi/decoration/mobile/page/lists'
  );
}

export async function getMobileDecorationDetail(id: number) {
  const response = await axios.get<unknown>(
    '/adminapi/decoration/mobile/page/detail',
    {
      params: { id },
    }
  );
  return { ...response, data: readDecorationPage(response.data) };
}

export function saveMobileDecoration(data: DecorationSavePayload) {
  return axios.post('/adminapi/decoration/mobile/page/save', data);
}

export function getDecorationArticleOptions(limit = 50) {
  return axios.get<DecorationArticleOption[]>(
    '/adminapi/decoration/mobile/article',
    { params: { limit } }
  );
}

export function getDecorationTabbar() {
  return axios.get<DecorationTabbar>('/adminapi/decoration/tabbar/detail');
}

export function saveDecorationTabbar(data: DecorationTabbar) {
  return axios.post('/adminapi/decoration/tabbar/save', data);
}

export function getPcDecorationLists() {
  return axios.get<DecorationPageSummary[]>(
    '/adminapi/decoration/pc/page/lists'
  );
}

export async function getPcDecorationDetail(id: number) {
  const response = await axios.get<unknown>(
    '/adminapi/decoration/pc/page/detail',
    {
      params: { id },
    }
  );
  return { ...response, data: readDecorationPage(response.data) };
}

export function savePcDecoration(data: DecorationSavePayload) {
  return axios.post('/adminapi/decoration/pc/page/save', data);
}
