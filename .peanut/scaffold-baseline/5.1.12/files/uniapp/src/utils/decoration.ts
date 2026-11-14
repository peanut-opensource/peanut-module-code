import { http } from '@/utils/request';
import { pageAvailable } from '@/module-contributions';

export type DecorationLinkType = 'shop' | 'article' | 'custom' | 'mini_program';

export interface DecorationLink {
  target_type: DecorationLinkType;
  target: string | number;
  query?: {
    app_id?: string;
    env_version?: 'develop' | 'trial' | 'release';
    web_url?: string;
    [key: string]: unknown;
  };
}

export interface DecorationItem {
  image: string;
  name: string;
  link: DecorationLink;
  is_show?: 0 | 1;
  [key: string]: unknown;
}

export interface DecorationComponent {
  title: string;
  name: string;
  disabled?: 0 | 1;
  content: Record<string, unknown>;
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

export interface DecorationTheme {
  themeColorId: number;
  topTextColor: 'white' | 'black';
  navigationBarColor: string;
  themeColor1: string;
  themeColor2: string;
  buttonColor: 'white' | 'black';
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

export function getMobileDecoration(type: 1 | 2 | 3): Promise<DecorationPage> {
  return http.get<DecorationPage>('api/decoration/mobile', { type }, false);
}

export function getDecorationComponents(
  page: DecorationPage | null | undefined
): DecorationComponent[] {
  return page && Array.isArray(page.data) ? page.data : [];
}

export function getDecorationComponent(
  page: DecorationPage | null | undefined,
  name: string
): DecorationComponent | undefined {
  return getDecorationComponents(page).find((item) => item.name === name);
}

export function getDecorationItems(
  component: DecorationComponent | undefined
): DecorationItem[] {
  const data = component?.content?.data;
  return Array.isArray(data) ? (data as DecorationItem[]) : [];
}

export function getDecorationMeta(
  page: DecorationPage | null | undefined
): Record<string, unknown> {
  const meta =
    page && Array.isArray(page.meta)
      ? page.meta.find((item) => item.name === 'page-meta')
      : undefined;
  return meta?.content || {};
}

export function isDecorationComponentEnabled(
  component: DecorationComponent
): boolean {
  if (component.disabled === 1) return true;
  return Number(component.content?.enabled ?? 1) === 1;
}

export function applyDecorationPageMeta(
  page: DecorationPage | null | undefined
) {
  const meta = getDecorationMeta(page);
  const title = typeof meta.title === 'string' ? meta.title.trim() : '';
  if (title) uni.setNavigationBarTitle({ title });

  const backgroundColor =
    typeof meta.bg_color === 'string' ? meta.bg_color : '';
  if (/^#[0-9a-f]{6}$/i.test(backgroundColor)) {
    uni.setNavigationBarColor({
      frontColor: Number(meta.text_color) === 2 ? '#000000' : '#ffffff',
      backgroundColor,
    });
  }
}

export function getDecorationTheme(value: unknown): DecorationTheme | null {
  if (!value || typeof value !== 'object') return null;
  const wrapper = value as { data?: unknown };
  const source =
    wrapper.data &&
    typeof wrapper.data === 'object' &&
    !Array.isArray(wrapper.data)
      ? wrapper.data
      : value;
  if (!source || typeof source !== 'object') return null;
  const theme = source as Partial<DecorationTheme>;
  if (
    typeof theme.themeColor1 !== 'string' ||
    typeof theme.navigationBarColor !== 'string'
  )
    return null;
  return {
    themeColorId: Number(theme.themeColorId || 7),
    topTextColor: theme.topTextColor === 'black' ? 'black' : 'white',
    navigationBarColor: theme.navigationBarColor,
    themeColor1: theme.themeColor1,
    themeColor2:
      typeof theme.themeColor2 === 'string'
        ? theme.themeColor2
        : theme.themeColor1,
    buttonColor: theme.buttonColor === 'black' ? 'black' : 'white',
  };
}

export function applyDecorationTheme(value: unknown) {
  const theme = getDecorationTheme(value);
  // App launch may load configuration before the first page exists. Page-level
  // loadConfig calls apply the cached theme once their navigation bar is ready.
  if (!theme || getCurrentPages().length === 0) return;
  const frontColor = theme.topTextColor === 'black' ? '#000000' : '#ffffff';
  try {
    uni.setNavigationBarColor({
      frontColor,
      backgroundColor: theme.navigationBarColor,
      // A page can disappear after the check. Handle the native async callback;
      // synchronous try/catch alone does not observe a rejected uni Promise.
      fail: (error) => console.warn('Unable to apply navigation theme', error),
    });
  } catch (error) {
    console.warn('Unable to apply navigation theme', error);
  }
}

export function executeDecorationLink(link: DecorationLink | null | undefined) {
  if (!link) return;
  const target = String(link.target ?? '');
  const routes: Record<string, string> = {
    home: '/pages/index/index',
    news: '/pages/news/news',
    profile: '/pages/user/user',
    settings: '/pages/user_set/user_set',
    favorites: '/pages/collection/collection',
    customer_service: '/pages/customer_service/customer_service',
    wallet: '/packages/pages/user_wallet/user_wallet',
    privacy: '/pages/agreement/agreement?type=privacy',
    service: '/pages/agreement/agreement?type=service',
  };
  if (link.target_type === 'shop') {
    const url = routes[target];
    if (url && !pageAvailable(url)) {
      uni.showToast({ title: '此功能尚未安装', icon: 'none' });
      return;
    }
    if (url && ['home', 'news', 'profile'].includes(target))
      uni.reLaunch({ url });
    else if (url) uni.navigateTo({ url });
    return;
  }
  if (link.target_type === 'article') {
    if (!pageAvailable('/pages/news_detail/news_detail')) {
      uni.showToast({ title: '资讯功能尚未安装', icon: 'none' });
      return;
    }
    const id = encodeURIComponent(target);
    uni.navigateTo({ url: `/pages/news_detail/news_detail?id=${id}` });
    return;
  }
  if (link.target_type === 'custom') {
    if (!/^https?:\/\//i.test(target)) return;
    // H5 has a browser-native external navigation; native builds delegate to
    // the platform opener without treating the URL as an app route.
    // #ifdef H5
    window.location.assign(target);
    // #endif
    // #ifndef H5
    const runtime = (
      globalThis as { plus?: { runtime?: { openURL: (url: string) => void } } }
    ).plus;
    runtime?.runtime?.openURL(target);
    // #endif
    return;
  }
  if (link.target_type === 'mini_program') {
    const appId = String(link.query?.app_id || '');
    if (!target || !appId) return;
    uni.navigateToMiniProgram({
      appId,
      path: target,
      envVersion: link.query?.env_version || 'release',
    });
  }
}
