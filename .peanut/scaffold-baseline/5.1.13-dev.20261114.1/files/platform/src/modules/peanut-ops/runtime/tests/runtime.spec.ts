import { describe, expect, it, vi } from 'vitest';
import { createOpsConsoleRuntime } from '../src/runtime';
import type { LogSeverity, OpsConsoleTransport } from '../src/contracts';
import {
  envelope,
  maintenanceData,
  result,
  statusData,
  taskData,
} from './fixtures';

const transport = (
  overrides: Partial<OpsConsoleTransport> = {}
): OpsConsoleTransport => ({
  overview: async () => result(200, envelope(statusData)),
  maintenance: async () => result(200, envelope(maintenanceData)),
  submitBackup: async () => result(202, envelope(taskData)),
  submitRestore: async () =>
    result(202, envelope({ ...taskData, task_type: 'ops.restore.verify' })),
  task: async () => result(200, envelope(taskData)),
  scheduleMaintenance: async () => result(200, envelope(maintenanceData)),
  closeMaintenance: async () =>
    result(200, envelope({ ...maintenanceData, state: 'closed', revision: 2 })),
  logs: async () => result(200, envelope({ items: [], next_cursor: null })),
  ...overrides,
});
const runtime = (
  api: OpsConsoleTransport,
  permissions = true,
  logSources = ['application']
) =>
  createOpsConsoleRuntime({
    transport: api,
    providers: [
      {
        key: 'reference.mysql',
        backup: true,
        restoreTargets: ['verification'],
      },
      { key: 'unsafe', backup: true, restoreTargets: ['production'] },
    ],
    maintenanceReasons: ['upgrade'],
    logSources,
    canRead: () => permissions,
    canBackup: () => permissions,
    canRestore: () => permissions,
    canMaintain: () => permissions,
    canReadLogs: () => permissions,
    idempotencyKey: () => 'fixed-request-0001',
  });
const logPage = (eventKey: string, nextCursor: string | null) =>
  envelope({
    items: [
      {
        event_key: eventKey,
        severity: 'warning',
        component_key: 'http.runtime',
        message: 'An operational event occurred.',
        occurred_at: '2026-07-24T02:00:00.000Z',
        request_id: null,
        occurrences: 1,
      },
    ],
    next_cursor: nextCursor,
  });

describe('ops-console runtime', () => {
  it('fails closed before transport access when platform read permission is absent', async () => {
    const overview = vi.fn();
    const instance = runtime(transport({ overview }), false);
    await instance.load();
    expect(overview).not.toHaveBeenCalled();
    expect(instance.state.error).toMatchObject({
      code: 'OPS_PERMISSION_DENIED',
      status: 403,
    });
  });

  it('discards stale responses and aborts active requests on dispose', async () => {
    let resolve!: (value: ReturnType<typeof result>) => void;
    const observed: AbortSignal[] = [];
    const pending = new Promise<ReturnType<typeof result>>((done) => {
      resolve = done;
    });
    const instance = runtime(
      transport({
        overview: async (signal) => {
          observed.push(signal);
          return pending;
        },
      })
    );
    const loading = instance.load();
    instance.dispose();
    expect(observed[0]?.aborted).toBe(true);
    resolve(result(200, envelope(statusData)));
    await loading;
    expect(instance.state.overview).toBeNull();
  });

  it('uses only registered provider targets and never renders server detail', async () => {
    const submitRestore = vi.fn(async () =>
      result(503, {
        code: 'OPS_PROVIDER_UNAVAILABLE',
        detail: 'password=secret /private/restore.sql',
        request_id: 'req_ops_2',
      })
    );
    const instance = runtime(transport({ submitRestore }));
    await instance.load();
    expect(instance.providers.map((item) => item.key)).toEqual([
      'reference.mysql',
    ]);
    await instance.submitRestore(
      'reference.mysql',
      'backup_12345678',
      'production'
    );
    expect(submitRestore).not.toHaveBeenCalled();
    await instance.submitRestore(
      'reference.mysql',
      'backup_12345678',
      'verification'
    );
    expect(instance.state.error?.message).toBe(
      'The operation provider is unavailable.'
    );
    expect(JSON.stringify(instance.state.error)).not.toContain('password=');
    expect(submitRestore).toHaveBeenCalledWith(
      'reference.mysql',
      'backup_12345678',
      'verification',
      'fixed-request-0001',
      expect.any(AbortSignal)
    );
  });

  it('keeps log filters in an independent request generation', async () => {
    type Pending = {
      resolve: (value: ReturnType<typeof result>) => void;
      signal: AbortSignal;
    };
    const pending = new Map<string, Pending>();
    const logs = vi.fn(
      (
        source: string,
        _severity: LogSeverity,
        _cursor: string | null,
        _pageSize: number,
        signal: AbortSignal
      ) =>
        new Promise<ReturnType<typeof result>>((resolve) => {
          pending.set(source, { resolve, signal });
        })
    );
    const instance = runtime(transport({ logs }), true, [
      'application',
      'audit',
    ]);
    const oldRequest = instance.loadLogs();
    const newRequest = instance.setLogFilter('audit', 'warning');
    expect(pending.get('application')?.signal.aborted).toBe(true);
    pending
      .get('application')
      ?.resolve(result(200, logPage('runtime.old', null)));
    pending.get('audit')?.resolve(result(200, logPage('runtime.new', null)));
    await Promise.all([oldRequest, newRequest]);
    expect(instance.state.logs.map((item) => item.eventKey)).toEqual([
      'runtime.new',
    ]);
  });

  it('clears old filter rows immediately and keeps them cleared when the new request fails', async () => {
    let resolve!: (value: ReturnType<typeof result>) => void;
    const logs = vi
      .fn()
      .mockResolvedValueOnce(result(200, logPage('runtime.old', null)))
      .mockImplementationOnce(
        () =>
          new Promise<ReturnType<typeof result>>((done) => {
            resolve = done;
          })
      );
    const instance = runtime(transport({ logs }), true, [
      'application',
      'audit',
    ]);
    await instance.loadLogs();
    expect(instance.state.logs.map((item) => item.eventKey)).toEqual([
      'runtime.old',
    ]);
    const filtering = instance.setLogFilter('audit', 'error');
    expect(instance.state.logs).toEqual([]);
    expect(instance.state.logsLoading).toBe(true);
    resolve(result(503, { code: 'OPS_LOGS_UNAVAILABLE' }));
    await filtering;
    expect(instance.state.logs).toEqual([]);
    expect(instance.state.logsError?.code).toBe('OPS_LOGS_UNAVAILABLE');
  });

  it('serializes load-more and rejects a page that repeats its input cursor', async () => {
    let resolveMore!: (value: ReturnType<typeof result>) => void;
    const logs = vi
      .fn()
      .mockResolvedValueOnce(
        result(200, logPage('runtime.first', 'cursor_page0001'))
      )
      .mockImplementationOnce(
        () =>
          new Promise<ReturnType<typeof result>>((resolve) => {
            resolveMore = resolve;
          })
      );
    const instance = runtime(transport({ logs }));
    await instance.loadLogs();
    const firstLoadMore = instance.loadLogs(false);
    const concurrentLoadMore = instance.loadLogs(false);
    expect(logs).toHaveBeenCalledTimes(2);
    resolveMore(result(200, logPage('runtime.duplicate', 'cursor_page0001')));
    await Promise.all([firstLoadMore, concurrentLoadMore]);
    expect(instance.state.logs.map((item) => item.eventKey)).toEqual([
      'runtime.first',
    ]);
    expect(instance.state.logsError?.code).toBe('OPS_LOGS_UNAVAILABLE');
  });

  it('aborts and invalidates log requests on dispose', async () => {
    let resolve!: (value: ReturnType<typeof result>) => void;
    let signal!: AbortSignal;
    const pending = new Promise<ReturnType<typeof result>>((done) => {
      resolve = done;
    });
    const instance = runtime(
      transport({
        logs: async (_source, _severity, _cursor, _pageSize, observed) => {
          signal = observed;
          return pending;
        },
      })
    );
    const loading = instance.loadLogs();
    instance.dispose();
    expect(signal.aborted).toBe(true);
    resolve(result(200, logPage('runtime.stale', null)));
    await loading;
    expect(instance.state.logs).toEqual([]);
    expect(instance.state.logsLoading).toBe(false);
  });
});
