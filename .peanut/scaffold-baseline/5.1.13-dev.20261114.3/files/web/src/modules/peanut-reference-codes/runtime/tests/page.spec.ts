// @vitest-environment happy-dom

import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { createI18n } from 'vue-i18n';
import enUS from '../../locale/en-US';

import ReferenceCodesPage from '../src/ReferenceCodesPage.vue';
import contribution from '../../contribution';
import RuntimePage from '../../RuntimePage.vue';
import { defineComponent, inject } from 'vue';
import type { ReferenceCodesRuntime } from '../src/runtime';
vi.mock('@/hooks/permission', () => ({ hasPermission: () => false }));
vi.mock('@/utils/auth', () => ({ getToken: () => null }));
vi.mock('@/router/routes/base', () => ({ DEFAULT_LAYOUT: {} }));
import {
  createReferenceCodesRuntime,
  REFERENCE_CODES_MODULE_KEY,
  REFERENCE_CODES_READ_PERMISSION,
  referenceCodesRuntimeKey,
} from '../src/runtime';
import type {
  ReferenceCodesTransport,
  ReferenceCodesTransportResult,
} from '../src/contracts';

const setSummary = {
  module_key: 'example.catalog',
  set_key: 'service-level',
  name: 'Service level',
  description: 'Generic service levels.',
  definition_revision: 1,
};

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
    metadata: {},
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

const result = (
  body: unknown,
  status = 200,
  headers: Record<string, string> = {}
): ReferenceCodesTransportResult => ({
  body,
  status,
  headers: new Headers(headers),
});

const success = (
  data: unknown,
  requestId = 'req_reference_codes'
): Record<string, unknown> => ({
  data,
  meta: { request_id: requestId },
});

const listResult = (
  ...items: Record<string, unknown>[]
): ReferenceCodesTransportResult =>
  result(
    success({
      items,
      as_of: '2026-07-20T02:00:00.000Z',
      page: 1,
      page_size: 50,
      total: items.length,
    })
  );

const filteredListResult = (
  items: Record<string, unknown>[],
  query: { asOf: string; page: number; pageSize: number }
): ReferenceCodesTransportResult =>
  result(
    success({
      items,
      as_of: query.asOf,
      page: query.page,
      page_size: query.pageSize,
      total: items.length,
    })
  );

const createTransport = (): ReferenceCodesTransport => ({
  listSets: vi.fn(async () => result(success({ items: [setSummary] }))),
  listCodes: vi.fn(async () => listResult(entry())),
  getCode: vi.fn(async () =>
    result(success(entry({ revision: 3, etag: '"rev-3"' })), 200, {
      ETag: '"rev-3"',
    })
  ),
  create: vi.fn(async () =>
    result(
      success(
        entry({
          code: 'priority',
          revision: 1,
          etag: '"rev-1"',
          effective: {
            ...(entry().effective as Record<string, unknown>),
            revision: 1,
          },
        })
      ),
      201,
      { ETag: '"rev-1"' }
    )
  ),
  replace: vi.fn(async () =>
    result(success(entry({ revision: 3, etag: '"rev-3"' })), 200, {
      ETag: '"rev-3"',
    })
  ),
  retire: vi.fn(async () =>
    result(
      success(
        entry({
          lifecycle: 'retired',
          revision: 4,
          etag: '"rev-4"',
          retired_at: '2026-07-20T02:00:00.000Z',
        })
      ),
      200,
      { ETag: '"rev-4"' }
    )
  ),
});

it('the adopted reference-code host provides a per-page runtime and disposes it on unmount', () => {
  const observed: { runtime?: ReferenceCodesRuntime } = {};
  const child = defineComponent({
    setup() {
      const runtime = inject(referenceCodesRuntimeKey);
      if (!runtime) throw new Error('REFERENCE_CODES_RUNTIME_NOT_PROVIDED');
      observed.runtime = runtime;
      return () => null;
    },
  });
  const wrapper = mount(RuntimePage, {
    global: { stubs: { ReferenceCodesPage: child } },
  });
  const runtime = observed.runtime;
  if (!runtime) throw new Error('REFERENCE_CODES_RUNTIME_NOT_PROVIDED');
  runtime.state.loading = true;
  wrapper.unmount();
  expect(runtime.state.loading).toBe(false);
  expect(runtime.state.requests.size).toBe(0);
});

describe('reference-code Tenant page', () => {
  it('exports one Tenant-only read-guarded contribution and fails closed before reads', async () => {
    const transport = createTransport();
    const runtime = createReferenceCodesRuntime({
      transport,
      canRead: () => false,
      canManage: () => false,
      now: () => '2026-07-20T02:00:00.000Z',
    });

    await expect(runtime.loadSets()).rejects.toThrow(
      'REFERENCE_CODES_READ_FORBIDDEN'
    );
    expect(transport.listSets).not.toHaveBeenCalled();
    expect(contribution.moduleKey).toBe(REFERENCE_CODES_MODULE_KEY);
    expect(contribution.routes).toHaveLength(1);
    expect(contribution.routes[0]).toMatchObject({
      path: '/system',
      meta: {
        requiresAuth: true,
        tenantModuleKey: REFERENCE_CODES_MODULE_KEY,
        requiredPermissions: REFERENCE_CODES_READ_PERMISSION,
      },
      children: [
        {
          path: 'reference-codes',
          meta: {
            requiresAuth: true,
            tenantModuleKey: REFERENCE_CODES_MODULE_KEY,
            requiredPermissions: REFERENCE_CODES_READ_PERMISSION,
          },
        },
      ],
    });
    expect(
      contribution.routes.some((route) => route.path.startsWith('/platform/'))
    ).toBe(false);

    const invalidSuccessTransport = createTransport();
    vi.mocked(invalidSuccessTransport.listSets).mockResolvedValueOnce(
      result(success({ items: [setSummary] }), 201)
    );
    const invalidSuccessRuntime = createReferenceCodesRuntime({
      transport: invalidSuccessTransport,
      canRead: () => true,
      canManage: () => true,
      now: () => '2026-07-20T02:00:00.000Z',
    });
    await invalidSuccessRuntime.loadSets();
    expect(invalidSuccessRuntime.state.errors.page).toMatchObject({
      kind: 'protocol',
      status: 201,
    });
  });

  it('drives owner/set, as-of, create, append-version, and retire operations', async () => {
    const transport = createTransport();
    const priority = entry({
      code: 'priority',
      revision: 1,
      etag: '"rev-1"',
      effective: {
        ...(entry().effective as Record<string, unknown>),
        revision: 1,
        label: 'Priority',
        sort_order: 5,
      },
    });
    const standardV3 = entry({ revision: 3, etag: '"rev-3"' });
    const retiredStandard = entry({
      lifecycle: 'retired',
      revision: 4,
      etag: '"rev-4"',
      retired_at: '2026-07-20T02:00:00.000Z',
    });
    vi.mocked(transport.listCodes)
      .mockResolvedValueOnce(listResult(entry()))
      .mockResolvedValueOnce(listResult(priority, entry()))
      .mockResolvedValueOnce(listResult(priority, standardV3))
      .mockResolvedValueOnce(listResult(priority, retiredStandard));
    const runtime = createReferenceCodesRuntime({
      transport,
      canRead: () => true,
      canManage: () => true,
      createIdempotencyKey: () => 'idem_reference_codes_0001',
      now: () => '2026-07-20T02:00:00.000Z',
    });
    const wrapper = mount(ReferenceCodesPage, {
      global: {
        plugins: [
          createI18n({
            legacy: false,
            locale: 'en-US',
            messages: { 'en-US': enUS },
          }),
        ],
        provide: { [referenceCodesRuntimeKey as symbol]: runtime },
      },
    });
    await flushPromises();

    await runtime.selectSet('example.catalog', 'service-level');
    runtime.beginCreate();
    runtime.beginRetire(
      runtime.state.entries.find((candidate) => candidate.code === 'standard')!
    );
    expect(runtime.state.createDraft).toBeNull();
    expect(runtime.state.retireCode).toBe('standard');
    runtime.cancelRetire();
    runtime.beginCreate();
    runtime.updateCreateDraft({
      code: 'priority',
      label: 'Priority',
      metadataText: '{"rank":1}',
      status: 'active',
      sortOrder: 5,
      effectiveAt: '2026-07-21T00:00:00.000Z',
      expiresAt: null,
    });
    await runtime.create();
    runtime.beginAppend(
      runtime.state.entries.find((candidate) => candidate.code === 'standard')!
    );
    runtime.updateAppendDraft({ label: 'Standard v3' });
    await runtime.appendVersion();
    runtime.beginRetire(
      runtime.state.entries.find((candidate) => candidate.code === 'standard')!
    );
    await runtime.retire();

    expect(transport.listCodes).toHaveBeenCalledWith(
      'example.catalog',
      'service-level',
      expect.objectContaining({
        asOf: '2026-07-20T02:00:00.000Z',
        page: 1,
        pageSize: 50,
      }),
      expect.any(AbortSignal)
    );
    expect(transport.create).toHaveBeenCalledWith(
      'example.catalog',
      'service-level',
      expect.objectContaining({
        input: expect.objectContaining({
          code: 'priority',
          metadata: { rank: 1 },
        }),
      })
    );
    expect(transport.replace).toHaveBeenCalledWith(
      'example.catalog',
      'service-level',
      'standard',
      expect.objectContaining({
        etag: '"rev-2"',
        input: expect.not.objectContaining({ code: expect.anything() }),
      })
    );
    expect(transport.retire).toHaveBeenCalledWith(
      'example.catalog',
      'service-level',
      'standard',
      expect.objectContaining({
        etag: '"rev-3"',
      })
    );
    expect(wrapper.text()).toContain('Reference codes');
  });

  it('keeps append input on 412 and reloads the stale identity explicitly', async () => {
    const transport = createTransport();
    vi.mocked(transport.create).mockResolvedValueOnce(
      result(
        {
          type: '/docs/problems/precondition-failed',
          title: 'Precondition failed',
          status: 412,
          detail: 'The reference code already exists.',
          code: 'REFERENCE_CODE_ALREADY_EXISTS',
          request_id: 'req_existing_code',
        },
        412
      )
    );
    vi.mocked(transport.replace).mockResolvedValueOnce(
      result(
        {
          type: '/docs/problems/precondition-failed',
          title: 'Precondition failed',
          status: 412,
          detail: 'The reference code changed.',
          code: 'REFERENCE_CODE_REVISION_MISMATCH',
          request_id: 'req_stale_code',
        },
        412
      )
    );
    const runtime = createReferenceCodesRuntime({
      transport,
      canRead: () => true,
      canManage: () => true,
      now: () => '2026-07-20T02:00:00.000Z',
    });
    await runtime.loadSets();
    await runtime.selectSet('example.catalog', 'service-level');
    runtime.beginCreate();
    runtime.updateCreateDraft({
      code: 'existing-code',
      label: 'Unsaved create input',
    });
    await runtime.create();
    const wrapper = mount(ReferenceCodesPage, {
      global: {
        plugins: [
          createI18n({
            legacy: false,
            locale: 'en-US',
            messages: { 'en-US': enUS },
          }),
        ],
        provide: { [referenceCodesRuntimeKey as symbol]: runtime },
      },
    });
    await flushPromises();

    expect(runtime.state.createDraft?.label).toBe('Unsaved create input');
    expect(wrapper.text()).toContain('The reference code already exists.');
    expect(wrapper.get('[data-reference-create-reload]').text()).toContain(
      'Reload entries'
    );
    runtime.cancelCreate();
    runtime.beginAppend(runtime.state.entries[0]!);
    runtime.updateAppendDraft({ label: 'Unsaved operator input' });

    await runtime.appendVersion();

    expect(runtime.state.appendDraft?.label).toBe('Unsaved operator input');
    expect(runtime.state.stale.standard?.requestId).toBe('req_stale_code');
    expect(transport.getCode).not.toHaveBeenCalled();

    await runtime.reloadStale('standard');

    expect(transport.getCode).toHaveBeenCalledWith(
      'example.catalog',
      'service-level',
      'standard',
      '2026-07-20T02:00:00.000Z',
      expect.any(AbortSignal)
    );
    expect(runtime.state.appendDraft).toMatchObject({
      label: 'Unsaved operator input',
      etag: '"rev-3"',
    });
    expect(runtime.state.stale.standard).toBeUndefined();
    wrapper.unmount();
  });

  it('aborts requests and clears every Tenant-owned view state on disposal', async () => {
    let listSignal: AbortSignal | undefined;
    const transport = createTransport();
    vi.mocked(transport.listCodes).mockImplementationOnce(
      async (_moduleKey, _setKey, _query, signal) => {
        listSignal = signal;
        return new Promise<ReferenceCodesTransportResult>(() => undefined);
      }
    );
    const runtime = createReferenceCodesRuntime({
      transport,
      canRead: () => true,
      canManage: () => true,
      now: () => '2026-07-20T02:00:00.000Z',
    });
    await runtime.loadSets();
    const pending = runtime.selectSet('example.catalog', 'service-level');
    runtime.beginCreate();
    runtime.updateCreateDraft({ code: 'draft-code', label: 'Draft label' });
    runtime.state.errors.page = {
      kind: 'transport',
      message: 'old error',
      requestId: null,
      status: null,
    };

    runtime.dispose();

    expect(listSignal?.aborted).toBe(true);
    expect(runtime.state).toMatchObject({
      sets: [],
      selectedSet: null,
      asOf: '',
      entries: [],
      page: 1,
      pageSize: 50,
      total: 0,
      createDraft: null,
      appendDraft: null,
      retireCode: null,
      errors: {},
      stale: {},
      loading: false,
    });
    expect(runtime.state.requests.size).toBe(0);
    await expect(
      Promise.race([pending, Promise.resolve()])
    ).resolves.toBeUndefined();
  });

  it('reuses create retry keys and resets them on edit, cancel, and success', async () => {
    const transport = createTransport();
    const nextKey = vi
      .fn()
      .mockReturnValueOnce('idem_create_0001')
      .mockReturnValueOnce('idem_create_0002')
      .mockReturnValueOnce('idem_create_0003')
      .mockReturnValueOnce('idem_create_0004');
    vi.mocked(transport.create)
      .mockRejectedValueOnce(new Error('network failed'))
      .mockResolvedValueOnce(
        result({ detail: 'unavailable', request_id: 'req_503' }, 503)
      )
      .mockResolvedValueOnce(
        result({ detail: 'invalid', request_id: 'req_422' }, 422)
      )
      .mockRejectedValueOnce(
        new Error('network failed after unchanged 422 retry')
      )
      .mockRejectedValueOnce(new Error('network failed after edit'))
      .mockRejectedValueOnce(new Error('network failed after cancel'))
      .mockResolvedValueOnce(
        result(
          success(entry({ code: 'retry-code', revision: 1, etag: '"rev-1"' })),
          201,
          {
            ETag: '"rev-1"',
          }
        )
      )
      .mockRejectedValueOnce(new Error('network failed after success'));
    const runtime = createReferenceCodesRuntime({
      transport,
      canRead: () => true,
      canManage: () => true,
      createIdempotencyKey: nextKey,
      now: () => '2026-07-20T02:00:00.000Z',
    });
    await runtime.loadSets();
    await runtime.selectSet('example.catalog', 'service-level');
    runtime.beginCreate();
    runtime.updateCreateDraft({ code: 'retry-code', label: 'Retry label' });

    await runtime.create();
    await runtime.create();
    await runtime.create();
    await runtime.create();
    runtime.updateCreateDraft({ label: 'Changed retry label' });
    await runtime.create();
    runtime.cancelCreate();
    runtime.beginCreate();
    runtime.updateCreateDraft({
      code: 'retry-code',
      label: 'Changed retry label',
    });
    await runtime.create();
    await runtime.create();
    runtime.beginCreate();
    runtime.updateCreateDraft({
      code: 'retry-code',
      label: 'Changed retry label',
    });
    await runtime.create();

    const keys = vi
      .mocked(transport.create)
      .mock.calls.map((call) => call[2].idempotencyKey);
    expect(keys[0]).toBe(keys[1]);
    expect(keys[1]).toBe(keys[2]);
    expect(keys[2]).toBe(keys[3]);
    expect(keys[4]).not.toBe(keys[3]);
    expect(keys[5]).not.toBe(keys[4]);
    expect(keys[6]).toBe(keys[5]);
    expect(keys[7]).not.toBe(keys[6]);
  });

  it('reuses replace and retire keys until edit, cancel, success, or stale reload', async () => {
    const transport = createTransport();
    const nextKey = vi
      .fn()
      .mockReturnValueOnce('idem_mutation_0001')
      .mockReturnValueOnce('idem_mutation_0002')
      .mockReturnValueOnce('idem_mutation_0003')
      .mockReturnValueOnce('idem_mutation_0004')
      .mockReturnValueOnce('idem_mutation_0005')
      .mockReturnValueOnce('idem_mutation_0006')
      .mockReturnValueOnce('idem_mutation_0007');
    vi.mocked(transport.replace)
      .mockRejectedValueOnce(new Error('network failed'))
      .mockResolvedValueOnce(
        result({ detail: 'unavailable', request_id: 'req_replace_503' }, 503)
      )
      .mockResolvedValueOnce(
        result({ detail: 'stale', request_id: 'req_replace_412' }, 412)
      )
      .mockRejectedValueOnce(new Error('network failed after stale reload'))
      .mockRejectedValueOnce(new Error('network failed after edit'))
      .mockResolvedValueOnce(
        result(success(entry({ revision: 4, etag: '"rev-4"' })), 200, {
          ETag: '"rev-4"',
        })
      )
      .mockRejectedValueOnce(new Error('network failed after replace success'));
    vi.mocked(transport.retire)
      .mockRejectedValueOnce(new Error('network failed'))
      .mockResolvedValueOnce(
        result({ detail: 'unavailable', request_id: 'req_retire_503' }, 503)
      )
      .mockResolvedValueOnce(
        result(
          success(
            entry({
              lifecycle: 'retired',
              revision: 4,
              etag: '"rev-4"',
              retired_at: '2026-07-20T02:00:00.000Z',
            })
          ),
          200,
          { ETag: '"rev-4"' }
        )
      )
      .mockRejectedValueOnce(new Error('network failed after retire success'))
      .mockRejectedValueOnce(new Error('network failed after cancel'));
    const runtime = createReferenceCodesRuntime({
      transport,
      canRead: () => true,
      canManage: () => true,
      createIdempotencyKey: nextKey,
      now: () => '2026-07-20T02:00:00.000Z',
    });
    await runtime.loadSets();
    await runtime.selectSet('example.catalog', 'service-level');
    runtime.beginAppend(runtime.state.entries[0]!);
    runtime.updateAppendDraft({ label: 'Retry version' });

    await runtime.appendVersion();
    await runtime.appendVersion();
    await runtime.appendVersion();
    await runtime.reloadStale('standard');
    await runtime.appendVersion();
    runtime.updateAppendDraft({ label: 'Changed version' });
    await runtime.appendVersion();
    await runtime.appendVersion();
    runtime.beginAppend(runtime.state.entries[0]!);
    runtime.updateAppendDraft({ label: 'Version after success' });
    await runtime.appendVersion();

    const replaceKeys = vi
      .mocked(transport.replace)
      .mock.calls.map((call) => call[3].idempotencyKey);
    expect(replaceKeys[0]).toBe(replaceKeys[1]);
    expect(replaceKeys[1]).toBe(replaceKeys[2]);
    expect(replaceKeys[3]).not.toBe(replaceKeys[2]);
    expect(replaceKeys[4]).not.toBe(replaceKeys[3]);
    expect(replaceKeys[5]).toBe(replaceKeys[4]);
    expect(replaceKeys[6]).not.toBe(replaceKeys[5]);

    runtime.cancelAppend();
    runtime.beginRetire(runtime.state.entries[0]!);
    await runtime.retire();
    await runtime.retire();
    await runtime.retire();
    runtime.beginRetire(runtime.state.entries[0]!);
    await runtime.retire();
    runtime.cancelRetire();
    runtime.beginRetire(runtime.state.entries[0]!);
    await runtime.retire();

    const retireKeys = vi
      .mocked(transport.retire)
      .mock.calls.map((call) => call[3].idempotencyKey);
    expect(retireKeys[0]).toBe(retireKeys[1]);
    expect(retireKeys[1]).toBe(retireKeys[2]);
    expect(retireKeys[3]).not.toBe(retireKeys[2]);
    expect(retireKeys[4]).not.toBe(retireKeys[3]);
  });

  it('advances create while preserving historical replace and retire filters', async () => {
    const transport = createTransport();
    vi.mocked(transport.create).mockResolvedValueOnce(
      result(
        success(
          entry({
            code: 'filtered-code',
            revision: 1,
            etag: '"rev-1"',
            effective: {
              ...(entry().effective as Record<string, unknown>),
              revision: 1,
            },
          })
        ),
        201,
        { ETag: '"rev-1"' }
      )
    );
    vi.mocked(transport.listCodes).mockImplementation(
      async (_moduleKey, _setKey, query) =>
        query.page === 2
          ? filteredListResult([], {
              asOf: query.asOf,
              page: query.page,
              pageSize: query.pageSize,
            })
          : filteredListResult([entry()], {
              asOf: query.asOf,
              page: query.page,
              pageSize: query.pageSize,
            })
    );
    const runtime = createReferenceCodesRuntime({
      transport,
      canRead: () => true,
      canManage: () => true,
      now: () => '2026-07-20T02:00:00.000Z',
    });
    const historicalQuery = {
      asOf: '2020-01-01T00:00:00.000Z',
      page: 2,
      pageSize: 50,
    };
    const prepareFilteredPage = async (): Promise<void> => {
      await runtime.selectSet('example.catalog', 'service-level');
      runtime.state.asOf = historicalQuery.asOf;
      runtime.state.effectiveStatus = 'inactive';
      runtime.state.includeRetired = false;
      runtime.state.page = historicalQuery.page;
    };
    await runtime.loadSets();

    await prepareFilteredPage();
    runtime.beginCreate();
    runtime.updateCreateDraft({
      code: 'filtered-code',
      label: 'Filtered create',
    });
    await runtime.create();
    expect(runtime.state.entries).toEqual([]);

    await prepareFilteredPage();
    runtime.beginAppend(runtime.state.entries[0]!);
    runtime.updateAppendDraft({ label: 'Filtered replace' });
    await runtime.appendVersion();
    expect(runtime.state.entries).toEqual([]);

    await prepareFilteredPage();
    runtime.beginRetire(runtime.state.entries[0]!);
    await runtime.retire();
    expect(runtime.state.entries).toEqual([]);

    const filteredCalls = vi
      .mocked(transport.listCodes)
      .mock.calls.filter((call) => call[2].page === 2);
    expect(filteredCalls).toHaveLength(3);
    const expectedFilters = {
      effectiveStatus: 'inactive',
      includeRetired: false,
      page: 2,
      pageSize: 50,
    };
    expect(filteredCalls[0]?.[2]).toMatchObject({
      ...expectedFilters,
      asOf: '2026-07-20T00:00:00.000Z',
    });
    for (const call of filteredCalls.slice(1)) {
      expect(call[2]).toMatchObject({
        ...expectedFilters,
        asOf: historicalQuery.asOf,
      });
    }
  });
});
