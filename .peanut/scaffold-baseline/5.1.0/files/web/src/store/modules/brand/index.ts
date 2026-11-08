import { defineStore } from 'pinia';
import defaultBrand from '@/generated/brand.json';
import {
  getPublicBrandConfig,
  type DemoLoginConfig,
  type WebsiteConfig,
} from '@/api/system/config';

const IMAGE_FIELDS: (keyof WebsiteConfig)[] = [
  'web_favicon',
  'web_logo',
  'login_image',
  'shop_logo',
  'pc_logo',
  'pc_ico',
  'h5_favicon',
];

function normalizeWebsite(website: WebsiteConfig): WebsiteConfig {
  const normalized = { ...defaultBrand.website, ...website } as WebsiteConfig;
  const baseUrl = import.meta.env.BASE_URL.replace(/\/$/, '');
  IMAGE_FIELDS.forEach((field) => {
    const value = website[field];
    const fallbackValue = defaultBrand.website[field];
    const resolvedValue =
      typeof value === 'string' && value.trim() ? value : fallbackValue;
    if (
      resolvedValue &&
      !resolvedValue.startsWith('/') &&
      !resolvedValue.startsWith('http')
    ) {
      normalized[field] = `${baseUrl}/${resolvedValue}`;
    } else {
      normalized[field] = resolvedValue;
    }
  });
  return normalized;
}

const fallback = normalizeWebsite(defaultBrand.website as WebsiteConfig);

function applyDocumentBrand(website: WebsiteConfig, tenantName: string) {
  document.title = tenantName
    ? `${tenantName} - ${website.name}`
    : website.name;
  let favicon = document.querySelector<HTMLLinkElement>('link[rel="icon"]');
  if (!favicon) {
    favicon = document.createElement('link');
    favicon.rel = 'icon';
    document.head.appendChild(favicon);
  }
  favicon.href = website.web_favicon;
}

const useBrandStore = defineStore('brand', {
  state: () => ({
    website: { ...fallback } as WebsiteConfig,
    entryWebsite: { ...fallback } as WebsiteConfig,
    entryTenantName: '',
    tenantName: '',
    demo: { enabled: false, email: '', password: '' } as DemoLoginConfig,
    loaded: false,
  }),
  actions: {
    replace(website: WebsiteConfig) {
      this.website = normalizeWebsite(website);
      this.applyTitle();
    },
    applyTitle() {
      applyDocumentBrand(this.website, this.tenantName || this.entryTenantName);
    },
    setEntryTenantName(tenantName?: string) {
      this.entryTenantName = tenantName?.trim() || '';
      this.applyTitle();
    },
    setTenantName(tenantName?: string) {
      this.tenantName = tenantName?.trim() || '';
      if (!this.tenantName) this.website = { ...this.entryWebsite };
      this.applyTitle();
    },
    async load() {
      if (this.loaded) return;
      try {
        const { data } = await getPublicBrandConfig();
        this.demo = data.demo || { enabled: false, email: '', password: '' };
        this.entryWebsite = normalizeWebsite(data.website);
        this.setEntryTenantName(data.tenantName);
        if (!this.tenantName) this.replace(this.entryWebsite);
      } catch {
        if (!this.tenantName) this.replace(this.entryWebsite);
      } finally {
        this.loaded = true;
      }
    },
  },
});

export default useBrandStore;
