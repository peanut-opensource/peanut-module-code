import { describe, expect, it, vi } from 'vitest';

import {
  createReferenceCodesFetchTransport,
  normalizeReferenceCodeInstant,
  parseReferenceCode,
  parseReferenceCodeList,
  parseReferenceCodeSets,
} from '../src/contracts';

const entry = (
  overrides: Record<string, unknown> = {}
): Record<string, unknown> => ({
  module_key: 'example.catalog',
  set_key: 'service-level',
  code: 'standard',
  lifecycle: 'active',
  revision: 2,
  etag: '"rev-2"',
  effective: {
    revision: 2,
    label: 'Standard',
    metadata: { visible: true, weight: 1, note: null },
    status: 'active',
    sort_order: 10,
    effective_at: '2026-07-20T00:00:00.000Z',
    expires_at: null,
  },
  created_at: '2026-07-20T00:00:00.000Z',
  updated_at: '2026-07-20T01:00:00.000Z',
  retired_at: null,
  ...overrides,
});

const success = (
  data: unknown,
  requestId = 'req_reference_codes'
): Record<string, unknown> => ({
  data,
  meta: { request_id: requestId },
});

describe('reference-code response contracts', () => {
  it('parses exact set, entry, and paginated list shapes', () => {
    const sets = parseReferenceCodeSets(
      success({
        items: [
          {
            module_key: 'example.catalog',
            set_key: 'service-level',
            name: 'Service level',
            description: 'Generic service levels.',
            definition_revision: 3,
          },
        ],
      })
    );
    const parsedEntry = parseReferenceCode(success(entry()), '"rev-2"');
    const list = parseReferenceCodeList(
      success({
        items: [entry()],
        as_of: '2026-07-20T02:00:00.000Z',
        page: 1,
        page_size: 50,
        total: 1,
      })
    );

    expect(sets[0]).toEqual({
      moduleKey: 'example.catalog',
      setKey: 'service-level',
      name: 'Service level',
      description: 'Generic service levels.',
      definitionRevision: 3,
    });
    expect(parsedEntry).toMatchObject({
      code: 'standard',
      etag: '"rev-2"',
      revision: 2,
    });
    expect(list).toMatchObject({
      asOf: '2026-07-20T02:00:00.000Z',
      page: 1,
      pageSize: 50,
      total: 1,
    });
  });

  it('parses retirement lifecycle relative to the requested snapshot', () => {
    const retiredAt = '2026-07-20T03:00:00.000Z';
    const historicalEntry = entry({ retired_at: retiredAt });
    const historical = parseReferenceCodeList(
      success({
        items: [historicalEntry],
        as_of: '2026-07-20T02:00:00.000Z',
        page: 1,
        page_size: 50,
        total: 1,
      })
    );

    expect(historical.items[0]).toMatchObject({
      lifecycle: 'active',
      retiredAt,
    });
    expect(parseReferenceCode(success(historicalEntry))).toMatchObject({
      lifecycle: 'active',
      retiredAt,
    });
    expect(() =>
      parseReferenceCode(success(entry({ lifecycle: 'retired' })))
    ).toThrow('REFERENCE_CODES_RESPONSE_INVALID');

    for (const inconsistent of [
      entry({ retired_at: '2026-07-20T01:00:00.000Z' }),
      entry({ lifecycle: 'retired', retired_at: retiredAt }),
    ]) {
      expect(() =>
        parseReferenceCodeList(
          success({
            items: [inconsistent],
            as_of: '2026-07-20T02:00:00.000Z',
            page: 1,
            page_size: 50,
            total: 1,
          })
        )
      ).toThrow('REFERENCE_CODES_RESPONSE_INVALID');
    }
  });

  it('fails closed on unknown fields, invalid scalar metadata, timestamps, and ETag mismatch', () => {
    const setData = {
      items: [
        {
          module_key: 'example.catalog',
          set_key: 'service-level',
          name: 'Service level',
          description: 'Generic service levels.',
          definition_revision: 1,
        },
      ],
    };
    for (const malformedEnvelope of [
      { data: setData },
      { data: setData, meta: {} },
      { data: setData, meta: { request_id: '' } },
      { data: setData, meta: { request_id: 'req_valid', extra: true } },
      { data: setData, meta: { request_id: 'req_valid' }, extra: true },
    ]) {
      expect(() => parseReferenceCodeSets(malformedEnvelope)).toThrow(
        'REFERENCE_CODES_RESPONSE_INVALID'
      );
    }
    expect(() =>
      parseReferenceCodeSets(
        success({
          items: [
            {
              module_key: 'example.catalog',
              set_key: 'service-level',
              name: 'Service level',
              description: 'Generic service levels.',
              definition_revision: 1,
              tenant_id: 'forbidden',
            },
          ],
        })
      )
    ).toThrow('REFERENCE_CODES_RESPONSE_INVALID');
    expect(() =>
      parseReferenceCode(
        success(
          entry({
            effective: {
              ...(entry().effective as Record<string, unknown>),
              metadata: { nested: { forbidden: true } },
            },
          })
        ),
        '"rev-2"'
      )
    ).toThrow('REFERENCE_CODES_RESPONSE_INVALID');
    expect(() =>
      parseReferenceCode(
        success(entry({ updated_at: '2026-07-20T01:00:00Z' })),
        '"rev-2"'
      )
    ).toThrow('REFERENCE_CODES_RESPONSE_INVALID');
    expect(() => parseReferenceCode(success(entry({ revision: 3 })))).toThrow(
      'REFERENCE_CODES_RESPONSE_INVALID'
    );
    expect(() => parseReferenceCode(success(entry()), '"rev-3"')).toThrow(
      'REFERENCE_CODES_RESPONSE_ETAG_MISMATCH'
    );

    expect(normalizeReferenceCodeInstant('2026-07-20T08:30:45.123+08:00')).toBe(
      '2026-07-20T00:30:45.123Z'
    );
    for (const invalidInstant of [
      '2026-02-30T00:00:00.000Z',
      '2026-07-20T24:00:00.000Z',
      '2026-07-20T12:60:00.000Z',
      '2026-07-20T12:00:60.000Z',
    ]) {
      expect(() => normalizeReferenceCodeInstant(invalidInstant)).toThrow(
        'REFERENCE_CODES_INSTANT_INVALID'
      );
    }
  });
});

describe('reference-code fetch transport', () => {
  it('encodes owner/set selectors once and sends only the fixed list query', async () => {
    const fetcher = vi.fn<(request: Request) => Promise<Response>>(
      async () => new Response('{}')
    );
    const transport = createReferenceCodesFetchTransport({
      baseUrl: 'https://admin.example.test/root/',
      fetch: fetcher,
    });

    await transport.listCodes(
      'example.catalog',
      'service-level',
      {
        asOf: '2026-07-20T02:00:00.000Z',
        effectiveStatus: 'inactive',
        includeRetired: true,
        page: 2,
        pageSize: 25,
      },
      new AbortController().signal
    );

    const request = fetcher.mock.calls[0]?.[0];
    const url = new URL(request?.url ?? '');
    expect(url.pathname).toBe(
      // The adopted generated SDK composes paths with the configured API base.
      '/root/adminapi/api/v1/reference-code-sets/example.catalog/service-level/codes'
    );
    expect(Object.fromEntries(url.searchParams)).toEqual({
      as_of: '2026-07-20T02:00:00.000Z',
      effective_status: 'inactive',
      include_retired: 'true',
      page: '2',
      page_size: '25',
    });
    expect(url.search).not.toContain('tenant');
  });

  it('sends exact create, append-version, and retire preconditions without Tenant input', async () => {
    const fetcher = vi.fn<(request: Request) => Promise<Response>>(
      async () => new Response('{}')
    );
    const transport = createReferenceCodesFetchTransport({
      baseUrl: 'https://admin.example.test',
      fetch: fetcher,
    });
    const signal = new AbortController().signal;
    const version = {
      label: 'Standard',
      metadata: { visible: true },
      status: 'active' as const,
      sortOrder: 10,
      effectiveAt: '2026-07-20T00:00:00.000Z',
      expiresAt: null,
    };

    await transport.create('example.catalog', 'service-level', {
      input: { code: 'standard', ...version },
      idempotencyKey: 'idem_create_0001',
      signal,
    });
    await transport.replace('example.catalog', 'service-level', 'standard', {
      input: version,
      etag: '"rev-1"',
      idempotencyKey: 'idem_replace_0001',
      signal,
    });
    await transport.retire('example.catalog', 'service-level', 'standard', {
      etag: '"rev-2"',
      idempotencyKey: 'idem_retire_0001',
      signal,
    });

    const [createRequest, replaceRequest, retireRequest] =
      fetcher.mock.calls.map((call) => call[0]);
    expect(createRequest?.headers.get('If-None-Match')).toBe('*');
    expect(createRequest?.headers.get('Idempotency-Key')).toBe(
      'idem_create_0001'
    );
    expect(await createRequest?.json()).toEqual({
      code: 'standard',
      label: 'Standard',
      metadata: { visible: true },
      status: 'active',
      sort_order: 10,
      effective_at: '2026-07-20T00:00:00.000Z',
      expires_at: null,
    });
    expect(replaceRequest?.method).toBe('PUT');
    expect(replaceRequest?.headers.get('If-Match')).toBe('"rev-1"');
    expect(await replaceRequest?.json()).not.toHaveProperty('code');
    expect(retireRequest?.method).toBe('DELETE');
    expect(retireRequest?.headers.get('If-Match')).toBe('"rev-2"');
    expect(await retireRequest?.text()).toBe('');
    expect(
      fetcher.mock.calls.map((call) => call[0].url).join(' ')
    ).not.toContain('tenant');
  });
});
