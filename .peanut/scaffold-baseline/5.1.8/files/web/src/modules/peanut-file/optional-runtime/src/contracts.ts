export interface ImageVariant {
  readonly variantKey: string;
  readonly fileKey: string;
  readonly width: number;
  readonly height: number;
  readonly mediaType: 'image/jpeg' | 'image/png';
  readonly deliveryUri: string | null;
}

export interface AssetCandidate {
  readonly materialId: number;
  readonly fileKey: string;
  readonly originalName: string;
  readonly mediaType: string;
  readonly width: number | null;
  readonly height: number | null;
  readonly previewUri: string | null;
  readonly variants: readonly ImageVariant[];
}

export interface AssetList {
  readonly items: readonly AssetCandidate[];
  readonly page: number;
  readonly pageSize: number;
  readonly total: number;
}

const fileKeyPattern = /^file_[0-9a-f]{32}$/;

const record = (value: unknown): Record<string, unknown> => {
  if (typeof value !== 'object' || value === null || Array.isArray(value))
    throw new Error('FILE_ASSET_RESPONSE_INVALID');
  return value as Record<string, unknown>;
};

const exactKeys = (
  value: Record<string, unknown>,
  keys: readonly string[]
): void => {
  const actual = Object.keys(value).sort();
  const expected = [...keys].sort();
  if (
    actual.length !== expected.length ||
    actual.some((key, index) => key !== expected[index])
  ) {
    throw new Error('FILE_ASSET_RESPONSE_INVALID');
  }
};

const deliveryUri = (value: unknown): string | null => {
  if (value === null || value === '') return null;
  if (typeof value !== 'string' || value.length > 2048 || value.includes('#'))
    throw new Error('FILE_ASSET_RESPONSE_INVALID');
  if (value.startsWith('/')) {
    if (value.startsWith('//')) throw new Error('FILE_ASSET_RESPONSE_INVALID');
    return value;
  }
  let parsed: URL;
  try {
    parsed = new URL(value);
  } catch {
    throw new Error('FILE_ASSET_RESPONSE_INVALID');
  }
  if (
    !['http:', 'https:'].includes(parsed.protocol) ||
    parsed.username !== '' ||
    parsed.password !== ''
  ) {
    throw new Error('FILE_ASSET_RESPONSE_INVALID');
  }
  return value;
};

const nullableDimension = (value: unknown): number | null => {
  if (value === null) return null;
  if (
    typeof value !== 'number' ||
    !Number.isSafeInteger(value) ||
    value < 1 ||
    value > 50000
  ) {
    throw new Error('FILE_ASSET_RESPONSE_INVALID');
  }
  return value;
};

export const parseAssetCandidate = (value: unknown): AssetCandidate => {
  const item = record(value);
  exactKeys(item, [
    'id',
    'file_key',
    'original_name',
    'media_type',
    'width',
    'height',
    'preview_uri',
    'variants',
  ]);
  if (
    typeof item.id !== 'number' ||
    !Number.isSafeInteger(item.id) ||
    item.id < 1 ||
    typeof item.file_key !== 'string' ||
    !fileKeyPattern.test(item.file_key) ||
    typeof item.original_name !== 'string' ||
    item.original_name === '' ||
    [...item.original_name].length > 255 ||
    typeof item.media_type !== 'string' ||
    item.media_type === '' ||
    item.media_type.length > 127 ||
    !Array.isArray(item.variants) ||
    item.variants.length > 16
  )
    throw new Error('FILE_ASSET_RESPONSE_INVALID');
  const width = nullableDimension(item.width);
  const height = nullableDimension(item.height);
  if ((width === null) !== (height === null))
    throw new Error('FILE_ASSET_RESPONSE_INVALID');
  const keys = new Set<string>();
  const variants = item.variants.map((variantValue): ImageVariant => {
    const variant = record(variantValue);
    exactKeys(variant, [
      'variant_key',
      'file_key',
      'width',
      'height',
      'media_type',
      'delivery_uri',
    ]);
    if (
      typeof variant.variant_key !== 'string' ||
      !/^[a-z][a-z0-9-]{0,31}$/.test(variant.variant_key) ||
      keys.has(variant.variant_key) ||
      typeof variant.file_key !== 'string' ||
      !fileKeyPattern.test(variant.file_key) ||
      typeof variant.width !== 'number' ||
      !Number.isSafeInteger(variant.width) ||
      variant.width < 1 ||
      variant.width > 4096 ||
      typeof variant.height !== 'number' ||
      !Number.isSafeInteger(variant.height) ||
      variant.height < 1 ||
      variant.height > 4096 ||
      (variant.media_type !== 'image/jpeg' &&
        variant.media_type !== 'image/png')
    )
      throw new Error('FILE_ASSET_RESPONSE_INVALID');
    keys.add(variant.variant_key);
    return {
      variantKey: variant.variant_key,
      fileKey: variant.file_key,
      width: variant.width,
      height: variant.height,
      mediaType: variant.media_type,
      deliveryUri: deliveryUri(variant.delivery_uri),
    };
  });
  return {
    materialId: item.id,
    fileKey: item.file_key,
    originalName: item.original_name,
    mediaType: item.media_type,
    width,
    height,
    previewUri: deliveryUri(item.preview_uri),
    variants,
  };
};

export const parseAssetList = (value: unknown): AssetList => {
  const body = record(value);
  exactKeys(body, ['data', 'meta']);
  const data = record(body.data);
  const meta = record(body.meta);
  exactKeys(data, ['items']);
  exactKeys(meta, ['request_id', 'page', 'page_size', 'total']);
  if (
    !Array.isArray(data.items) ||
    typeof meta.request_id !== 'string' ||
    meta.request_id === ''
  ) {
    throw new Error('FILE_ASSET_RESPONSE_INVALID');
  }
  (['page', 'page_size', 'total'] as const).forEach((key) => {
    const number = meta[key];
    if (
      typeof number !== 'number' ||
      !Number.isSafeInteger(number) ||
      number < (key === 'total' ? 0 : 1)
    ) {
      throw new Error('FILE_ASSET_RESPONSE_INVALID');
    }
  });
  return {
    items: data.items.map(parseAssetCandidate),
    page: meta.page as number,
    pageSize: meta.page_size as number,
    total: meta.total as number,
  };
};
