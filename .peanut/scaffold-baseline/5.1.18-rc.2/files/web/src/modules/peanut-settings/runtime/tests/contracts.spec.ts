import { describe, expect, it, vi } from 'vitest';

import {
  createSettingsFetchTransport,
  groupSettingRecords,
  parseSettingRecord,
  parseSettingsList,
  settingEditorKind,
} from '../src/contracts';

const setting = (
  overrides: Record<string, unknown> = {}
): Record<string, unknown> => ({
  module_key: 'example.target',
  setting_key: 'daily-limit',
  name: 'Daily limit',
  description: 'Maximum daily processing limit.',
  schema: { type: 'number', minimum: 0 },
  required: false,
  secret: false,
  configured: true,
  source_scope: 'tenant',
  value: 25,
  effective_at: '2026-07-19T08:00:00.000Z',
  expires_at: null,
  revision: '2',
  etag: '"setting-2"',
  ...overrides,
});

describe('settings response contracts', () => {
  it('strictly parses records and groups them in stable module and key order', () => {
    const records = parseSettingsList({
      data: {
        items: [
          setting({ module_key: 'example.zeta', setting_key: 'zeta-value' }),
          setting({ module_key: 'example.alpha', setting_key: 'second-value' }),
          setting({ module_key: 'example.alpha', setting_key: 'first-value' }),
        ],
      },
    });

    const groups = groupSettingRecords(records);

    expect(groups.map((group) => group.moduleKey)).toEqual([
      'example.alpha',
      'example.zeta',
    ]);
    expect(groups[0]?.definitions.map((record) => record.settingKey)).toEqual([
      'first-value',
      'second-value',
    ]);
    expect(records[0]?.revision).toBe('2');
  });

  it('selects only scalar and enum editors and leaves compound schemas read-only', () => {
    expect(
      settingEditorKind(
        parseSettingRecord(
          setting({ schema: { type: 'boolean' }, value: true })
        )
      )
    ).toBe('boolean');
    expect(
      settingEditorKind(
        parseSettingRecord(setting({ schema: { type: 'integer' }, value: 3 }))
      )
    ).toBe('number');
    expect(
      settingEditorKind(
        parseSettingRecord(
          setting({ schema: { type: 'string' }, value: 'plain' })
        )
      )
    ).toBe('string');
    expect(
      settingEditorKind(
        parseSettingRecord(
          setting({
            schema: { type: 'string', enum: ['low', 'high'] },
            value: 'low',
          })
        )
      )
    ).toBe('enum');
    expect(
      settingEditorKind(
        parseSettingRecord(
          setting({ schema: { type: 'object' }, value: { enabled: true } })
        )
      )
    ).toBe('unsupported');
  });

  it('accepts compound schemas with top-level enums as unsupported read-only records', () => {
    const objectRecord = parseSettingRecord(
      setting({
        schema: { type: 'object', enum: [{ mode: 'safe' }, { mode: 'fast' }] },
        value: { mode: 'safe' },
      })
    );
    const arrayRecord = parseSettingRecord(
      setting({
        setting_key: 'allowed-regions',
        schema: { type: 'array', enum: [['cn'], ['us', 'ca']] },
        value: ['cn'],
      })
    );

    expect(settingEditorKind(objectRecord)).toBe('unsupported');
    expect(settingEditorKind(arrayRecord)).toBe('unsupported');
  });

  it('accepts valid union and null schemas as unsupported read-only records', () => {
    const unionRecord = parseSettingRecord(
      setting({
        schema: { type: ['string', 'null'] },
        value: null,
      })
    );
    const nullRecord = parseSettingRecord(
      setting({
        setting_key: 'empty-marker',
        schema: { type: 'null' },
        value: null,
      })
    );

    expect(settingEditorKind(unionRecord)).toBe('unsupported');
    expect(settingEditorKind(nullRecord)).toBe('unsupported');
    expect(() => parseSettingRecord(setting({ schema: { type: [] } }))).toThrow(
      'SETTINGS_RESPONSE_INVALID'
    );
    expect(() =>
      parseSettingRecord(setting({ schema: { type: ['string', 'string'] } }))
    ).toThrow('SETTINGS_RESPONSE_INVALID');
  });

  it('rejects malformed revisions, ETags, and secret values from admin reads', () => {
    expect(() => parseSettingRecord(setting({ revision: '0' }))).toThrow(
      'SETTINGS_RESPONSE_INVALID'
    );
    expect(() => parseSettingRecord(setting({ etag: 'weak-etag' }))).toThrow(
      'SETTINGS_RESPONSE_INVALID'
    );
    expect(() =>
      parseSettingRecord(
        setting({
          secret: true,
          schema: { type: 'string' },
          value: 'must-not-leak',
        })
      )
    ).toThrow('SETTINGS_SECRET_RESPONSE_EXPOSED');
  });

  it('rejects non-secret scalar values that do not satisfy their declared schema', () => {
    const invalidRecords = [
      setting({ schema: { type: 'boolean' }, value: 'true' }),
      setting({ schema: { type: 'string' }, value: 42 }),
      setting({ schema: { type: 'number' }, value: Number.NaN }),
      setting({ schema: { type: 'number' }, value: Number.POSITIVE_INFINITY }),
      setting({ schema: { type: 'integer' }, value: 1.5 }),
      setting({
        schema: { type: 'string', enum: ['safe', 'fast'] },
        value: 'other',
      }),
    ];

    for (const invalidRecord of invalidRecords) {
      expect(() => parseSettingRecord(invalidRecord)).toThrow(
        'SETTINGS_RESPONSE_INVALID'
      );
    }

    expect(
      parseSettingRecord(setting({ schema: { type: 'boolean' }, value: false }))
        .value
    ).toBe(false);
    expect(
      parseSettingRecord(setting({ schema: { type: 'integer' }, value: 4 }))
        .value
    ).toBe(4);
    expect(
      parseSettingRecord(
        setting({
          schema: { type: 'string', enum: ['safe', 'fast'] },
          value: 'fast',
        })
      ).value
    ).toBe('fast');
  });

  it('accepts scalar null only for a non-required setting with no effective value', () => {
    const unconfigured = parseSettingRecord(
      setting({
        configured: false,
        source_scope: null,
        value: null,
      })
    );

    expect(unconfigured.value).toBeNull();
    expect(() =>
      parseSettingRecord(
        setting({ configured: true, source_scope: 'tenant', value: null })
      )
    ).toThrow('SETTINGS_RESPONSE_INVALID');
    expect(() =>
      parseSettingRecord(
        setting({ configured: false, source_scope: 'deployment', value: null })
      )
    ).toThrow('SETTINGS_RESPONSE_INVALID');
    expect(() =>
      parseSettingRecord(
        setting({
          required: true,
          configured: false,
          source_scope: null,
          value: null,
        })
      )
    ).toThrow('SETTINGS_RESPONSE_INVALID');
  });
});

describe('settings fetch transport', () => {
  it('sends create preconditions and idempotency without leaking values into the URL', async () => {
    const fetcher = vi.fn<(request: Request) => Promise<Response>>(
      async () =>
        new Response(JSON.stringify({ data: setting() }), {
          status: 200,
          headers: {
            'Content-Type': 'application/json',
            'ETag': '"setting-2"',
          },
        })
    );
    const transport = createSettingsFetchTransport({
      baseUrl: 'https://admin.example.test',
      fetch: fetcher,
    });

    await transport.replace('example.target', 'daily-limit', {
      value: 50,
      idempotencyKey: 'idem_create_1',
      precondition: { kind: 'create' },
      signal: new AbortController().signal,
    });

    const request = fetcher.mock.calls[0]?.[0];
    expect(request?.url).toBe(
      'https://admin.example.test/adminapi/api/v1/settings/example.target/daily-limit'
    );
    expect(request?.headers.get('If-None-Match')).toBe('*');
    expect(request?.headers.get('If-Match')).toBeNull();
    expect(request?.headers.get('Idempotency-Key')).toBe('idem_create_1');
    expect(await request?.json()).toEqual({ value: 50 });
    expect(request?.url).not.toContain('50');
  });

  it('sends strong If-Match for replace and unset operations', async () => {
    const fetcher = vi.fn<(request: Request) => Promise<Response>>(
      async () =>
        new Response(JSON.stringify({ data: setting() }), {
          status: 200,
          headers: {
            'Content-Type': 'application/json',
            'ETag': '"setting-3"',
          },
        })
    );
    const transport = createSettingsFetchTransport({
      baseUrl: 'https://admin.example.test/root/',
      fetch: fetcher,
    });
    const signal = new AbortController().signal;

    await transport.replace('example.target', 'daily-limit', {
      value: 51,
      idempotencyKey: 'idem_replace_1',
      precondition: { kind: 'replace', etag: '"setting-2"' },
      signal,
    });
    await transport.unset('example.target', 'daily-limit', {
      idempotencyKey: 'idem_unset_1',
      etag: '"setting-3"',
      signal,
    });

    expect(fetcher.mock.calls[0]?.[0].headers.get('If-Match')).toBe(
      '"setting-2"'
    );
    expect(fetcher.mock.calls[0]?.[0].headers.get('If-None-Match')).toBeNull();
    expect(fetcher.mock.calls[1]?.[0].method).toBe('DELETE');
    expect(fetcher.mock.calls[1]?.[0].headers.get('If-Match')).toBe(
      '"setting-3"'
    );
  });
});
