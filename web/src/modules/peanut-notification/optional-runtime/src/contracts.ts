import { createModuleClient } from '../../generated/openapi';

export type NotificationStatus = 'unread' | 'read' | 'archived';
export type NotificationFilter = NotificationStatus | 'all';
export type NotificationBulkAction = 'read' | 'archive';

export interface NotificationAttachment {
  readonly fileKey: string;
  readonly originalName: string;
  readonly mediaType: string;
  readonly sizeBytes: number;
  readonly sha256: string;
}

export interface NotificationMessage {
  readonly messageKey: string;
  readonly templateKey: string;
  readonly templateRevision: number;
  readonly subject: string;
  readonly body: string;
  readonly status: NotificationStatus;
  readonly revision: number;
  readonly createdAt: string;
  readonly readAt: string | null;
  readonly archivedAt: string | null;
  readonly attachments: readonly NotificationAttachment[];
}

export interface NotificationList {
  readonly items: readonly NotificationMessage[];
  readonly page: number;
  readonly pageSize: number;
  readonly total: number;
}

export interface NotificationTransportResult {
  readonly body: unknown;
  readonly headers: Headers;
  readonly status: number;
}

export interface NotificationTransport {
  list: (
    status: NotificationFilter,
    page: number,
    pageSize: number,
    signal: AbortSignal
  ) => Promise<NotificationTransportResult>;
  markRead: (
    messageKey: string,
    revision: number,
    signal: AbortSignal
  ) => Promise<NotificationTransportResult>;
  bulk: (
    messageKeys: readonly string[],
    action: NotificationBulkAction,
    signal: AbortSignal
  ) => Promise<NotificationTransportResult>;
}

const record = (value: unknown): Record<string, unknown> => {
  if (typeof value !== 'object' || value === null || Array.isArray(value))
    throw new Error('NOTIFICATION_RESPONSE_INVALID');
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
    throw new Error('NOTIFICATION_RESPONSE_INVALID');
  }
};

const instant = (value: unknown): value is string =>
  typeof value === 'string' &&
  /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/.test(value) &&
  Number.isFinite(Date.parse(value));

const fileKeyPattern = /^file_[0-9a-f]{32}$/;
const messageKeyPattern = /^notice_[0-9a-f]{32}$/;
const qualifiedKeyPattern = /^[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*$/;

const parseAttachment = (value: unknown): NotificationAttachment => {
  const item = record(value);
  exactKeys(item, [
    'file_key',
    'original_name',
    'media_type',
    'size_bytes',
    'sha256',
  ]);
  if (
    typeof item.file_key !== 'string' ||
    !fileKeyPattern.test(item.file_key) ||
    typeof item.original_name !== 'string' ||
    item.original_name === '' ||
    [...item.original_name].length > 255 ||
    typeof item.media_type !== 'string' ||
    !/^[a-z0-9][a-z0-9.+-]*\/[a-z0-9][a-z0-9.+-]*$/.test(item.media_type) ||
    typeof item.size_bytes !== 'number' ||
    !Number.isSafeInteger(item.size_bytes) ||
    item.size_bytes < 0 ||
    typeof item.sha256 !== 'string' ||
    !/^[0-9a-f]{64}$/.test(item.sha256)
  )
    throw new Error('NOTIFICATION_RESPONSE_INVALID');
  return {
    fileKey: item.file_key,
    originalName: item.original_name,
    mediaType: item.media_type,
    sizeBytes: item.size_bytes,
    sha256: item.sha256,
  };
};

export const parseNotification = (value: unknown): NotificationMessage => {
  const item = record(value);
  exactKeys(item, [
    'message_key',
    'template_key',
    'template_revision',
    'subject',
    'body',
    'status',
    'revision',
    'created_at',
    'read_at',
    'archived_at',
    'attachments',
  ]);
  if (
    typeof item.message_key !== 'string' ||
    !messageKeyPattern.test(item.message_key) ||
    typeof item.template_key !== 'string' ||
    item.template_key.length > 64 ||
    !qualifiedKeyPattern.test(item.template_key) ||
    typeof item.template_revision !== 'number' ||
    !Number.isSafeInteger(item.template_revision) ||
    item.template_revision < 1 ||
    typeof item.subject !== 'string' ||
    item.subject === '' ||
    [...item.subject].length > 255 ||
    typeof item.body !== 'string' ||
    item.body === '' ||
    [...item.body].length > 10000 ||
    !['unread', 'read', 'archived'].includes(String(item.status)) ||
    typeof item.revision !== 'number' ||
    !Number.isSafeInteger(item.revision) ||
    item.revision < 1 ||
    !instant(item.created_at) ||
    (item.read_at !== null && !instant(item.read_at)) ||
    (item.archived_at !== null && !instant(item.archived_at)) ||
    (item.status === 'unread') !== (item.read_at === null) ||
    (item.status === 'archived') !== (item.archived_at !== null) ||
    !Array.isArray(item.attachments) ||
    item.attachments.length > 10
  )
    throw new Error('NOTIFICATION_RESPONSE_INVALID');
  return {
    messageKey: item.message_key,
    templateKey: item.template_key,
    templateRevision: item.template_revision,
    subject: item.subject,
    body: item.body,
    status: item.status as NotificationStatus,
    revision: item.revision,
    createdAt: item.created_at,
    readAt: item.read_at as string | null,
    archivedAt: item.archived_at as string | null,
    attachments: item.attachments.map(parseAttachment),
  };
};

export const parseNotificationResponse = (
  value: unknown
): NotificationMessage => {
  const body = record(value);
  exactKeys(body, ['data', 'meta']);
  const meta = record(body.meta);
  exactKeys(meta, ['request_id']);
  if (typeof meta.request_id !== 'string' || meta.request_id === '')
    throw new Error('NOTIFICATION_RESPONSE_INVALID');
  return parseNotification(body.data);
};

export const parseNotificationList = (value: unknown): NotificationList => {
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
    throw new Error('NOTIFICATION_RESPONSE_INVALID');
  }
  (['page', 'page_size', 'total'] as const).forEach((key) => {
    const number = meta[key];
    if (
      typeof number !== 'number' ||
      !Number.isSafeInteger(number) ||
      number < (key === 'total' ? 0 : 1)
    ) {
      throw new Error('NOTIFICATION_RESPONSE_INVALID');
    }
  });
  return {
    items: data.items.map(parseNotification),
    page: meta.page as number,
    pageSize: meta.page_size as number,
    total: meta.total as number,
  };
};

export const parseBulkResult = (value: unknown): number => {
  const body = record(value);
  exactKeys(body, ['data', 'meta']);
  const data = record(body.data);
  const meta = record(body.meta);
  exactKeys(data, ['changed']);
  exactKeys(meta, ['request_id']);
  if (
    typeof data.changed !== 'number' ||
    !Number.isSafeInteger(data.changed) ||
    data.changed < 0 ||
    typeof meta.request_id !== 'string' ||
    meta.request_id === ''
  )
    throw new Error('NOTIFICATION_RESPONSE_INVALID');
  return data.changed;
};

export const createNotificationFetchTransport = (options: {
  readonly baseUrl: string;
  readonly fetch?: (request: Request) => Promise<Response>;
}): NotificationTransport => {
  const fetcher = options.fetch ?? fetch;
  const sdk = createModuleClient({
    baseUrl: options.baseUrl,
    fetch: fetcher,
  });
  const result = async (
    request: Promise<{ data?: unknown; error?: unknown; response: Response }>
  ): Promise<NotificationTransportResult> => {
    const resolved = await request;
    return {
      body: resolved.data ?? resolved.error ?? null,
      headers: resolved.response.headers,
      status: resolved.response.status,
    };
  };
  return {
    list(status, page, pageSize, signal) {
      return result(
        sdk.listInboxNotifications({
          credentials: 'include',
          headers: { Accept: 'application/json' },
          params: { query: { status, page, page_size: pageSize } },
          signal,
        })
      );
    },
    markRead(messageKey, revision, signal) {
      return result(
        sdk.markInboxNotificationRead({
          body: {},
          credentials: 'include',
          headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/json',
            'If-Match': `"rev-${revision}"`,
          },
          params: {
            header: { 'If-Match': `"rev-${revision}"` },
            path: { messageKey },
          },
          signal,
        })
      );
    },
    bulk(messageKeys, action, signal) {
      return result(
        sdk.bulkUpdateInboxNotifications({
          body: { message_keys: [...messageKeys], action },
          credentials: 'include',
          headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/json',
          },
          signal,
        })
      );
    },
  };
};
