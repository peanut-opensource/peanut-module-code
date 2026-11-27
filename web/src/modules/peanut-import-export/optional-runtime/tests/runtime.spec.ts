import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { createImportExportRuntime } from '../src/runtime';
import type {
  ImportExportTransport,
  ImportExportTransportResult,
} from '../src/contracts';

const result = (
  status: number,
  body: unknown
): ImportExportTransportResult => ({
  status,
  body,
  headers: new Headers({ 'X-Request-Id': 'req-1' }),
});
const operation = {
  operation_key: `iox_${'a'.repeat(32)}`,
  provider_key: 'test.contacts',
  direction: 'export',
  format: 'csv',
  status: 'queued',
  input_file_key: null,
  result_file_key: null,
  error_file_key: null,
  task_job_key: `job_${'d'.repeat(32)}`,
  schema_revision: 'contacts.v1',
  mapping: {},
  processed_rows: 0,
  accepted_rows: 0,
  rejected_rows: 0,
  total_rows: 0,
  revision: 2,
  last_error_code: null,
  retention_until: '2026-08-01T00:00:00.000Z',
  created_at: '2026-07-24T00:00:00.000Z',
  updated_at: '2026-07-24T00:00:00.000Z',
  completed_at: null,
};
const deferred = <T>() => {
  let resolve!: (value: T) => void;
  const promise = new Promise<T>((done) => {
    resolve = done;
  });
  return { promise, resolve };
};
const listResult = (item = operation) =>
  result(200, {
    data: { items: [item] },
    meta: { request_id: 'req-1', page: 1, page_size: 20, total: 1 },
  });

describe('import/export runtime', () => {
  it('loads, submits, cancels and disposes tenant-scoped state', async () => {
    const calls: string[] = [];
    const transport: ImportExportTransport = {
      async list() {
        calls.push('list');
        return result(200, {
          data: { items: [operation] },
          meta: { request_id: 'req-1', page: 1, page_size: 20, total: 1 },
        });
      },
      async submitImport() {
        calls.push('import');
        return result(201, { data: operation, meta: {} });
      },
      async submitExport() {
        calls.push('export');
        return result(201, { data: operation, meta: {} });
      },
      async cancel() {
        calls.push('cancel');
        return result(200, {
          data: {
            ...operation,
            status: 'cancelled',
            revision: 3,
            completed_at: '2026-07-24T00:00:01.000Z',
          },
          meta: {},
        });
      },
      async download() {
        return new Response('csv');
      },
    };
    const runtime = createImportExportRuntime({
      transport,
      canRead: () => true,
      canCreate: () => true,
      canCancel: () => true,
      idempotencyKey: () => 'web-test-key',
    });
    await runtime.load();
    await runtime.submitExport('test.contacts');
    await runtime.cancel(runtime.state.items[0]!);
    expect(calls).toEqual(['list', 'export', 'list', 'cancel', 'list']);
    runtime.dispose();
    expect(runtime.state.items).toEqual([]);
  });
  it('fails closed before transport when create permission is absent', async () => {
    let called = false;
    const transport = {
      list: async () =>
        result(200, {
          data: { items: [] },
          meta: { request_id: 'r', page: 1, page_size: 20, total: 0 },
        }),
      submitImport: async () => {
        called = true;
        return result(500, {});
      },
      submitExport: async () => {
        called = true;
        return result(500, {});
      },
      cancel: async () => result(500, {}),
      download: async () => new Response(),
    } satisfies ImportExportTransport;
    const runtime = createImportExportRuntime({
      transport,
      canRead: () => true,
      canCreate: () => false,
      canCancel: () => false,
    });
    await runtime.submitExport('test.contacts');
    expect(called).toBe(false);
  });

  it('fences an older list response when the Tenant-scoped filter changes', async () => {
    const first = deferred<ImportExportTransportResult>();
    const second = deferred<ImportExportTransportResult>();
    let calls = 0;
    const transport = {
      list: async () => (++calls === 1 ? first.promise : second.promise),
      submitImport: async () => result(500, {}),
      submitExport: async () => result(500, {}),
      cancel: async () => result(500, {}),
      download: async () => new Response(),
    } satisfies ImportExportTransport;
    const runtime = createImportExportRuntime({
      transport,
      canRead: () => true,
      canCreate: () => true,
      canCancel: () => true,
    });
    const stale = runtime.load();
    const current = runtime.setStatus('running');
    second.resolve(listResult({ ...operation, status: 'running' }));
    await current;
    first.resolve(listResult(operation));
    await stale;
    expect(runtime.state.status).toBe('running');
    expect(runtime.state.items[0]?.status).toBe('running');
  });

  it('keeps the command mutex while filters and downloads use independent read domains', async () => {
    const command = deferred<ImportExportTransportResult>();
    let commandCalls = 0;
    let commandSignal: AbortSignal | undefined;
    let downloadSignal: AbortSignal | undefined;
    const runtimeStatus = 'running' as const;
    const transport = {
      list: async (status: typeof runtimeStatus) =>
        listResult({ ...operation, status }),
      submitImport: async () => {
        commandCalls += 1;
        return result(500, {});
      },
      submitExport: async (
        _provider: string,
        _key: string,
        signal: AbortSignal
      ) => {
        commandCalls += 1;
        commandSignal = signal;
        return command.promise;
      },
      cancel: async () => result(500, {}),
      download: async (_key: string, signal: AbortSignal) => {
        downloadSignal = signal;
        return new Response('csv');
      },
    } satisfies ImportExportTransport;
    const runtime = createImportExportRuntime({
      transport,
      canRead: () => true,
      canCreate: () => true,
      canCancel: () => true,
      saveDownload: async () => {},
    });
    const pending = runtime.submitExport('test.contacts');
    expect(runtime.state.mutating).toBe(true);
    await runtime.setStatus(runtimeStatus);
    await runtime.download(`file_${'e'.repeat(32)}`);
    await runtime.submitImport('test.contacts', `file_${'f'.repeat(32)}`, {});
    expect(commandCalls).toBe(1);
    expect(commandSignal?.aborted).toBe(false);
    expect(downloadSignal?.aborted).toBe(false);
    expect(runtime.state.mutating).toBe(true);
    command.resolve(result(201, { data: operation, meta: {} }));
    await pending;
    expect(runtime.state.mutating).toBe(false);
    expect(runtime.state.status).toBe(runtimeStatus);
  });

  it('aborts and fences in-flight mutation and download work on dispose', async () => {
    const mutation = deferred<ImportExportTransportResult>();
    const saved = deferred<void>();
    let mutationSignal: AbortSignal | undefined;
    let saveSignal: AbortSignal | undefined;
    const transport = {
      list: async () => listResult(),
      submitImport: async () => result(500, {}),
      submitExport: async (
        _provider: string,
        _key: string,
        signal: AbortSignal
      ) => {
        mutationSignal = signal;
        return mutation.promise;
      },
      cancel: async () => result(500, {}),
      download: async () => new Response('csv'),
    } satisfies ImportExportTransport;
    const runtime = createImportExportRuntime({
      transport,
      canRead: () => true,
      canCreate: () => true,
      canCancel: () => true,
      saveDownload: async (_response, _fileKey, signal) => {
        saveSignal = signal;
        await saved.promise;
      },
    });
    const pendingMutation = runtime.submitExport('test.contacts');
    runtime.dispose();
    expect(mutationSignal?.aborted).toBe(true);
    mutation.resolve(result(201, { data: operation, meta: {} }));
    await pendingMutation;
    expect(runtime.state).toMatchObject({
      items: [],
      loading: false,
      mutating: false,
      error: null,
      total: 0,
    });

    const pendingDownload = runtime.download(`file_${'f'.repeat(32)}`);
    await Promise.resolve();
    await Promise.resolve();
    runtime.dispose();
    expect(saveSignal?.aborted).toBe(true);
    saved.resolve();
    await pendingDownload;
    expect(runtime.state.error).toBeNull();
  });
});

describe('asynchronous task result user feedback and permission fencing', () => {
  it('retains confirmed queued operation and request ID after the follow-up list GET fails', async () => {
    let lists = 0;
    let submits = 0;
    const runtime = createImportExportRuntime({
      canRead: () => true, canCreate: () => true, canCancel: () => true,
      transport: {
        list: async () => {
          lists += 1;
          return lists === 1 ? listResult() : result(503, { detail: 'Transient list outage' });
        },
        submitExport: async () => {
          submits += 1;
          return result(201, { data: operation, meta: {} });
        },
        submitImport: async () => result(422, {}),
        cancel: async () => result(422, {}),
        download: async () => new Response(),
      },
    });
    await runtime.load();
    await runtime.submitExport('test.contacts');
    expect(submits).toBe(1);
    expect(runtime.state.actionFeedback).toMatchObject({
      kind: 'accepted', action: 'export',
      operationKey: operation.operation_key,
      taskJobKey: operation.task_job_key,
      requestId: 'req-1', refreshFailed: true,
    });
    expect(runtime.state.error).toMatchObject({ status: 503 });
    expect(runtime.state.items).toHaveLength(1);
    await runtime.submitExport('test.contacts');
    expect(submits).toBe(1);
    runtime.dispose();
  });

  it('preserves unknown command outcome until an explicit successful read and human acknowledgement', async () => {
    let submits = 0;
    const runtime = createImportExportRuntime({
      canRead: () => true, canCreate: () => true, canCancel: () => true,
      transport: {
        list: async () => listResult(),
        submitExport: async () => {
          submits += 1;
          if (submits === 1) throw new Error('socket reset after possible enqueue');
          return result(201, { data: operation, meta: {} });
        },
        submitImport: async () => result(422, {}),
        cancel: async () => result(422, {}),
        download: async () => new Response(),
      },
    });
    await runtime.submitExport('test.contacts');
    expect(runtime.state.actionFeedback).toMatchObject({ kind: 'unknown', verifiedRead: false });
    expect(runtime.state.error?.message).toContain('unconfirmed');
    await runtime.submitExport('test.contacts');
    expect(submits).toBe(1);
    runtime.acknowledgeUnknown();
    expect(runtime.state.actionFeedback?.kind).toBe('unknown');
    await runtime.load();
    expect(runtime.state.actionFeedback).toMatchObject({ kind: 'unknown', verifiedRead: true });
    await runtime.submitExport('test.contacts');
    expect(submits).toBe(1);
    runtime.acknowledgeUnknown();
    expect(runtime.state.actionFeedback).toBeNull();
    await runtime.submitExport('test.contacts');
    expect(submits).toBe(2);
    expect(runtime.state.actionFeedback?.kind).toBe('accepted');
    runtime.dispose();
  });

  it('401/403 on the task list revokes previously visible task and accepted receipt', async () => {
    let reads = 0;
    const runtime = createImportExportRuntime({
      canRead: () => true, canCreate: () => true, canCancel: () => true,
      transport: {
        list: async () => (++reads === 1
          ? listResult()
          : result(403, { detail: 'Role no longer permits this list' })),
        submitExport: async () => result(201, { data: operation, meta: {} }),
        submitImport: async () => result(422, {}),
        cancel: async () => result(422, {}),
        download: async () => new Response(),
      },
    });
    await runtime.load();
    expect(runtime.state.items).toHaveLength(1);
    await runtime.submitExport('test.contacts');
    expect(runtime.state.items).toHaveLength(0);
    expect(runtime.state.total).toBe(0);
    expect(runtime.state.actionFeedback).toBeNull();
    expect(runtime.state.error?.status).toBe(403);
    runtime.dispose();
  });

  it('cancel acknowledgement cannot silently substitute another operation key', async () => {
    const wrong = {
      ...operation, operation_key: 'iox_' + 'f'.repeat(32),
      status: 'cancelled', revision: 3, completed_at: '2026-07-24T00:00:01.000Z',
    };
    let cancels = 0;
    const runtime = createImportExportRuntime({
      canRead: () => true, canCreate: () => true, canCancel: () => true,
      transport: {
        list: async () => listResult(),
        submitImport: async () => result(422, {}),
        submitExport: async () => result(422, {}),
        cancel: async () => {
          cancels += 1;
          return result(200, { data: wrong, meta: {} });
        },
        download: async () => new Response(),
      },
    });
    await runtime.load();
    await runtime.cancel(runtime.state.items[0]!);
    expect(runtime.state.actionFeedback).toMatchObject({
      kind: 'unknown', action: 'cancel', operationKey: operation.operation_key,
      requestId: 'req-1',
    });
    await runtime.cancel(runtime.state.items[0]!);
    expect(cancels).toBe(1);
    runtime.dispose();
  });

  it('non-2xx definite revision conflict retains server trace but does not claim accepted', async () => {
    const runtime = createImportExportRuntime({
      canRead: () => true, canCreate: () => true, canCancel: () => true,
      transport: {
        list: async () => listResult(),
        submitImport: async () => result(422, {}),
        submitExport: async () => result(422, {}),
        cancel: async () => result(409, { detail: 'Revision stale' }),
        download: async () => new Response(),
      },
    });
    await runtime.load();
    await runtime.cancel(runtime.state.items[0]!);
    expect(runtime.state.error).toMatchObject({
      status: 409, requestId: 'req-1', message: 'Revision stale',
    });
    expect(runtime.state.actionFeedback).toBeNull();
    expect(runtime.state.items).toHaveLength(1);
    runtime.dispose();
  });
});

describe('CSV error report preview and original download', () => {
  const errorFileKey = 'file_' + 'f'.repeat(32);
  const report = readFileSync(new URL('./fixtures/article-draft-errors.csv', import.meta.url), 'utf8');
  const transport = (download: ImportExportTransport['download']): ImportExportTransport => ({
    list: async () => listResult(),
    submitImport: async () => result(422, {}),
    submitExport: async () => result(422, {}),
    cancel: async () => result(422, {}),
    download,
  });

  it('previews authorized redacted line issues, keeps full download independent and clears on dispose', async () => {
    const fileCalls: string[] = [];
    const saved: string[] = [];
    const runtime = createImportExportRuntime({
      transport: transport(async (fileKey) => {
        fileCalls.push(fileKey);
        return new Response(report, { headers: { 'content-type': 'text/csv; charset=utf-8' } });
      }),
      canRead: () => true, canCreate: () => false, canCancel: () => false,
      saveDownload: async (_response, fileKey) => { saved.push(fileKey); },
    });
    await runtime.previewErrors(errorFileKey);
    expect(runtime.state.errorPreview).toEqual({
      fileKey: errorFileKey,
      rows: [
        { rowNumber: 3, columnKey: 'title', errorCode: 'IMPORT_VALUE_REQUIRED' },
        { rowNumber: 4, columnKey: 'body', errorCode: 'IMPORT_VALUE_TOO_LONG' },
      ],
      truncated: false,
    });
    expect(saved).toEqual([]);
    await runtime.download(errorFileKey);
    expect(fileCalls).toEqual([errorFileKey, errorFileKey]);
    expect(saved).toEqual([errorFileKey]);
    runtime.dispose();
    expect(runtime.state.errorPreview).toBeNull();
  });

  it('refuses unauthorized reads, session HTML, malformed CSV and oversized previews', async () => {
    let allowed = false;
    let calls = 0;
    let response = new Response(report, { headers: { 'content-type': 'text/csv' } });
    const runtime = createImportExportRuntime({
      transport: transport(async () => { calls += 1; return response; }),
      canRead: () => allowed, canCreate: () => false, canCancel: () => false,
      saveDownload: async () => { throw new Error('must not save HTML'); },
    });
    await runtime.previewErrors(errorFileKey);
    expect(calls).toBe(0);
    allowed = true;
    response = new Response('<html>login</html>', { headers: { 'content-type': 'text/html' } });
    await runtime.previewErrors(errorFileKey);
    expect(runtime.state.errorPreview).toBeNull();
    expect(runtime.state.error?.message).toContain('could not be previewed');
    await runtime.download(errorFileKey);
    expect(runtime.state.error?.message).toContain('not CSV');
    response = new Response('row_number,column_key,error_code\n3,raw data,bad', {
      headers: { 'content-type': 'text/csv' },
    });
    await runtime.previewErrors(errorFileKey);
    expect(runtime.state.errorPreview).toBeNull();
    response = new Response('x'.repeat(512 * 1024 + 1), { headers: { 'content-type': 'text/csv' } });
    await runtime.previewErrors(errorFileKey);
    expect(runtime.state.errorPreview).toBeNull();
    expect(calls).toBe(4);
    runtime.dispose();
  });

  it('requires File original-download permission independently of task read access', async () => {
    let fetched = 0;
    const runtime = createImportExportRuntime({
      transport: transport(async () => {
        fetched += 1;
        return new Response(report, { headers: { 'content-type': 'text/csv' } });
      }),
      canRead: () => true,
      canCreate: () => false,
      canCancel: () => false,
      canDownload: () => false,
    });
    expect(runtime.canDownload()).toBe(false);
    await runtime.previewErrors(errorFileKey);
    await runtime.download(errorFileKey);
    expect(fetched).toBe(0);
    expect(runtime.state.errorPreview).toBeNull();
    expect(runtime.state.error?.status).toBe(403);
    runtime.dispose();
  });

  it('aborts an in-flight preview on dispose rather than rendering a stale report', async () => {
    const deferredResponse = deferred<Response>();
    let signal: AbortSignal | null = null;
    const runtime = createImportExportRuntime({
      transport: transport(async (_key, incoming) => { signal = incoming; return deferredResponse.promise; }),
      canRead: () => true, canCreate: () => false, canCancel: () => false,
    });
    const pending = runtime.previewErrors(errorFileKey);
    runtime.dispose();
    expect(signal?.aborted).toBe(true);
    deferredResponse.resolve(new Response(report, { headers: { 'content-type': 'text/csv' } }));
    await pending;
    expect(runtime.state.errorPreview).toBeNull();
    expect(runtime.state.previewLoading).toBe(false);
  });
});
