import { describe, expect, it, vi } from 'vitest';

import { createNotificationRuntime } from '../src/runtime';
import type {
  NotificationTransport,
  NotificationTransportResult,
} from '../src/contracts';

const ok = (body: unknown): NotificationTransportResult => ({
  body,
  headers: new Headers(),
  status: 200,
});
const item = {
  message_key: 'notice_0123456789abcdef0123456789abcdef',
  template_key: 'security.alert',
  template_revision: 2,
  subject: 'Alert',
  body: 'Review this event.',
  status: 'unread',
  revision: 1,
  created_at: '2026-07-24T10:00:00.000Z',
  read_at: null,
  archived_at: null,
  attachments: [],
};
const list = () =>
  ok({
    data: { items: [item] },
    meta: { request_id: 'req_list', page: 1, page_size: 20, total: 1 },
  });

describe('notification runtime', () => {
  it('loads, marks read, and performs bounded bulk actions without Tenant input', async () => {
    const transport: NotificationTransport = {
      list: vi.fn(async () => list()),
      markRead: vi.fn(async () =>
        ok({
          data: {
            ...item,
            status: 'read',
            revision: 2,
            read_at: '2026-07-24T10:01:00.000Z',
          },
          meta: { request_id: 'req_read' },
        })
      ),
      bulk: vi.fn(async () =>
        ok({ data: { changed: 1 }, meta: { request_id: 'req_bulk' } })
      ),
    };
    const runtime = createNotificationRuntime({
      transport,
      canRead: () => true,
    });
    await runtime.load();
    expect(runtime.state.items).toHaveLength(1);
    await runtime.markRead(runtime.state.items[0]!);
    expect(transport.markRead).toHaveBeenCalledWith(
      item.message_key,
      1,
      expect.any(AbortSignal)
    );
    runtime.toggle(item.message_key);
    await runtime.bulk('archive');
    expect(transport.bulk).toHaveBeenCalledWith(
      [item.message_key],
      'archive',
      expect.any(AbortSignal)
    );
  });

  it('fails closed on permission and invalid response without retaining stale selection', async () => {
    const deniedTransport = { list: vi.fn(), markRead: vi.fn(), bulk: vi.fn() };
    const denied = createNotificationRuntime({
      transport: deniedTransport,
      canRead: () => false,
    });
    denied.state.selected.add(item.message_key);
    await denied.load();
    expect(denied.state.error).toMatchObject({
      status: 403,
      messageKey: 'notificationInbox.forbidden',
    });
    expect(deniedTransport.list).not.toHaveBeenCalled();
    expect(deniedTransport.markRead).not.toHaveBeenCalled();
    expect(deniedTransport.bulk).not.toHaveBeenCalled();
    expect(denied.state.selected.size).toBe(0);

    const invalid = createNotificationRuntime({
      transport: {
        list: vi.fn(async () =>
          ok({
            data: { items: [{ ...item, tenant_id: 9 }] },
            meta: { request_id: 'x', page: 1, page_size: 20, total: 1 },
          })
        ),
        markRead: vi.fn(),
        bulk: vi.fn(),
      },
      canRead: () => true,
    });
    invalid.toggle(item.message_key);
    await invalid.load();
    expect(invalid.state.error?.message).toContain('could not be reached');
    invalid.dispose();
    expect(invalid.state.selected.size).toBe(0);
  });

  it('cannot rehydrate disposed tenant state from a delayed mutation', async () => {
    let resolveMutation:
      | ((result: NotificationTransportResult) => void)
      | undefined;
    const delayed = new Promise<NotificationTransportResult>((resolve) => {
      resolveMutation = resolve;
    });
    const transport: NotificationTransport = {
      list: vi.fn(async () => list()),
      markRead: vi.fn(async () => delayed),
      bulk: vi.fn(),
    };
    const runtime = createNotificationRuntime({
      transport,
      canRead: () => true,
    });
    await runtime.load();
    const mutation = runtime.markRead(runtime.state.items[0]!);
    runtime.dispose();
    resolveMutation?.(
      ok({
        data: {
          ...item,
          status: 'read',
          revision: 2,
          read_at: '2026-07-24T10:01:00.000Z',
        },
        meta: { request_id: 'req_delayed' },
      })
    );
    expect(await mutation).toBe(false);

    expect(transport.list).toHaveBeenCalledTimes(1);
    expect(runtime.state.items).toEqual([]);
    expect(runtime.state).toMatchObject({
      page: 1,
      pageSize: 10,
      total: 0,
      loading: false,
      mutating: false,
      error: null,
    });
    expect(runtime.state.selected.size).toBe(0);
  });
});
