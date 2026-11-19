import { describe, expect, it, vi } from 'vitest';
import { createFileAssetRuntime } from '../src/runtime';

const body = {
  data: {
    items: [
      {
        id: 7,
        file_key: 'file_0123456789abcdef0123456789abcdef',
        original_name: 'photo.png',
        media_type: 'image/png',
        width: 640,
        height: 480,
        preview_uri: '/api/storage/delivery?token=signed',
        variants: [],
      },
    ],
  },
  meta: { request_id: 'req', page: 1, page_size: 24, total: 1 },
};

describe('official.file asset runtime', () => {
  it('loads canonical assets and selects by file_key', async () => {
    const request = vi.fn(
      async () =>
        new Response(JSON.stringify(body), {
          status: 200,
          headers: { 'Content-Type': 'application/json' },
        })
    );
    const runtime = createFileAssetRuntime({ canRead: () => true, request });
    await runtime.load();
    expect(runtime.state.items[0]?.fileKey).toBe(body.data.items[0].file_key);
    runtime.select(runtime.state.items[0]!);
    expect(runtime.state.selectedFileKey).toBe(body.data.items[0].file_key);
  });

  it('fails closed before transport without read permission', async () => {
    const request = vi.fn();
    const runtime = createFileAssetRuntime({ canRead: () => false, request });
    await runtime.load();
    expect(runtime.state.error?.status).toBe(403);
    expect(request).not.toHaveBeenCalled();
  });
});
