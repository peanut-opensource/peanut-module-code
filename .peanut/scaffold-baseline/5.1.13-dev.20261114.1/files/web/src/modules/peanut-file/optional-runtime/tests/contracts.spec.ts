import { describe, expect, it } from 'vitest';
import { parseAssetCandidate, parseAssetList } from '../src/contracts';

const asset = {
  id: 7,
  file_key: 'file_0123456789abcdef0123456789abcdef',
  original_name: 'photo.png',
  media_type: 'image/png',
  width: 640,
  height: 480,
  preview_uri: '/api/storage/delivery?token=signed',
  variants: [
    {
      variant_key: 'thumb',
      file_key: 'file_fedcba9876543210fedcba9876543210',
      width: 160,
      height: 120,
      media_type: 'image/jpeg',
      delivery_uri: 'https://cdn.example.test/thumb?sig=x',
    },
  ],
};

describe('official.file asset contracts', () => {
  it('parses canonical file keys, nullable legacy dimensions and derivatives', () => {
    expect(parseAssetCandidate(asset).variants[0]?.variantKey).toBe('thumb');
    expect(
      parseAssetCandidate({ ...asset, width: null, height: null }).width
    ).toBeNull();
    expect(
      parseAssetList({
        data: { items: [asset] },
        meta: { request_id: 'req_1', page: 1, page_size: 20, total: 1 },
      }).total
    ).toBe(1);
  });

  it('rejects internal fields, half-present dimensions and duplicate variants', () => {
    expect(() =>
      parseAssetCandidate({ ...asset, object_key: 'private/path' })
    ).toThrow('FILE_ASSET_RESPONSE_INVALID');
    expect(() => parseAssetCandidate({ ...asset, width: null })).toThrow(
      'FILE_ASSET_RESPONSE_INVALID'
    );
    expect(() =>
      parseAssetCandidate({
        ...asset,
        variants: [...asset.variants, asset.variants[0]],
      })
    ).toThrow('FILE_ASSET_RESPONSE_INVALID');
  });
});
