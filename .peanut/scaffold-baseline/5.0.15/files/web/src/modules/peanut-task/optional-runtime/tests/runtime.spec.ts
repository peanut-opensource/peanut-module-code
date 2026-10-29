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
