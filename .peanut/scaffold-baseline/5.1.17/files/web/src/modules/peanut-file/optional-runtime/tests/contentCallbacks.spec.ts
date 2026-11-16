// @vitest-environment happy-dom
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { FileResourceContent } from '@/modules/official-content-tools/src';
import { createFileContentCallbacks } from '../src/contentCallbacks';

vi.mock('@/utils/auth', () => ({ getToken: () => 'synthetic-token' }));

const content: FileResourceContent = {
  kind: 'file_resource',
  identity: 'synthetic-image',
  fileKey: `file_${'a'.repeat(32)}`,
  sourceSha256: 'c'.repeat(64),
  verifiedMimeType: 'image/jpeg',
  displayName: 'source.jpg',
};
const receipt = () => ({
  state: 'available',
  file_key: content.fileKey,
  delivery_file_key: `file_${'b'.repeat(32)}`,
  source_sha256: content.sourceSha256,
  purpose: 'preview',
  media_type: 'image/png',
  bytes: 'derivative',
  variant_key: 'small',
  expires_at: Date.now() + 300_000,
  url: '/api/storage/delivery?token=synthetic',
  capabilities: {
    contentKind: 'file_resource',
    verifiedMimeType: 'image/png',
    viewerAdapterId: 'builtin.image',
    editorAdapterId: null,
    canView: true,
    canEdit: false,
    canDownloadOriginal: false,
    state: 'read_only',
    unavailableReason: null,
    capabilityRevision: 'synthetic-revision',
  },
});
const respond = (data: ReturnType<typeof receipt>) => {
  vi.stubGlobal('window', {
    location: { origin: 'https://application.invalid' },
  });
  const fetch = vi.fn().mockResolvedValue({
    ok: true,
    json: async () => ({ code: 20000, data }),
  });
  vi.stubGlobal('fetch', fetch);
  return fetch;
};
const callbacks = () =>
  createFileContentCallbacks({
    variantKey: 'small',
    viewerAdapterId: 'builtin.image',
    canDownloadOriginal: () => false,
  });
afterEach(() => vi.unstubAllGlobals());

describe('File preview selection receipt', () => {
  it('accepts an actual PNG derivative of a JPEG source and sends the fixed variant', async () => {
    const fetch = respond(receipt());
    const preview = await callbacks().resolvePreview!(
      content,
      new AbortController().signal
    );
    expect(preview.mimeType).toBe('image/png');
    expect(preview.bytes).toBe('derivative');
    expect(JSON.parse(fetch.mock.calls[0]![1].body).variant_key).toBe('small');
  });

  it.each([
    ['mismatched MIME', { media_type: 'image/jpeg' }],
    ['wrong variant', { variant_key: 'large' }],
    ['missing variant', { variant_key: undefined }],
    ['original substitution', { bytes: 'original' }],
    ['source delivery key', { delivery_file_key: content.fileKey }],
  ])(
    'rejects %s without falling back to original bytes',
    async (_, changed) => {
      respond({ ...receipt(), ...changed } as ReturnType<typeof receipt>);
      await expect(
        callbacks().resolvePreview!(content, new AbortController().signal)
      ).rejects.toThrow('FILE_VARIANT_RECEIPT_INVALID');
    }
  );

  it('uses the exact HTTP variant grammar and rejects an unauthorized MIME claim', async () => {
    expect(() =>
      createFileContentCallbacks({
        variantKey: 'UPPER',
        viewerAdapterId: 'builtin.image',
        canDownloadOriginal: () => false,
      })
    ).toThrow('FILE_VARIANT_KEY_INVALID');
    const data = receipt();
    data.capabilities.verifiedMimeType = 'text/html';
    respond(data);
    await expect(
      callbacks().resolvePreview!(content, new AbortController().signal)
    ).rejects.toThrow('FILE_VARIANT_RECEIPT_INVALID');
  });
});
