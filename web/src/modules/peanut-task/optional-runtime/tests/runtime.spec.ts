import { describe, expect, it } from 'vitest';
import { createTaskJobRuntime } from '../src/runtime';

const queued = {
  job_key: 'job_0123456789abcdef0123456789abcdef',
  task_type: 'file.variant',
  status: 'queued',
  attempt_count: 0,
  max_attempts: 3,
  revision: 1,
  last_error_code: null,
  available_at: '2026-07-24T00:00:00.000Z',
  created_at: '2026-07-24T00:00:00.000Z',
  updated_at: '2026-07-24T00:00:00.000Z',
  completed_at: null,
};

describe('task-job runtime', () => {
  it('loads redacted jobs and never exposes a client submission transport', async () => {
    const runtime = createTaskJobRuntime({
      canRead: () => true,
      canManage: () => false,
      transport: {
        list: async () => ({
          status: 200,
          headers: new Headers(),
          body: {
            data: { items: [queued] },
            meta: { page: 1, page_size: 20, total: 1 },
          },
        }),
        cancel: async () => {
          throw new Error('not called');
        },
        retry: async () => {
          throw new Error('not called');
        },
      },
    });
    await runtime.load();
    expect(runtime.state.items).toHaveLength(1);
    expect(runtime.state.items[0]).not.toHaveProperty('payload');
    expect(runtime).not.toHaveProperty('submit');
  });
});

const result = (status: number, body: unknown, requestId = 'trace_task_synthetic') => ({
  status,
  headers: new Headers({ 'X-Request-Id': requestId }),
  body,
});
const jobAfter = (status: 'cancelled' | 'queued', revision = 2) => ({
  ...queued,
  status,
  revision,
  last_error_code: null,
  completed_at: status === 'cancelled' ? '2026-07-24T00:10:00.000Z' : null,
});
const taskResponse = (job: unknown) => ({ data: job, meta: {} });

describe('task native action feedback, original tenant-session transport', () => {
  it('shows cancellation receipt + request ID and refreshes without submitting again', async () => {
    let listCalls = 0;
    let cancelCalls = 0;
    const runtime = createTaskJobRuntime({
      canRead: () => true,
      canManage: () => true,
      transport: {
        list: async () => {
          listCalls += 1;
          return result(200, {
            data: { items: listCalls === 1 ? [queued] : [] },
            meta: { page: 1, page_size: 20, total: listCalls === 1 ? 1 : 0 },
          });
        },
        cancel: async (_key, revision) => {
          cancelCalls += 1;
          expect(revision).toBe(1);
          return result(200, taskResponse(jobAfter('cancelled')), 'trace_cancel_confirmed');
        },
        retry: async () => { throw Error('unrelated transport'); },
      },
    });
    await runtime.load();
    await runtime.cancel(runtime.state.items[0]);
    expect(cancelCalls).toBe(1);
    expect(listCalls).toBe(2);
    expect(runtime.state.items).toHaveLength(0);
    expect(runtime.state.error).toBeNull();
    expect(runtime.state.actionFeedback).toMatchObject({
      kind: 'success', operation: 'cancel', requestId: 'trace_cancel_confirmed',
      jobKey: queued.job_key, refreshFailed: false,
    });
  });

  it('keeps visible rows on 409, blocks duplicate submission until explicit reload', async () => {
    let calls = 0;
    const runtime = createTaskJobRuntime({
      canRead: () => true, canManage: () => true,
      transport: {
        list: async () => result(200, {
          data: { items: [queued] },
          meta: { page: 1, page_size: 20, total: 1 },
        }),
        cancel: async () => {
          calls += 1;
          return result(409, { detail: 'Task revision conflict' }, 'trace_task_conflict');
        },
        retry: async () => { throw Error('unrelated'); },
      },
    });
    await runtime.load();
    await runtime.cancel(runtime.state.items[0]);
    expect(runtime.state.error).toBeNull();
    expect(runtime.state.items).toHaveLength(1);
    expect(runtime.state.actionFeedback).toMatchObject({
      kind: 'error', operation: 'cancel', requestId: 'trace_task_conflict',
      error: { status: 409, message: 'Task revision conflict' },
    });
    await runtime.cancel(runtime.state.items[0]);
    expect(calls).toBe(1);
    await runtime.load();
    expect(runtime.state.actionFeedback).toBeNull();
    await runtime.cancel(runtime.state.items[0]);
    expect(calls).toBe(2);
  });

  it('retains confirmed retry receipt when follow-up list refresh fails', async () => {
    let listCalls = 0;
    let retryCalls = 0;
    const dead = {
      ...queued, status: 'dead' as const,
      completed_at: '2026-07-24T00:01:00.000Z', last_error_code: 'TASK_FAILED',
    };
    const runtime = createTaskJobRuntime({
      canRead: () => true, canManage: () => true,
      transport: {
        list: async () => {
          listCalls += 1;
          return listCalls === 1
            ? result(200, { data: { items: [dead] }, meta: { page: 1, page_size: 20, total: 1 } })
            : result(503, { detail: 'Read endpoint temporarily unavailable' });
        },
        cancel: async () => { throw Error('unrelated'); },
        retry: async () => {
          retryCalls += 1;
          return result(200, taskResponse(jobAfter('queued')), 'trace_retry_confirmed');
        },
      },
    });
    await runtime.load();
    await runtime.retry(runtime.state.items[0]);
    expect(retryCalls).toBe(1);
    expect(listCalls).toBe(2);
    expect(runtime.state.actionFeedback).toMatchObject({
      kind: 'success', operation: 'retry',
      requestId: 'trace_retry_confirmed', refreshFailed: true,
    });
    expect(runtime.state.error).toMatchObject({ status: 503 });
    expect(runtime.state.items[0].status).toBe('dead');
    // Explicit list refresh does not repeat the already-confirmed POST.
    await runtime.load();
    expect(retryCalls).toBe(1);
  });

  it('does not claim success if another job was returned or response cannot be confirmed', async () => {
    let listCalls = 0;
    const runtime = createTaskJobRuntime({
      canRead: () => true, canManage: () => true,
      transport: {
        list: async () => {
          listCalls += 1;
          return result(200, { data: { items: [queued] }, meta: { page: 1, page_size: 20, total: 1 } });
        },
        cancel: async () => result(200, taskResponse({
          ...jobAfter('cancelled'), job_key: 'job_ffffffffffffffffffffffffffffffff',
        })),
        retry: async () => { throw Error('unrelated'); },
      },
    });
    await runtime.load();
    await runtime.cancel(runtime.state.items[0]);
    expect(listCalls).toBe(1);
    expect(runtime.state.actionFeedback).toMatchObject({
      kind: 'error', operation: 'cancel',
      error: { messageKey: 'taskJobs.error.actionUnconfirmed' },
    });
    expect(runtime.state.items).toHaveLength(1);
  });

  it('does not grant actions to read-only roles or submit anything without permission', async () => {
    let submits = 0;
    const runtime = createTaskJobRuntime({
      canRead: () => true, canManage: () => false,
      transport: {
        list: async () => result(200, {
          data: { items: [queued] }, meta: { page: 1, page_size: 20, total: 1 },
        }),
        cancel: async () => { submits += 1; throw Error('must not call'); },
        retry: async () => { submits += 1; throw Error('must not call'); },
      },
    });
    await runtime.load();
    await runtime.cancel(runtime.state.items[0]);
    await runtime.retry(runtime.state.items[0]);
    expect(submits).toBe(0);
    expect(runtime.state.actionFeedback).toBeNull();
  });
});

describe('task mutation request-trace fidelity', () => {
  it('shows server problem request_id when response header is absent', async () => {
    const runtime = createTaskJobRuntime({
      canRead: () => true, canManage: () => true,
      transport: {
        list: async () => result(200, {
          data: { items: [queued] },
          meta: { page: 1, page_size: 20, total: 1 },
        }),
        cancel: async () => ({
          status: 412,
          headers: new Headers(),
          body: { detail: 'Expected revision was superseded', request_id: 'trace_body_412' },
        }),
        retry: async () => { throw Error('unrelated'); },
      },
    });
    await runtime.load();
    await runtime.cancel(runtime.state.items[0]);
    expect(runtime.state.actionFeedback).toMatchObject({
      kind: 'error', operation: 'cancel', requestId: 'trace_body_412',
      error: { status: 412, requestId: 'trace_body_412' },
    });
    expect(runtime.state.items).toHaveLength(1);
  });
});
