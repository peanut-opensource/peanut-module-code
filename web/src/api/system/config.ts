import axios from 'axios';

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
  sidebar_footer_enabled: boolean;
  sidebar_footer_title: string;
  sidebar_footer_settings: boolean;
  sidebar_footer_license: boolean;
  sidebar_footer_about: boolean;
}

export interface DemoLoginConfig {
  enabled: boolean;
  email: string;
  password: string;
}

export function getWebsiteConfig() {
  return axios.get<WebsiteConfig>('/adminapi/config/website');
}

export function saveWebsiteConfig(data: WebsiteConfig) {
  return axios.post<WebsiteConfig>('/adminapi/config/website/save', data);
}

export function getPublicBrandConfig() {
  return axios.get<{
    website: WebsiteConfig;
    tenantName: string;
    demo: DemoLoginConfig;
    login_enabled: boolean;
  }>('/adminapi/config/brand');
}
