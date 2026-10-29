import brandManifest from '@/generated/brand.json';
import type { WebsiteConfig } from '@/api/index';

const defaultWebsite = brandManifest.website;

function staticAssetPath(assetPath: string): string {
  const normalized = assetPath.trim().replace(/^\/+/, '');
  return normalized && !normalized.includes('://')
    ? `/static/${normalized}`
    : '/static/brand/logo.svg';
}

export const FALLBACK_BRAND_NAME =
  defaultWebsite.shop_name.trim() || 'Peanut Module Code';
export const FALLBACK_BRAND_LOGO = staticAssetPath(defaultWebsite.shop_logo);
export const FALLBACK_BRAND_SLOGAN =
  defaultWebsite.slogan.trim() || 'Peanut Module Code';

export function resolveBrandName(
  website?: Partial<WebsiteConfig> | null
): string {
  return website?.shop_name?.trim() || FALLBACK_BRAND_NAME;
}

export function resolveBrandLogo(
  website?: Partial<WebsiteConfig> | null
): string {
  return website?.shop_logo?.trim() || FALLBACK_BRAND_LOGO;
}

export function resolveBrandSlogan(
  website?: Partial<WebsiteConfig> | null
): string {
  return website?.slogan?.trim() || FALLBACK_BRAND_SLOGAN;
}
