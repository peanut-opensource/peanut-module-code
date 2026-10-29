import { describe, expect, it } from 'vitest';
import { parseOperation, parseOperationList } from '../src/contracts';

const operation = {
  operation_key: `iox_${'a'.repeat(32)}`,
  provider_key: 'test.contacts',
  direction: 'import',
  format: 'csv',
  status: 'succeeded',
  input_file_key: `file_${'b'.repeat(32)}`,
  result_file_key: null,
  error_file_key: `file_${'c'.repeat(32)}`,
  task_job_key: `job_${'d'.repeat(32)}`,
  schema_revision: 'contacts.v1',
  mapping: { Name: 'name' },
  processed_rows: 2,
  accepted_rows: 1,
  rejected_rows: 1,
  total_rows: 2,
  revision: 4,
  last_error_code: null,
  retention_until: '2026-08-01T00:00:00.000Z',
  created_at: '2026-07-24T00:00:00.000Z',
  updated_at: '2026-07-24T00:00:01.000Z',
  completed_at: '2026-07-24T00:00:01.000Z',
};

describe('import/export response contracts', () => {
  it('parses exact tenant-safe operation and list shapes', () => {
    expect(parseOperation(operation).rejectedRows).toBe(1);
    expect(
      parseOperationList({
        data: { items: [operation] },
        meta: { request_id: 'req-1', page: 1, page_size: 20, total: 1 },
      }).total
    ).toBe(1);
  });
  it('rejects impossible progress, leaked fields and lifecycle drift', () => {
    expect(() =>
      parseOperation({ ...operation, accepted_rows: 2, rejected_rows: 2 })
    ).toThrow('IMPORT_EXPORT_RESPONSE_INVALID');
    expect(() => parseOperation({ ...operation, raw_row: 'secret' })).toThrow(
      'IMPORT_EXPORT_RESPONSE_INVALID'
    );
    expect(() =>
      parseOperation({
        ...operation,
        status: 'running',
        completed_at: operation.completed_at,
      })
    ).toThrow('IMPORT_EXPORT_RESPONSE_INVALID');
  });
});
