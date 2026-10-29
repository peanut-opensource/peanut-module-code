import { describe, expect, it, vi } from 'vitest';

import {
  createNotificationFetchTransport,
  parseBulkResult,
  parseNotificationList,
  parseNotificationResponse,
} from '../src/contracts';

const message = (
  overrides: Record<string, unknown> = {}
): Record<string, unknown> => ({
  message_key: 'notice_0123456789abcdef0123456789abcdef',
  template_key: 'security.alert',
  template_revision: 2,
  subject: 'Security alert',
  body: 'A sign-in was detected.',
  status: 'unread',
  revision: 1,
  created_at: '2026-07-24T10:00:00.000Z',
  read_at: null,
  archived_at: null,
  attachments: [
    {
      file_key: 'file_0123456789abcdef0123456789abcdef',
      original_name: 'report.pdf',
      media_type: 'application/pdf',
      size_bytes: 42,
      sha256: 'a'.repeat(64),
    },
  ],
  ...overrides,
});

const envelope = (data: unknown): Record<string, unknown> => ({
  data,
  meta: { request_id: 'req_notification' },
});

describe('notification response contracts', () => {
  it('parses exact list, detail, attachment, and bulk shapes', () => {
    expect(parseNotificationResponse(envelope(message()))).toMatchObject({
      messageKey: 'notice_0123456789abcdef0123456789abcdef',
      status: 'unread',
      revision: 1,
    });
    expect(
      parseNotificationList({
        data: { items: [message()] },
        meta: { request_id: 'req_list', page: 1, page_size: 20, total: 1 },
      })
    ).toMatchObject({ page: 1, pageSize: 20, total: 1 });
    expect(parseBulkResult(envelope({ changed: 2 }))).toBe(2);
  });

  it('fails closed on tenant leakage, state/timestamp mismatch, and attachment metadata drift', () => {
    expect(() =>
      parseNotificationResponse(envelope({ ...message(), tenant_id: 7 }))
    ).toThrow('NOTIFICATION_RESPONSE_INVALID');
    expect(() =>
      parseNotificationResponse(
        envelope(message({ status: 'read', read_at: null }))
      )
    ).toThrow('NOTIFICATION_RESPONSE_INVALID');
    expect(() =>
      parseNotificationResponse(
        envelope(
          message({
            attachments: [
              {
                file_key: 'file_0123456789abcdef0123456789abcdef',
                original_name: 'x',
                media_type: 'text/plain',
                size_bytes: 1,
                sha256: 'a'.repeat(64),
                storage_key: 'forbidden',
              },
            ],
          })
        )
      )
    ).toThrow('NOTIFICATION_RESPONSE_INVALID');
    expect(() =>
      parseNotificationList({
        data: { items: [] },
        meta: { page: 1, page_size: 20, total: 0 },
      })
    ).toThrow('NOTIFICATION_RESPONSE_INVALID');
  });
});

describe('notification fetch transport', () => {
  it('never accepts Tenant input and sends bounded bulk and revision requests', async () => {
    const fetcher = vi.fn<(request: Request) => Promise<Response>>(
      async () =>
        new Response('{}', {
          status: 200,
          headers: { 'Content-Type': 'application/json' },
        })
    );
    const transport = createNotificationFetchTransport({
      baseUrl: 'https://admin.example.test/root/',
      fetch: fetcher,
    });
    const signal = new AbortController().signal;
    await transport.list('unread', 2, 25, signal);
    await transport.markRead(
      'notice_0123456789abcdef0123456789abcdef',
      3,
      signal
    );
    await transport.bulk(
      ['notice_0123456789abcdef0123456789abcdef'],
      'archive',
      signal
    );

    const [list, read, bulk] = fetcher.mock.calls.map((call) => call[0]);
    expect(new URL(list?.url ?? '').searchParams.toString()).toBe(
      'status=unread&page=2&page_size=25'
    );
    expect(read?.headers.get('If-Match')).toBe('"rev-3"');
    expect(await read?.json()).toEqual({});
    expect(await bulk?.json()).toEqual({
      message_keys: ['notice_0123456789abcdef0123456789abcdef'],
      action: 'archive',
    });
    expect(
      fetcher.mock.calls.map((call) => call[0].url).join(' ')
    ).not.toContain('tenant');
  });
});
