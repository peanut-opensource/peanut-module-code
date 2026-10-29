import { inject, reactive } from 'vue';
import type { InjectionKey } from 'vue';

import {
  parseBulkResult,
  parseNotificationList,
  parseNotificationResponse,
} from './contracts';
import type {
  NotificationBulkAction,
  NotificationFilter,
  NotificationMessage,
  NotificationTransport,
  NotificationTransportResult,
} from './contracts';

export const NOTIFICATION_SMS_MODULE_KEY = 'peanut.notification' as const;
export const NOTIFICATION_SMS_ROUTE_NAME =
  'peanut.notification.inbox' as const;
export const NOTIFICATION_SMS_ROUTE_PATH = '/notice/inbox' as const;
export const NOTIFICATION_SMS_READ_PERMISSION =
  'peanut.notification.inbox.read' as const;
export const NOTIFICATION_SMS_MANAGE_PERMISSION =
  'peanut.notification.inbox.manage' as const;
export const NOTIFICATION_SMS_STORE_KEY =
  'peanut.notification.inbox.runtime' as const;

export interface NotificationError {
  readonly message: string;
  readonly messageKey?: string;
  readonly requestId: string | null;
  readonly status: number | null;
}
export interface NotificationState {
  items: NotificationMessage[];
  status: NotificationFilter;
  page: number;
  pageSize: number;
  total: number;
  selected: Set<string>;
  loading: boolean;
  mutating: boolean;
  error: NotificationError | null;
}
export interface NotificationRuntime {
  readonly state: NotificationState;
  readonly canManage: () => boolean;
  load: () => Promise<void>;
  setStatus: (status: NotificationFilter) => Promise<void>;
  setPage: (page: number) => Promise<void>;
  setPageSize: (size: number) => Promise<void>;
  toggle: (messageKey: string) => void;
  selectPage: (selected: boolean) => void;
  markRead: (message: NotificationMessage) => Promise<boolean>;
  archive: (message: NotificationMessage) => Promise<boolean>;
  bulk: (action: NotificationBulkAction) => Promise<boolean>;
  dispose: () => void;
}
export interface NotificationRuntimeOptions {
  readonly transport: NotificationTransport;
  readonly canRead: () => boolean;
  readonly canManage?: () => boolean;
}

const failure = (result: NotificationTransportResult): NotificationError => {
  const body =
    typeof result.body === 'object' &&
    result.body !== null &&
    !Array.isArray(result.body)
      ? (result.body as Record<string, unknown>)
      : {};
  const requestId = body.request_id ?? result.headers.get('X-Request-Id');
  return {
    message:
      typeof body.detail === 'string' && body.detail !== ''
        ? body.detail
        : `Notification request failed (${result.status}).`,
    ...(typeof body.detail === 'string' && body.detail !== ''
      ? {}
      : { messageKey: 'notificationInbox.requestFailed' }),
    requestId:
      typeof requestId === 'string' && requestId !== '' ? requestId : null,
    status: result.status,
  };
};

export const createNotificationRuntime = (
  options: NotificationRuntimeOptions
): NotificationRuntime => {
  const canManage = options.canManage ?? options.canRead;
  const state = reactive<NotificationState>({
    items: [],
    status: 'all',
    page: 1,
    pageSize: 10,
    total: 0,
    selected: new Set(),
    loading: false,
    mutating: false,
    error: null,
  });
  const controllers = new Set<AbortController>();
  let generation = 0;
  const run = async <T>(
    operation: (signal: AbortSignal) => Promise<T>
  ): Promise<T> => {
    const controller = new AbortController();
    controllers.add(controller);
    try {
      return await operation(controller.signal);
    } finally {
      controllers.delete(controller);
    }
  };
  const load = async (): Promise<void> => {
    generation += 1;
    const current = generation;
    state.loading = true;
    state.error = null;
    state.selected.clear();
    try {
      if (!options.canRead()) {
        state.error = {
          message: 'Permission denied.',
          messageKey: 'notificationInbox.forbidden',
          requestId: null,
          status: 403,
        };
        return;
      }
      const result = await run((signal) =>
        options.transport.list(state.status, state.page, state.pageSize, signal)
      );
      if (current !== generation) return;
      if (result.status !== 200) {
        state.error = failure(result);
        return;
      }
      let list = parseNotificationList(result.body);
      const lastPage = Math.max(1, Math.ceil(list.total / list.pageSize));
      if (list.page > lastPage) {
        state.page = lastPage;
        const corrected = await run((signal) =>
          options.transport.list(state.status, lastPage, state.pageSize, signal)
        );
        if (current !== generation) return;
        if (corrected.status !== 200) {
          state.error = failure(corrected);
          return;
        }
        list = parseNotificationList(corrected.body);
      }
      state.items = [...list.items];
      state.page = list.page;
      state.pageSize = list.pageSize;
      state.total = list.total;
      state.selected.clear();
    } catch {
      if (current === generation)
        state.error = {
          message: 'The notification service could not be reached.',
          messageKey: 'notificationInbox.loadFailed',
          requestId: null,
          status: null,
        };
    } finally {
      if (current === generation) state.loading = false;
    }
  };
  const mutate = async (
    operation: (signal: AbortSignal) => Promise<NotificationTransportResult>,
    parser: (body: unknown) => unknown
  ): Promise<boolean> => {
    if (state.loading || state.mutating || state.error !== null || !canManage())
      return false;
    const current = generation;
    state.mutating = true;
    state.error = null;
    try {
      const result = await run(operation);
      if (current !== generation) return false;
      if (result.status !== 200) {
        state.error = failure(result);
        return false;
      }
      parser(result.body);
      if (current !== generation) return false;
      state.mutating = false;
      await load();
      // Disposal or a superseding load invalidates this action's feedback.
      return generation === current + 1 && state.error === null;
    } catch {
      if (current === generation)
        state.error = {
          message: 'The notification action could not be completed.',
          messageKey: 'notificationInbox.actionFailed',
          requestId: null,
          status: null,
        };
      return false;
    } finally {
      if (current === generation) state.mutating = false;
    }
  };
  return {
    state,
    canManage,
    load,
    async setStatus(status) {
      if (state.loading || state.mutating) return;
      state.status = status;
      state.page = 1;
      await load();
    },
    async setPage(page) {
      if (
        state.loading ||
        state.mutating ||
        !Number.isInteger(page) ||
        page < 1
      )
        return;
      state.page = page;
      await load();
    },
    async setPageSize(size) {
      if (state.loading || state.mutating || ![10, 20, 50].includes(size))
        return;
      state.pageSize = size;
      state.page = 1;
      await load();
    },
    selectPage(selected) {
      if (state.loading || state.mutating || !canManage()) return;
      state.selected.clear();
      if (selected)
        state.items.forEach((message) =>
          state.selected.add(message.messageKey)
        );
    },
    toggle(messageKey) {
      if (
        state.loading ||
        state.mutating ||
        !canManage() ||
        !state.items.some((message) => message.messageKey === messageKey)
      )
        return;
      if (state.selected.has(messageKey)) state.selected.delete(messageKey);
      else if (state.selected.size < 100) state.selected.add(messageKey);
    },
    markRead(message) {
      if (message.status !== 'unread') return Promise.resolve(false);
      return mutate(
        (signal) =>
          options.transport.markRead(
            message.messageKey,
            message.revision,
            signal
          ),
        parseNotificationResponse
      );
    },
    archive(message) {
      if (message.status === 'archived') return Promise.resolve(false);
      return mutate(
        (signal) =>
          options.transport.bulk([message.messageKey], 'archive', signal),
        parseBulkResult
      );
    },
    bulk(action) {
      const keys = state.items
        .filter(
          (message) =>
            state.selected.has(message.messageKey) &&
            (action === 'read'
              ? message.status === 'unread'
              : message.status !== 'archived')
        )
        .map((message) => message.messageKey);
      if (keys.length === 0) return Promise.resolve(false);
      return mutate(
        (signal) => options.transport.bulk(keys, action, signal),
        parseBulkResult
      );
    },
    dispose() {
      generation += 1;
      controllers.forEach((controller) => controller.abort());
      controllers.clear();
      state.items = [];
      state.status = 'all';
      state.page = 1;
      state.pageSize = 10;
      state.total = 0;
      state.selected.clear();
      state.loading = false;
      state.mutating = false;
      state.error = null;
    },
  };
};

export const notificationRuntimeKey: InjectionKey<NotificationRuntime> = Symbol(
  NOTIFICATION_SMS_STORE_KEY
);
export const useNotificationRuntime = (): NotificationRuntime => {
  const runtime = inject(notificationRuntimeKey);
  if (runtime === undefined) throw new Error('NOTIFICATION_RUNTIME_MISSING');
  return runtime;
};
