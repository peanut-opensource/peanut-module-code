import { describe, expect, it } from 'vitest';
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
