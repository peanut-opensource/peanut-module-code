import { http } from '@/utils/request';
import type {
  DecorationPage,
  DecorationTabbar,
  DecorationTheme,
} from '@/utils/decoration';

export interface WebsiteConfig {
  name: string;
  web_favicon: string;
  web_logo: string;
  login_image: string;
  shop_name: string;
  shop_logo: string;
  pc_logo: string;
  pc_title: string;
  pc_ico: string;
  pc_desc: string;
  pc_keywords: string;
  h5_favicon: string;
  slogan: string;
  copyright: string;
  official_url: string;
  github_url: string;
}

export interface ConfigData {
  domain: string;
  website: WebsiteConfig;
  login: { login_way: number[] };
  web_page: {
    status: 0 | 1;
    page_status: 0 | 1;
    page_url: string;
  };
  tabbar: DecorationTabbar;
  theme: DecorationPage & { data: DecorationTheme };
  version: string;
}

export interface PolicyData {
  title: string;
  content: string;
}

/** GET api/index/config — app global config */
export function getConfig() {
  return http.get<ConfigData>('api/index/config', undefined, false);
}

/** GET api/index/policy?type=privacy|service */
export function getPolicy(type: 'privacy' | 'service') {
  return http.get<PolicyData>('api/index/policy', { type }, false);
}

/** GET api/decoration/tabbar — public visible tabbar configuration */
export function getDecorationTabbar() {
  return http.get<DecorationTabbar>('api/decoration/tabbar', undefined, false);
}
