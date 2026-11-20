// @vitest-environment happy-dom
import { afterEach, describe, expect, it, vi } from 'vitest';
import { tenantSessionFetch } from '@/api/tenant-session-fetch';
import type { FileResourceContent } from '@/modules/peanut-content-tools/src';
import { createFileContentCallbacks } from '../src/contentCallbacks';

vi.mock('@/api/tenant-session-fetch', () => ({ tenantSessionFetch: vi.fn() }));

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
  return vi
    .mocked(tenantSessionFetch)
    .mockResolvedValue(new Response(JSON.stringify({ code: 20000, data })));
};
const callbacks = (pagePreviewReceipt?: unknown) =>
  createFileContentCallbacks({
    variantKey: 'small',
    viewerAdapterId: 'builtin.image',
    canDownloadOriginal: () => false,
    pagePreviewReceipt,
  });
afterEach(() => {
  vi.unstubAllGlobals();
  vi.resetAllMocks();
});

describe('File preview selection receipt', () => {
  it('accepts an actual PNG derivative of a JPEG source and sends the fixed variant', async () => {
    const fetch = respond(receipt());
    const preview = await callbacks().resolvePreview!(
      content,
      new AbortController().signal
    );
    expect(preview.mimeType).toBe('image/png');
    expect(preview.bytes).toBe('derivative');
    expect(
      (await (fetch.mock.calls[0]![0] as Request).json()).variant_key
    ).toBe('small');
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

  it('consumes the issued page preview only once and uses live resolution on retry', async () => {
    const issued = receipt();
    const fetch = respond({
      ...issued,
      url: '/api/storage/delivery?token=fresh',
    });
    const resolver = callbacks(issued).resolvePreview!;
    const { signal } = new AbortController();
    expect((await resolver(content, signal)).url).toContain('token=synthetic');
    expect(fetch).not.toHaveBeenCalled();
    expect((await resolver(content, signal)).url).toContain('token=fresh');
    expect(fetch).toHaveBeenCalledTimes(1);
  });

  it.each([
    ['expired', { expires_at: 1 }],
    ['different file', { file_key: `file_${'d'.repeat(32)}` }],
    ['original bytes', { bytes: 'original' }],
    ['cross-origin URL', { url: 'https://other.invalid/api/storage/delivery' }],
  ])('replaces a %s page receipt only with fresh access', async (_, change) => {
    const fetch = respond({
      ...receipt(),
      url: '/api/storage/delivery?token=fresh',
    });
    const initial = { ...receipt(), ...change };
    const preview = await callbacks(initial).resolvePreview!(
      content,
      new AbortController().signal
    );
    expect(preview.url).toContain('token=fresh');
    expect(fetch).toHaveBeenCalledTimes(1);
  });

  it('requires live resolution for document previews and preserves the document binding', async () => {
    const fetch = respond({
      ...receipt(),
      url: '/api/storage/delivery?token=fresh',
    });
    const resolver = createFileContentCallbacks({
      documentId: 42,
      viewerAdapterId: 'builtin.image',
      canDownloadOriginal: () => false,
      pagePreviewReceipt: receipt(),
    }).resolvePreview!;
    const preview = await resolver(content, new AbortController().signal);
    expect(preview.url).toContain('token=fresh');
    expect(
      (await (fetch.mock.calls[0]![0] as Request).json()).document_id
    ).toBe(42);
  });
});
