import { createModuleClient } from '../../generated/openapi';

export type ImportExportDirection = 'import' | 'export';
export type ImportExportStatus =
  | 'queued'
  | 'running'
  | 'cancel_requested'
  | 'succeeded'
  | 'failed'
  | 'cancelled'
  | 'expired';

export interface ImportExportOperation {
  operationKey: string;
  providerKey: string;
  direction: ImportExportDirection;
  status: ImportExportStatus;
  inputFileKey: string | null;
  resultFileKey: string | null;
  errorFileKey: string | null;
  taskJobKey: string | null;
  schemaRevision: string;
  processedRows: number;
  acceptedRows: number;
  rejectedRows: number;
  totalRows: number;
  revision: number;
  lastErrorCode: string | null;
  retentionUntil: string;
  createdAt: string;
  updatedAt: string;
  completedAt: string | null;
}

export interface ImportExportList {
  items: ImportExportOperation[];
  page: number;
  pageSize: number;
  total: number;
}
export interface ImportExportTransportResult {
  status: number;
  body: unknown;
  headers: Headers;
}
export interface ImportExportTransport {
  list(
    status: ImportExportStatus,
    page: number,
    pageSize: number,
    signal: AbortSignal
  ): Promise<ImportExportTransportResult>;
  submitImport(
    providerKey: string,
    fileKey: string,
    mapping: Record<string, string>,
    idempotencyKey: string,
    signal: AbortSignal
  ): Promise<ImportExportTransportResult>;
  submitExport(
    providerKey: string,
    idempotencyKey: string,
    signal: AbortSignal
  ): Promise<ImportExportTransportResult>;
  cancel(
    operationKey: string,
    revision: number,
    signal: AbortSignal
  ): Promise<ImportExportTransportResult>;
  download(fileKey: string, signal: AbortSignal): Promise<Response>;
}

const operationPattern = /^iox_[0-9a-f]{32}$/;
const filePattern = /^file_[0-9a-f]{32}$/;
const jobPattern = /^job_[0-9a-f]{32}$/;
const providerPattern = /^[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*$/;
const revisionPattern = /^[a-z0-9][a-z0-9._-]{0,63}$/;
const errorPattern = /^[A-Z][A-Z0-9_]{2,63}$/;
const datePattern = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/;

const record = (value: unknown): Record<string, unknown> => {
  if (typeof value !== 'object' || value === null || Array.isArray(value))
    throw new Error('IMPORT_EXPORT_RESPONSE_INVALID');
  return value as Record<string, unknown>;
};
const exact = (
  value: Record<string, unknown>,
  keys: readonly string[]
): void => {
  if (Object.keys(value).sort().join('|') !== [...keys].sort().join('|'))
    throw new Error('IMPORT_EXPORT_RESPONSE_INVALID');
};
const nullablePattern = (
  value: unknown,
  pattern: RegExp
): value is string | null =>
  value === null || (typeof value === 'string' && pattern.test(value));
const integer = (value: unknown, minimum = 0): value is number =>
  typeof value === 'number' && Number.isSafeInteger(value) && value >= minimum;

export const parseOperation = (value: unknown): ImportExportOperation => {
  const item = record(value);
  exact(item, [
    'operation_key',
    'provider_key',
    'direction',
    'format',
    'status',
    'input_file_key',
    'result_file_key',
    'error_file_key',
    'task_job_key',
    'schema_revision',
    'mapping',
    'processed_rows',
    'accepted_rows',
    'rejected_rows',
    'total_rows',
    'revision',
    'last_error_code',
    'retention_until',
    'created_at',
    'updated_at',
    'completed_at',
  ]);
  if (
    !operationPattern.test(String(item.operation_key)) ||
    !providerPattern.test(String(item.provider_key)) ||
    (item.direction !== 'import' && item.direction !== 'export') ||
    item.format !== 'csv' ||
    ![
      'queued',
      'running',
      'cancel_requested',
      'succeeded',
      'failed',
      'cancelled',
      'expired',
    ].includes(String(item.status)) ||
    !nullablePattern(item.input_file_key, filePattern) ||
    !nullablePattern(item.result_file_key, filePattern) ||
    !nullablePattern(item.error_file_key, filePattern) ||
    !nullablePattern(item.task_job_key, jobPattern) ||
    !revisionPattern.test(String(item.schema_revision)) ||
    !integer(item.processed_rows) ||
    !integer(item.accepted_rows) ||
    !integer(item.rejected_rows) ||
    !integer(item.total_rows) ||
    !integer(item.revision, 1) ||
    (item.accepted_rows as number) + (item.rejected_rows as number) >
      (item.processed_rows as number) ||
    (item.last_error_code !== null &&
      (typeof item.last_error_code !== 'string' ||
        !errorPattern.test(item.last_error_code))) ||
    !datePattern.test(String(item.retention_until)) ||
    !datePattern.test(String(item.created_at)) ||
    !datePattern.test(String(item.updated_at)) ||
    (item.completed_at !== null &&
      (typeof item.completed_at !== 'string' ||
        !datePattern.test(item.completed_at)))
  ) {
    throw new Error('IMPORT_EXPORT_RESPONSE_INVALID');
  }
  const terminal = ['succeeded', 'failed', 'cancelled', 'expired'].includes(
    String(item.status)
  );
  if (
    (item.direction === 'import') !== (item.input_file_key !== null) ||
    terminal !== (item.completed_at !== null)
  )
    throw new Error('IMPORT_EXPORT_RESPONSE_INVALID');
  const mapping = record(item.mapping);
  if (
    Object.entries(mapping).some(
      ([source, target]) =>
        source === '' ||
        source.length > 120 ||
        typeof target !== 'string' ||
        !/^[a-z][a-z0-9_]{0,63}$/.test(target)
    )
  )
    throw new Error('IMPORT_EXPORT_RESPONSE_INVALID');
  return {
    operationKey: item.operation_key as string,
    providerKey: item.provider_key as string,
    direction: item.direction as ImportExportDirection,
    status: item.status as ImportExportStatus,
    inputFileKey: item.input_file_key as string | null,
    resultFileKey: item.result_file_key as string | null,
    errorFileKey: item.error_file_key as string | null,
    taskJobKey: item.task_job_key as string | null,
    schemaRevision: item.schema_revision as string,
    processedRows: item.processed_rows as number,
    acceptedRows: item.accepted_rows as number,
    rejectedRows: item.rejected_rows as number,
    totalRows: item.total_rows as number,
    revision: item.revision as number,
    lastErrorCode: item.last_error_code as string | null,
    retentionUntil: item.retention_until as string,
    createdAt: item.created_at as string,
    updatedAt: item.updated_at as string,
    completedAt: item.completed_at as string | null,
  };
};

export const parseOperationResponse = (
  value: unknown
): ImportExportOperation => {
  const body = record(value);
  exact(body, ['data', 'meta']);
  record(body.meta);
  return parseOperation(body.data);
};
export const parseOperationList = (value: unknown): ImportExportList => {
  const body = record(value);
  exact(body, ['data', 'meta']);
  const data = record(body.data);
  exact(data, ['items']);
  if (!Array.isArray(data.items))
    throw new Error('IMPORT_EXPORT_RESPONSE_INVALID');
  const meta = record(body.meta);
  exact(meta, ['request_id', 'page', 'page_size', 'total']);
  if (
    typeof meta.request_id !== 'string' ||
    meta.request_id === '' ||
    !integer(meta.page, 1) ||
    !integer(meta.page_size, 1) ||
    !integer(meta.total)
  )
    throw new Error('IMPORT_EXPORT_RESPONSE_INVALID');
  return {
    items: data.items.map(parseOperation),
    page: meta.page,
    pageSize: meta.page_size,
    total: meta.total,
  };
};

export const createImportExportFetchTransport = (options: {
  baseUrl: string;
  fetch?: (request: Request) => Promise<Response>;
}): ImportExportTransport => {
  const fetcher = options.fetch ?? fetch;
  const sdk = createModuleClient({
    baseUrl: options.baseUrl,
    fetch: fetcher,
  });
  const result = async (
    request: Promise<{ data?: unknown; error?: unknown; response: Response }>
  ): Promise<ImportExportTransportResult> => {
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
        sdk.listImportExportOperations({
          credentials: 'include',
          headers: { Accept: 'application/json' },
          params: { query: { status, page, page_size: pageSize } },
          signal,
        })
      );
    },
    submitImport(providerKey, fileKey, mapping, idempotencyKey, signal) {
      return result(
        sdk.submitImportOperation({
          body: { provider_key: providerKey, file_key: fileKey, mapping },
          credentials: 'include',
          headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/json',
            'Idempotency-Key': idempotencyKey,
          },
          params: { header: { 'Idempotency-Key': idempotencyKey } },
          signal,
        })
      );
    },
    submitExport(providerKey, idempotencyKey, signal) {
      return result(
        sdk.submitExportOperation({
          body: { provider_key: providerKey },
          credentials: 'include',
          headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/json',
            'Idempotency-Key': idempotencyKey,
          },
          params: { header: { 'Idempotency-Key': idempotencyKey } },
          signal,
        })
      );
    },
    cancel(operationKey, revision, signal) {
      return result(
        sdk.cancelImportExportOperation({
          body: { revision },
          credentials: 'include',
          headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/json',
          },
          params: { path: { operationKey } },
          signal,
        })
      );
    },
    download(fileKey, signal) {
      return fetcher(
        new Request(
          new URL(
            `/adminapi/api/v1/files/${encodeURIComponent(fileKey)}/content`,
            options.baseUrl
          ),
          {
            credentials: 'include',
            headers: { Accept: 'text/csv' },
            signal,
          }
        )
      );
    },
  };
};
