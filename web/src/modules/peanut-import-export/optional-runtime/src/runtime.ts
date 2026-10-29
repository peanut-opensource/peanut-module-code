import { inject, reactive } from 'vue';
import type { InjectionKey } from 'vue';
import { parseOperationList, parseOperationResponse } from './contracts';
import type {
  ImportExportOperation,
  ImportExportStatus,
  ImportExportTransport,
  ImportExportTransportResult,
} from './contracts';

export const IMPORT_EXPORT_MODULE_KEY = 'peanut.import-export' as const;
export const IMPORT_EXPORT_ROUTE_NAME =
  'peanut.import-export.operations' as const;
export const IMPORT_EXPORT_ROUTE_PATH =
  '/system/import-export-operations' as const;
export const IMPORT_EXPORT_READ_PERMISSION =
  'peanut.import-export.operations.read' as const;
export const IMPORT_EXPORT_CREATE_PERMISSION =
  'peanut.import-export.operations.create' as const;
export const IMPORT_EXPORT_CANCEL_PERMISSION =
  'peanut.import-export.operations.cancel' as const;
export const IMPORT_EXPORT_STORE_KEY =
  'peanut.import-export.operations.runtime' as const;

export interface ImportExportError {
  message: string;
  requestId: string | null;
  status: number | null;
}
export interface ImportExportState {
  items: ImportExportOperation[];
  status: ImportExportStatus;
  page: number;
  pageSize: number;
  total: number;
  loading: boolean;
  mutating: boolean;
  error: ImportExportError | null;
}
export interface ImportExportRuntime {
  state: ImportExportState;
  canCreate(): boolean;
  canCancel(): boolean;
  load(): Promise<void>;
  setStatus(status: ImportExportStatus): Promise<void>;
  submitImport(
    providerKey: string,
    fileKey: string,
    mapping: Record<string, string>
  ): Promise<void>;
  submitExport(providerKey: string): Promise<void>;
  cancel(operation: ImportExportOperation): Promise<void>;
  download(fileKey: string): Promise<void>;
  dispose(): void;
}
export interface ImportExportRuntimeOptions {
  transport: ImportExportTransport;
  canRead(): boolean;
  canCreate(): boolean;
  canCancel(): boolean;
  saveDownload?: (
    response: Response,
    fileKey: string,
    signal: AbortSignal
  ) => Promise<void>;
  idempotencyKey?: () => string;
}

const requestId = (result: ImportExportTransportResult): string | null => {
  const body =
    typeof result.body === 'object' &&
    result.body !== null &&
    !Array.isArray(result.body)
      ? (result.body as Record<string, unknown>)
      : {};
  const value = body.request_id ?? result.headers.get('X-Request-Id');
  return typeof value === 'string' && value !== '' ? value : null;
};
const failure = (result: ImportExportTransportResult): ImportExportError => {
  const body =
    typeof result.body === 'object' &&
    result.body !== null &&
    !Array.isArray(result.body)
      ? (result.body as Record<string, unknown>)
      : {};
  return {
    message:
      typeof body.detail === 'string' && body.detail !== ''
        ? body.detail
        : `Import/export request failed (${result.status}).`,
    requestId: requestId(result),
    status: result.status,
  };
};
const key = (): string => `web-${crypto.randomUUID()}`;
const save = async (
  response: Response,
  fileKey: string,
  signal: AbortSignal
): Promise<void> => {
  const blob = await response.blob();
  if (signal.aborted) throw new DOMException('Aborted', 'AbortError');
  const url = URL.createObjectURL(blob);
  const anchor = document.createElement('a');
  anchor.href = url;
  anchor.download = `${fileKey}.csv`;
  anchor.click();
  URL.revokeObjectURL(url);
};

export const createImportExportRuntime = (
  options: ImportExportRuntimeOptions
): ImportExportRuntime => {
  const state = reactive<ImportExportState>({
    items: [],
    status: 'queued',
    page: 1,
    pageSize: 20,
    total: 0,
    loading: false,
    mutating: false,
    error: null,
  });
  let disposeEpoch = 0;
  let readEpoch = 0;
  let downloadEpoch = 0;
  let readController: AbortController | null = null;
  let commandController: AbortController | null = null;
  let downloadController: AbortController | null = null;
  const aborted = (error: unknown): boolean =>
    error instanceof DOMException && error.name === 'AbortError';
  const load = async (): Promise<void> => {
    const currentDispose = disposeEpoch;
    readEpoch += 1;
    const currentRead = readEpoch;
    readController?.abort();
    const controller = new AbortController();
    readController = controller;
    state.loading = true;
    state.error = null;
    try {
      if (!options.canRead())
        throw new Error('IMPORT_EXPORT_PERMISSION_DENIED');
      const { status, page, pageSize } = state;
      const result = await options.transport.list(
        status,
        page,
        pageSize,
        controller.signal
      );
      if (currentDispose !== disposeEpoch || currentRead !== readEpoch) return;
      if (result.status !== 200) {
        state.error = failure(result);
        return;
      }
      const list = parseOperationList(result.body);
      state.items = [...list.items];
      state.page = list.page;
      state.pageSize = list.pageSize;
      state.total = list.total;
    } catch (error) {
      if (
        currentDispose === disposeEpoch &&
        currentRead === readEpoch &&
        !aborted(error)
      )
        state.error = {
          message: 'The import/export service could not be reached.',
          requestId: null,
          status: null,
        };
    } finally {
      if (readController === controller) readController = null;
      if (currentDispose === disposeEpoch && currentRead === readEpoch)
        state.loading = false;
    }
  };
  const mutate = async (
    operation: (signal: AbortSignal) => Promise<ImportExportTransportResult>
  ): Promise<void> => {
    if (state.mutating || commandController !== null) return;
    const currentDispose = disposeEpoch;
    const controller = new AbortController();
    commandController = controller;
    state.mutating = true;
    state.error = null;
    try {
      const result = await operation(controller.signal);
      if (currentDispose !== disposeEpoch) return;
      if (result.status !== 201 && result.status !== 200) {
        state.error = failure(result);
        return;
      }
      parseOperationResponse(result.body);
      await load();
    } catch (error) {
      if (currentDispose === disposeEpoch && !aborted(error))
        state.error = {
          message: 'The import/export request could not be completed.',
          requestId: null,
          status: null,
        };
    } finally {
      if (commandController === controller) commandController = null;
      if (currentDispose === disposeEpoch) state.mutating = false;
    }
  };
  return {
    state,
    canCreate: options.canCreate,
    canCancel: options.canCancel,
    load,
    async setStatus(status) {
      state.status = status;
      state.page = 1;
      await load();
    },
    async submitImport(providerKey, fileKey, mapping) {
      if (!options.canCreate()) return;
      await mutate((signal) =>
        options.transport.submitImport(
          providerKey,
          fileKey,
          mapping,
          (options.idempotencyKey ?? key)(),
          signal
        )
      );
    },
    async submitExport(providerKey) {
      if (!options.canCreate()) return;
      await mutate((signal) =>
        options.transport.submitExport(
          providerKey,
          (options.idempotencyKey ?? key)(),
          signal
        )
      );
    },
    async cancel(operation) {
      if (
        !options.canCancel() ||
        !['queued', 'running'].includes(operation.status)
      )
        return;
      await mutate((signal) =>
        options.transport.cancel(
          operation.operationKey,
          operation.revision,
          signal
        )
      );
    },
    async download(fileKey) {
      const currentDispose = disposeEpoch;
      downloadEpoch += 1;
      const currentDownload = downloadEpoch;
      downloadController?.abort();
      const controller = new AbortController();
      downloadController = controller;
      state.error = null;
      try {
        const response = await options.transport.download(
          fileKey,
          controller.signal
        );
        if (
          currentDispose !== disposeEpoch ||
          currentDownload !== downloadEpoch
        )
          return;
        if (!response.ok) {
          let body: unknown = null;
          try {
            body = await response.json();
          } catch {
            body = null;
          }
          if (
            currentDispose === disposeEpoch &&
            currentDownload === downloadEpoch
          )
            state.error = failure({
              status: response.status,
              body,
              headers: response.headers,
            });
          return;
        }
        await (options.saveDownload ?? save)(
          response,
          fileKey,
          controller.signal
        );
      } catch (error) {
        if (
          currentDispose === disposeEpoch &&
          currentDownload === downloadEpoch &&
          !aborted(error)
        )
          state.error = {
            message: 'The CSV file could not be downloaded.',
            requestId: null,
            status: null,
          };
      } finally {
        if (downloadController === controller) downloadController = null;
      }
    },
    dispose() {
      disposeEpoch += 1;
      readEpoch += 1;
      downloadEpoch += 1;
      readController?.abort();
      commandController?.abort();
      downloadController?.abort();
      readController = null;
      commandController = null;
      downloadController = null;
      state.items = [];
      state.status = 'queued';
      state.page = 1;
      state.pageSize = 20;
      state.total = 0;
      state.loading = false;
      state.mutating = false;
      state.error = null;
    },
  };
};

export const importExportRuntimeKey: InjectionKey<ImportExportRuntime> = Symbol(
  IMPORT_EXPORT_STORE_KEY
);
export const useImportExportRuntime = (): ImportExportRuntime => {
  const runtime = inject(importExportRuntimeKey);
  if (runtime === undefined) throw new Error('IMPORT_EXPORT_RUNTIME_MISSING');
  return runtime;
};
