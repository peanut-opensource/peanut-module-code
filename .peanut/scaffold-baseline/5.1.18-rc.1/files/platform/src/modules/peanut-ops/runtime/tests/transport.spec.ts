import { describe, expect, it, vi } from 'vitest';
import { createOpsConsoleFetchTransport } from '../src/contracts';

describe('ops-console transport', () => {
  it('uses fixed endpoints, exact public fields, and idempotency/revision headers', async () => {
    const requests: Request[] = [];
    const transport = createOpsConsoleFetchTransport({
      baseUrl: 'https://admin.example/',
      fetch: vi.fn(async (request) => {
        requests.push(request);
        return new Response(
          JSON.stringify({ data: null, meta: { request_id: 'req' } }),
          { status: 200, headers: { 'Content-Type': 'application/json' } }
        );
      }),
    });
    const signal = new AbortController().signal;
    await transport.submitRestore(
      'reference.mysql',
      'backup_12345678',
      'verification',
      'restore-request-0001',
      signal
    );
    await transport.scheduleMaintenance(
      {
        reasonKey: 'upgrade',
        startsAt: '2026-07-24T03:00:00.000Z',
        endsAt: '2026-07-24T04:00:00.000Z',
      },
      4,
      'maintenance-request-0001',
      signal
    );
    expect(requests[0]?.url).toBe(
      'https://admin.example/api/platform/v1/ops/tasks/restore'
    );
    expect(requests[0]?.headers.get('Idempotency-Key')).toBe(
      'restore-request-0001'
    );
    expect(await requests[0]?.json()).toEqual({
      provider_key: 'reference.mysql',
      backup_reference_key: 'backup_12345678',
      target_key: 'verification',
    });
    expect(requests[1]?.headers.get('If-Match')).toBe('"rev-4"');
    const encoded = JSON.stringify(await requests[1]?.json());
    expect(encoded).not.toMatch(
      /command|handler|path|sql|dsn|credential|password|stack|raw/i
    );
  });
});
