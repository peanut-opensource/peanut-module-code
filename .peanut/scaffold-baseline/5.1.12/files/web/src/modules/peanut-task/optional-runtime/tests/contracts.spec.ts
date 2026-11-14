import { describe, expect, it } from 'vitest';
import { parseTaskJob, parseTaskList } from '../src/contracts';

const job = {
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

describe('task-job contracts', () => {
  it('parses the exact redacted job and list shapes', () => {
    expect(parseTaskJob(job).taskType).toBe('file.variant');
    expect(
      parseTaskList({
        data: { items: [job] },
        meta: { page: 1, page_size: 20, total: 1 },
      }).total
    ).toBe(1);
  });

  it('rejects execution payload, handler and inconsistent terminal state', () => {
    expect(() =>
      parseTaskJob({ ...job, handler_key: 'private.handler' })
    ).toThrow('TASK_RESPONSE_INVALID');
    expect(() => parseTaskJob({ ...job, status: 'succeeded' })).toThrow(
      'TASK_RESPONSE_INVALID'
    );
    expect(() =>
      parseTaskJob({ ...job, last_error_code: 'raw stack trace' })
    ).toThrow('TASK_RESPONSE_INVALID');
  });
});
