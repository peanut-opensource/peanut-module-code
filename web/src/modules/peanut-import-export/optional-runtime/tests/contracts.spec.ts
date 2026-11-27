import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { parseErrorReportCsv, parseOperation, parseOperationList } from '../src/contracts';

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

describe('official redacted CSV error row projection', () => {
  it('locates the original CSV line and column without exposing source values', () => {
    const report = readFileSync(new URL('./fixtures/article-draft-errors.csv', import.meta.url), 'utf8');
    expect(parseErrorReportCsv(report)).toEqual({
      rows: [
        { rowNumber: 3, columnKey: 'title', errorCode: 'IMPORT_VALUE_REQUIRED' },
        { rowNumber: 4, columnKey: 'body', errorCode: 'IMPORT_VALUE_TOO_LONG' },
      ],
      truncated: false,
    });
    const truncated = parseErrorReportCsv(report, 1);
    expect(truncated.rows).toHaveLength(1);
    expect(truncated.truncated).toBe(true);
  });

  it('rejects malformed or raw-value reports instead of displaying unsafe content', () => {
    for (const report of [
      '<html>session login</html>',
      'row_number,column_key,error_code\n3,title,=HYPERLINK',
      'row_number,column_key,error_code\n1,title,IMPORT_VALUE_REQUIRED',
      'row_number,column_key,error_code\n3,title,IMPORT_VALUE_REQUIRED,private-record',
      'row_number,column_key,error_code\n3,raw value,IMPORT_VALUE_REQUIRED',
    ])
      expect(() => parseErrorReportCsv(report)).toThrow('IMPORT_EXPORT_ERROR_REPORT_INVALID');
  });
});
