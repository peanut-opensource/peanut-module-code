// @vitest-environment happy-dom

import { flushPromises, mount } from '@vue/test-utils';
import {
  ForbiddenState as ForbiddenStateComponent,
  ModuleUnavailableState as ModuleUnavailableStateComponent,
  SessionExpiredState as SessionExpiredStateComponent,
} from '@peanut-admin/ui-vue';
import { describe, expect, it, vi } from 'vitest';
import { createI18n } from 'vue-i18n';
import enUS from '../../locale/en-US';
import { nextTick, defineComponent, inject, type Component } from 'vue';
// Keep the actual component identity without recursively instantiating Vue's
// entire public-instance type in every test-utils selector overload.
const ForbiddenState: Component = ForbiddenStateComponent;
const ModuleUnavailableState: Component = ModuleUnavailableStateComponent;
const SessionExpiredState: Component = SessionExpiredStateComponent;

import SettingsPage from '../src/SettingsPage.vue';
import contribution from '../../contribution';
import RuntimePage from '../../RuntimePage.vue';
import type { SettingsRuntime } from '../src/runtime';
vi.mock('@/hooks/permission', () => ({ hasPermission: () => false }));
vi.mock('@/utils/auth', () => ({ getToken: () => null }));
vi.mock('@/router/routes/base', () => ({ DEFAULT_LAYOUT: {} }));
import { createSettingsRuntime, settingsRuntimeKey } from '../src/runtime';
import type {
  SettingsTransport,
  SettingsTransportResult,
} from '../src/runtime';

interface Deferred<T> {
  promise: Promise<T>;
  reject: (reason?: unknown) => void;
  resolve: (value: T) => void;
}

const deferred = <T>(): Deferred<T> => {
  let reject!: (reason?: unknown) => void;
  let resolve!: (value: T) => void;
  const promise = new Promise<T>((promiseResolve, promiseReject) => {
    reject = promiseReject;
    resolve = promiseResolve;
  });
  return { promise, reject, resolve };
};

const record = (
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

const result = (
  body: unknown,
  status = 200,
  headers: Record<string, string> = {}
): SettingsTransportResult => ({
  body,
  status,
  headers: new Headers(headers),
});

const listResult = (
  ...records: Record<string, unknown>[]
): SettingsTransportResult =>
  result(
    {
      data: { items: records },
    },
    200,
    { ETag: '"settings-collection-1"' }
  );

const createTransport = (
  list: SettingsTransport['list'] = vi.fn(async () => listResult(record()))
): SettingsTransport => ({
  list,
  replace: vi.fn(async () =>
    result({ data: record({ revision: '3', value: 30 }) }, 200, {
      ETag: '"setting-3"',
    })
  ),
  unset: vi.fn(async () =>
    result(
      {
        data: record({
          configured: false,
          source_scope: null,
          value: null,
          revision: '3',
        }),
      },
      200,
      { ETag: '"setting-3"' }
    )
  ),
});

const expectDisposedState = (
  runtime: ReturnType<typeof createSettingsRuntime>
): void => {
  expect(runtime.state.records).toEqual([]);
  expect(runtime.state.groups).toEqual([]);
  expect(runtime.state.forms).toEqual({});
  expect(runtime.state.errors).toEqual({});
  expect(runtime.state.conflicts).toEqual({});
  expect(runtime.state.pendingResources.size).toBe(0);
  expect(runtime.state.requests.size).toBe(0);
};

describe('settings page runtime', () => {
  it('loads grouped records and keeps secret forms write-only', async () => {
    const runtime = createSettingsRuntime({
      canRead: () => true,
      canManage: () => true,
      transport: createTransport(
        vi.fn(async () =>
          listResult(
            record(),
            record({
              setting_key: 'service-token',
              name: 'Service token',
              schema: { type: 'string', minLength: 1, maxLength: 4096 },
              secret: true,
              value: undefined,
            })
          )
        )
      ),
    });

    await runtime.load();

    expect(runtime.state.groups[0]?.definitions).toHaveLength(2);
    expect(runtime.state.forms['example.target/service-token']?.value).toBe('');
    expect(runtime.state.etags['example.target/service-token']).toBe(
      '"setting-2"'
    );
  });

  it('records invalid list responses as protocol errors instead of empty success', async () => {
    const invalidResults = [
      result({ data: { items: [record()] } }, 200, {
        'X-Request-Id': 'req_missing_etag',
      }),
      result({ data: { items: [record()] } }, 200, {
        'ETag': 'W/"settings-collection-1"',
        'X-Request-Id': 'req_weak_etag',
      }),
      result({ data: { items: 'invalid' } }, 200, {
        'ETag': '"settings-collection-1"',
        'X-Request-Id': 'req_invalid_body',
      }),
    ];

    for (const invalidResult of invalidResults) {
      const runtime = createSettingsRuntime({
        canRead: () => true,
        canManage: () => true,
        transport: createTransport(vi.fn(async () => invalidResult)),
      });
      const wrapper = mount(SettingsPage, {
        global: {
          plugins: [
            createI18n({
              legacy: false,
              locale: 'en-US',
              messages: { 'en-US': enUS },
            }),
          ],
          provide: { [settingsRuntimeKey as symbol]: runtime },
        },
      });

      await flushPromises();

      expect(runtime.state.records).toEqual([]);
      expect(runtime.state.collectionEtag).toBeNull();
      expect(runtime.state.errors.page).toMatchObject({
        kind: 'protocol',
        status: 200,
      });
      expect(
        wrapper.find('[data-settings-state="request-error"]').exists()
      ).toBe(true);
      expect(wrapper.text()).not.toContain(
        'No settings are available for this Tenant.'
      );
      expect(wrapper.text()).not.toContain('0 settings');
      wrapper.unmount();
    }

    const runtime = createSettingsRuntime({
      canRead: () => true,
      canManage: () => true,
      transport: createTransport(),
    });

    await runtime.load();

    expect(runtime.state.collectionEtag).toBe('"settings-collection-1"');
  });

  it('accepts unconfigured scalar null in lists and successful unset responses', async () => {
    const listRuntime = createSettingsRuntime({
      canRead: () => true,
      canManage: () => true,
      transport: createTransport(
        vi.fn(async () =>
          listResult(
            record({
              configured: false,
              source_scope: null,
              value: null,
            })
          )
        )
      ),
    });

    await listRuntime.load();

    expect(listRuntime.state.errors.page).toBeUndefined();
    expect(listRuntime.state.records[0]).toMatchObject({
      configured: false,
      sourceScope: null,
      value: null,
    });

    const unsetRuntime = createSettingsRuntime({
      canRead: () => true,
      canManage: () => true,
      transport: createTransport(),
    });
    await unsetRuntime.load();
    await unsetRuntime.unset('example.target/daily-limit');

    expect(
      unsetRuntime.state.errors['example.target/daily-limit']
    ).toBeUndefined();
    expect(unsetRuntime.state.records[0]).toMatchObject({
      configured: false,
      sourceScope: null,
      value: null,
    });
  });

  it('keeps only the latest list response authoritative and derives loading from it', async () => {
    const firstResult = deferred<SettingsTransportResult>();
    const secondResult = deferred<SettingsTransportResult>();
    let firstSignal: AbortSignal | undefined;
    const list = vi
      .fn()
      .mockImplementationOnce(async (signal: AbortSignal) => {
        firstSignal = signal;
        return firstResult.promise;
      })
      .mockImplementationOnce(async () => secondResult.promise);
    const runtime = createSettingsRuntime({
      canRead: () => true,
      canManage: () => true,
      transport: createTransport(list),
    });

    const firstLoad = runtime.load();
    const secondLoad = runtime.load();
    firstResult.resolve(listResult(record({ revision: '2', value: 20 })));
    await firstLoad;

    expect(firstSignal?.aborted).toBe(true);
    expect(runtime.state.loading).toBe(true);
    expect(runtime.state.records).toEqual([]);

    secondResult.resolve(
      result({ data: { items: [record({ revision: '3', value: 30 })] } }, 200, {
        ETag: '"settings-collection-3"',
      })
    );
    await secondLoad;

    expect(runtime.state.loading).toBe(false);
    expect(runtime.state.records[0]?.revision).toBe('3');
    expect(runtime.state.collectionEtag).toBe('"settings-collection-3"');
  });

  it('silently ignores an AbortError from a superseded list request', async () => {
    const abortedResult = deferred<SettingsTransportResult>();
    let firstSignal: AbortSignal | undefined;
    const list = vi
      .fn()
      .mockImplementationOnce(async (signal: AbortSignal) => {
        firstSignal = signal;
        signal.addEventListener('abort', () => {
          abortedResult.reject(
            Object.assign(new Error('Aborted'), { name: 'AbortError' })
          );
        });
        return abortedResult.promise;
      })
      .mockResolvedValueOnce(
        result({ data: { items: [record({ revision: '3' })] } }, 200, {
          ETag: '"settings-collection-3"',
        })
      );
    const runtime = createSettingsRuntime({
      canRead: () => true,
      canManage: () => true,
      transport: createTransport(list),
    });

    const firstLoad = runtime.load();
    const secondLoad = runtime.load();
    await secondLoad;
    if (firstSignal?.aborted !== true)
      abortedResult.resolve(listResult(record({ revision: '2' })));
    await firstLoad;

    expect(firstSignal?.aborted).toBe(true);
    expect(runtime.state.errors.page).toBeUndefined();
    expect(runtime.state.records[0]?.revision).toBe('3');
  });

  it('uses create then replace preconditions from the current resource ETag', async () => {
    const transport = createTransport(
      vi.fn(async () =>
        listResult(
          record({
            configured: false,
            source_scope: 'deployment',
            etag: null,
          })
        )
      )
    );
    const runtime = createSettingsRuntime({
      canRead: () => true,
      canManage: () => true,
      createIdempotencyKey: () => 'idem_settings_test',
      transport,
    });
    await runtime.load();
    runtime.updateForm('example.target/daily-limit', 30);

    await runtime.save('example.target/daily-limit');
    runtime.updateForm('example.target/daily-limit', 31);
    await runtime.save('example.target/daily-limit');

    expect(transport.replace).toHaveBeenNthCalledWith(
      1,
      'example.target',
      'daily-limit',
      expect.objectContaining({
        idempotencyKey: 'idem_settings_test',
        precondition: { kind: 'create' },
        value: 30,
      })
    );
    expect(transport.replace).toHaveBeenNthCalledWith(
      2,
      'example.target',
      'daily-limit',
      expect.objectContaining({
        precondition: { kind: 'replace', etag: '"setting-3"' },
        value: 31,
      })
    );
  });

  it('blocks a duplicate mutation while the same resource request is pending', async () => {
    const saveResult = deferred<SettingsTransportResult>();
    const transport = createTransport();
    vi.mocked(transport.replace).mockImplementationOnce(
      async () => saveResult.promise
    );
    const runtime = createSettingsRuntime({
      canRead: () => true,
      canManage: () => true,
      transport,
    });
    await runtime.load();
    runtime.updateForm('example.target/daily-limit', 30);

    const save = runtime.save('example.target/daily-limit');
    const duplicateError = await runtime
      .unset('example.target/daily-limit')
      .then(
        () => null,
        (error) => error as Error
      );
    saveResult.resolve(
      result({ data: record({ revision: '3', value: 30 }) }, 200, {
        ETag: '"setting-3"',
      })
    );
    await save;

    expect(duplicateError?.message).toBe('SETTINGS_RESOURCE_REQUEST_PENDING');
    expect(transport.replace).toHaveBeenCalledOnce();
    expect(transport.unset).not.toHaveBeenCalled();
  });

  it('rejects list and reload while a sent mutation remains in flight', async () => {
    const saveResult = deferred<SettingsTransportResult>();
    let saveSignal: AbortSignal | undefined;
    const list = vi.fn(async () => listResult(record()));
    const transport = createTransport(list);
    vi.mocked(transport.replace).mockImplementationOnce(
      async (_moduleKey, _settingKey, request) => {
        saveSignal = request.signal;
        return saveResult.promise;
      }
    );
    const runtime = createSettingsRuntime({
      canRead: () => true,
      canManage: () => true,
      transport,
    });
    await runtime.load();
    runtime.updateForm('example.target/daily-limit', 30);

    const save = runtime.save('example.target/daily-limit');
    const loadError = await runtime.load().then(
      () => null,
      (error) => error as Error
    );
    const reloadError = await runtime.reload('example.target/daily-limit').then(
      () => null,
      (error) => error as Error
    );
    saveResult.resolve(
      result({ data: record({ revision: '3', value: 30 }) }, 200, {
        ETag: '"setting-3"',
      })
    );
    await save;

    expect(loadError?.message).toBe('SETTINGS_MUTATION_PENDING');
    expect(reloadError?.message).toBe('SETTINGS_MUTATION_PENDING');
    expect(saveSignal?.aborted).toBe(false);
    expect(list).toHaveBeenCalledOnce();
    expect(runtime.state.records[0]?.revision).toBe('3');
  });

  it('preserves a newer form edit when an earlier save response arrives', async () => {
    const saveResult = deferred<SettingsTransportResult>();
    const transport = createTransport();
    vi.mocked(transport.replace).mockImplementationOnce(
      async () => saveResult.promise
    );
    const runtime = createSettingsRuntime({
      canRead: () => true,
      canManage: () => true,
      transport,
    });
    await runtime.load();
    runtime.updateForm('example.target/daily-limit', 30);

    const save = runtime.save('example.target/daily-limit');
    runtime.updateForm('example.target/daily-limit', 31);
    saveResult.resolve(
      result({ data: record({ revision: '3', value: 30 }) }, 200, {
        ETag: '"setting-3"',
      })
    );
    await save;

    expect(runtime.state.records[0]?.value).toBe(30);
    expect(runtime.state.forms['example.target/daily-limit']?.value).toBe(31);
  });

  it('takes a successful write ETag from the response header without requiring it in the body', async () => {
    const responseRecord = record({ revision: '3', value: 30 });
    delete responseRecord.etag;
    const transport = createTransport();
    vi.mocked(transport.replace).mockResolvedValueOnce(
      result({ data: responseRecord }, 200, { ETag: '"setting-3"' })
    );
    const runtime = createSettingsRuntime({
      canRead: () => true,
      canManage: () => true,
      transport,
    });
    await runtime.load();
    runtime.updateForm('example.target/daily-limit', 30);

    await runtime.save('example.target/daily-limit');

    expect(runtime.state.etags['example.target/daily-limit']).toBe(
      '"setting-3"'
    );
    expect(runtime.state.records[0]?.revision).toBe('3');
  });

  it('surfaces stale writes without optimistic changes until an explicit reload', async () => {
    const list = vi.fn(async () => listResult(record()));
    const transport = createTransport(list);
    vi.mocked(transport.replace).mockResolvedValueOnce(
      result(
        {
          type: '/docs/problems/precondition-failed',
          title: 'Precondition failed',
          status: 412,
          detail: 'The setting changed.',
          code: 'PRECONDITION_FAILED',
          request_id: 'req_stale_setting',
        },
        412
      )
    );
    const runtime = createSettingsRuntime({
      canRead: () => true,
      canManage: () => true,
      transport,
    });
    await runtime.load();
    runtime.updateForm('example.target/daily-limit', 30);

    await runtime.save('example.target/daily-limit');

    expect(runtime.state.records[0]?.value).toBe(25);
    expect(
      runtime.state.conflicts['example.target/daily-limit']?.requestId
    ).toBe('req_stale_setting');
    expect(list).toHaveBeenCalledTimes(1);

    await runtime.reload('example.target/daily-limit');

    expect(list).toHaveBeenCalledTimes(2);
    expect(
      runtime.state.conflicts['example.target/daily-limit']
    ).toBeUndefined();
  });

  it('surfaces an unset 412 conflict until an explicit reload refreshes the record', async () => {
    const list = vi
      .fn()
      .mockResolvedValueOnce(listResult(record()))
      .mockResolvedValueOnce(
        result(
          { data: { items: [record({ revision: '3', etag: '"setting-3"' })] } },
          200,
          {
            ETag: '"settings-collection-2"',
          }
        )
      );
    const transport = createTransport(list);
    vi.mocked(transport.unset).mockResolvedValueOnce(
      result(
        {
          type: '/docs/problems/precondition-failed',
          title: 'Precondition failed',
          status: 412,
          detail: 'The setting changed before unset.',
          code: 'PRECONDITION_FAILED',
          request_id: 'req_stale_unset',
        },
        412
      )
    );
    const runtime = createSettingsRuntime({
      canRead: () => true,
      canManage: () => true,
      transport,
    });
    await runtime.load();

    await runtime.unset('example.target/daily-limit');

    expect(runtime.state.records[0]?.revision).toBe('2');
    expect(
      runtime.state.conflicts['example.target/daily-limit']?.requestId
    ).toBe('req_stale_unset');
    expect(list).toHaveBeenCalledTimes(1);

    await runtime.reload('example.target/daily-limit');

    expect(list).toHaveBeenCalledTimes(2);
    expect(runtime.state.records[0]?.revision).toBe('3');
    expect(runtime.state.collectionEtag).toBe('"settings-collection-2"');
    expect(
      runtime.state.conflicts['example.target/daily-limit']
    ).toBeUndefined();
  });

  it('turns list, reload, and resource transport rejections into explicit errors', async () => {
    const listRuntime = createSettingsRuntime({
      canRead: () => true,
      canManage: () => true,
      transport: createTransport(
        vi.fn(async () => {
          throw new Error('list offline');
        })
      ),
    });

    await expect(listRuntime.load()).resolves.toBeUndefined();
    expect(listRuntime.state.errors.page).toMatchObject({
      kind: 'transport',
      status: null,
    });

    const reloadList = vi
      .fn()
      .mockResolvedValueOnce(listResult(record()))
      .mockRejectedValueOnce(new Error('reload offline'));
    const reloadRuntime = createSettingsRuntime({
      canRead: () => true,
      canManage: () => true,
      transport: createTransport(reloadList),
    });
    await reloadRuntime.load();
    await expect(
      reloadRuntime.reload('example.target/daily-limit')
    ).resolves.toBeUndefined();
    expect(reloadRuntime.state.errors.page).toMatchObject({
      kind: 'transport',
      status: null,
    });

    for (const operation of ['save', 'unset'] as const) {
      const transport = createTransport();
      vi.mocked(
        transport[operation === 'save' ? 'replace' : 'unset']
      ).mockRejectedValueOnce(new Error(`${operation} offline`));
      const runtime = createSettingsRuntime({
        canRead: () => true,
        canManage: () => true,
        transport,
      });
      await runtime.load();
      if (operation === 'save')
        runtime.updateForm('example.target/daily-limit', 30);

      await expect(
        runtime[operation]('example.target/daily-limit')
      ).resolves.toBeUndefined();
      expect(runtime.state.errors['example.target/daily-limit']).toMatchObject({
        kind: 'transport',
        status: null,
      });
    }
  });

  it('turns successful resource responses with invalid protocols into resource errors', async () => {
    const saveTransport = createTransport();
    vi.mocked(saveTransport.replace).mockResolvedValueOnce(
      result({ data: record({ revision: '3', value: 30 }) })
    );
    const saveRuntime = createSettingsRuntime({
      canRead: () => true,
      canManage: () => true,
      transport: saveTransport,
    });
    await saveRuntime.load();
    saveRuntime.updateForm('example.target/daily-limit', 30);

    await expect(
      saveRuntime.save('example.target/daily-limit')
    ).resolves.toBeUndefined();
    expect(
      saveRuntime.state.errors['example.target/daily-limit']
    ).toMatchObject({ kind: 'protocol', status: 200 });

    const unsetTransport = createTransport();
    vi.mocked(unsetTransport.unset).mockResolvedValueOnce(
      result({ data: { invalid: true } }, 200, {
        ETag: '"setting-3"',
      })
    );
    const unsetRuntime = createSettingsRuntime({
      canRead: () => true,
      canManage: () => true,
      transport: unsetTransport,
    });
    await unsetRuntime.load();

    await expect(
      unsetRuntime.unset('example.target/daily-limit')
    ).resolves.toBeUndefined();
    expect(
      unsetRuntime.state.errors['example.target/daily-limit']
    ).toMatchObject({ kind: 'protocol', status: 200 });
  });

  it('surfaces missing manage permission as a resource validation error and clears state on dispose', async () => {
    const transport = createTransport();
    const runtime = createSettingsRuntime({
      canRead: () => true,
      canManage: () => false,
      transport,
    });
    await runtime.load();
    runtime.updateForm('example.target/daily-limit', 30);
    runtime.setSecretVisible('example.target/daily-limit', true);

    await expect(
      runtime.save('example.target/daily-limit')
    ).resolves.toBeUndefined();
    expect(transport.replace).not.toHaveBeenCalled();
    expect(runtime.state.errors['example.target/daily-limit']).toMatchObject({
      kind: 'validation',
    });

    runtime.dispose();

    expect(runtime.state.records).toEqual([]);
    expect(runtime.state.groups).toEqual([]);
    expect(runtime.state.forms).toEqual({});
    expect(runtime.state.errors).toEqual({});
    expect(runtime.state.etags).toEqual({});
    expect(runtime.state.conflicts).toEqual({});
    expect(runtime.state.pendingVisibility.size).toBe(0);
    expect(runtime.state.requests.size).toBe(0);
  });

  it('surfaces unsupported unset as a resource validation error before sending a mutation', async () => {
    const transport = createTransport(
      vi.fn(async () =>
        listResult(
          record({
            schema: { type: 'object' },
            value: { enabled: true },
          })
        )
      )
    );
    const runtime = createSettingsRuntime({
      canRead: () => true,
      canManage: () => true,
      transport,
    });
    await runtime.load();

    await expect(
      runtime.unset('example.target/daily-limit')
    ).resolves.toBeUndefined();
    expect(transport.unset).not.toHaveBeenCalled();
    expect(runtime.state.errors['example.target/daily-limit']).toMatchObject({
      kind: 'validation',
    });
  });

  it('aborts in-flight list, save, and unset requests on dispose without late state refill', async () => {
    const listResultDeferred = deferred<SettingsTransportResult>();
    let listSignal: AbortSignal | undefined;
    const listRuntime = createSettingsRuntime({
      canRead: () => true,
      canManage: () => true,
      transport: createTransport(
        vi.fn(async (signal: AbortSignal) => {
          listSignal = signal;
          return listResultDeferred.promise;
        })
      ),
    });
    const listRequest = listRuntime.load();
    listRuntime.dispose();
    expect(listSignal?.aborted).toBe(true);
    listResultDeferred.resolve(listResult(record({ revision: '9' })));
    await listRequest;
    expectDisposedState(listRuntime);

    const saveResultDeferred = deferred<SettingsTransportResult>();
    let saveSignal: AbortSignal | undefined;
    const saveTransport = createTransport();
    vi.mocked(saveTransport.replace).mockImplementationOnce(
      async (_moduleKey, _settingKey, request) => {
        saveSignal = request.signal;
        return saveResultDeferred.promise;
      }
    );
    const saveRuntime = createSettingsRuntime({
      canRead: () => true,
      canManage: () => true,
      transport: saveTransport,
    });
    await saveRuntime.load();
    saveRuntime.updateForm('example.target/daily-limit', 30);
    const saveRequest = saveRuntime.save('example.target/daily-limit');
    saveRuntime.dispose();
    expect(saveSignal?.aborted).toBe(true);
    saveResultDeferred.resolve(
      result({ data: record({ revision: '9', value: 90 }) }, 200, {
        ETag: '"setting-9"',
      })
    );
    await saveRequest;
    expectDisposedState(saveRuntime);

    const unsetResultDeferred = deferred<SettingsTransportResult>();
    let unsetSignal: AbortSignal | undefined;
    const unsetTransport = createTransport();
    vi.mocked(unsetTransport.unset).mockImplementationOnce(
      async (_moduleKey, _settingKey, request) => {
        unsetSignal = request.signal;
        return unsetResultDeferred.promise;
      }
    );
    const unsetRuntime = createSettingsRuntime({
      canRead: () => true,
      canManage: () => true,
      transport: unsetTransport,
    });
    await unsetRuntime.load();
    const unsetRequest = unsetRuntime.unset('example.target/daily-limit');
    unsetRuntime.dispose();
    expect(unsetSignal?.aborted).toBe(true);
    unsetResultDeferred.reject(new Error('late unset failure'));
    await unsetRequest;
    expectDisposedState(unsetRuntime);
  });

  it('surfaces a Module availability failure instead of presenting an empty successful page', async () => {
    const transport = createTransport(
      vi.fn(async () =>
        result(
          {
            type: '/docs/problems/module-unavailable',
            title: 'Module unavailable',
            status: 503,
            detail: 'The settings Module is unavailable.',
            code: 'MODULE_UNAVAILABLE',
            request_id: 'req_settings_unavailable',
          },
          503
        )
      )
    );
    const runtime = createSettingsRuntime({
      canRead: () => true,
      canManage: () => true,
      transport,
    });

    await runtime.load();

    expect(runtime.state.groups).toEqual([]);
    expect(runtime.state.errors.page).toEqual({
      kind: 'http',
      message: 'The settings Module is unavailable.',
      requestId: 'req_settings_unavailable',
      status: 503,
    });
  });
});

it('the adopted settings host provides a per-page runtime and disposes it on unmount', () => {
  const observed: { runtime?: SettingsRuntime } = {};
  const child = defineComponent({
    setup() {
      const runtime = inject(settingsRuntimeKey);
      if (!runtime) throw new Error('SETTINGS_RUNTIME_NOT_PROVIDED');
      observed.runtime = runtime;
      return () => null;
    },
  });
  const wrapper = mount(RuntimePage, {
    global: { stubs: { SettingsPage: child } },
  });
  const runtime = observed.runtime;
  if (!runtime) throw new Error('SETTINGS_RUNTIME_NOT_PROVIDED');
  runtime.state.loading = true;
  wrapper.unmount();
  expect(runtime.state.loading).toBe(false);
  expect(runtime.state.groups).toEqual([]);
  expect(runtime.state.pendingResources.size).toBe(0);
});

describe('settings module page', () => {
  it('renders session-expired, forbidden, and module-unavailable list failures distinctly', async () => {
    const cases = [
      {
        status: 401,
        requestId: 'req_settings_session',
        component: SessionExpiredState,
      },
      {
        status: 403,
        requestId: 'req_settings_forbidden',
        component: ForbiddenState,
      },
      {
        status: 503,
        requestId: 'req_settings_unavailable',
        component: ModuleUnavailableState,
      },
    ] as const;

    for (const failure of cases) {
      const transport = createTransport(
        vi.fn(async () =>
          result(
            {
              type: '/docs/problems/settings-failed',
              title: 'Settings failed',
              status: failure.status,
              detail: `Settings failed with ${failure.status}.`,
              code: 'SETTINGS_FAILED',
              request_id: failure.requestId,
            },
            failure.status
          )
        )
      );
      const runtime = createSettingsRuntime({
        canRead: () => true,
        canManage: () => true,
        transport,
      });
      const wrapper = mount(SettingsPage, {
        global: {
          plugins: [
            createI18n({
              legacy: false,
              locale: 'en-US',
              messages: { 'en-US': enUS },
            }),
          ],
          provide: { [settingsRuntimeKey as symbol]: runtime },
        },
      });

      await flushPromises();

      expect(runtime.state.errors.page?.status).toBe(failure.status);
      const component: Component = failure.component;
      const renderedState = wrapper.findComponent(component);
      expect(renderedState.exists()).toBe(true);
      expect(renderedState.text()).toContain(failure.requestId);
      wrapper.unmount();
    }
  });

  it('does not present not-found or generic request failures as Module unavailable or empty success', async () => {
    const cases = [
      { status: 404, requestId: 'req_settings_hidden', state: 'not-found' },
      {
        status: 422,
        requestId: 'req_settings_invalid',
        state: 'request-error',
      },
      { status: 500, requestId: 'req_settings_failed', state: 'request-error' },
    ] as const;

    for (const failure of cases) {
      const transport = createTransport(
        vi.fn(async () =>
          result(
            {
              type: '/docs/problems/settings-failed',
              title: 'Settings failed',
              status: failure.status,
              detail: `Settings failed with ${failure.status}.`,
              code: 'SETTINGS_FAILED',
              request_id: failure.requestId,
            },
            failure.status
          )
        )
      );
      const runtime = createSettingsRuntime({
        canRead: () => true,
        canManage: () => true,
        transport,
      });
      const wrapper = mount(SettingsPage, {
        global: {
          plugins: [
            createI18n({
              legacy: false,
              locale: 'en-US',
              messages: { 'en-US': enUS },
            }),
          ],
          provide: { [settingsRuntimeKey as symbol]: runtime },
        },
      });

      await flushPromises();

      const renderedState = wrapper.get(
        `[data-settings-state="${failure.state}"]`
      );
      expect(renderedState.text()).toContain(failure.requestId);
      expect(wrapper.findComponent(ModuleUnavailableState).exists()).toBe(
        false
      );
      expect(wrapper.text()).not.toContain(
        'No settings are available for this Tenant.'
      );
      expect(wrapper.text()).not.toContain('0 settings');
      wrapper.unmount();
    }
  });

  it('exports a permission-gated Tenant contribution with lifecycle disposal', () => {
    const runtime = createSettingsRuntime({
      canRead: () => true,
      canManage: () => true,
      transport: createTransport(),
    });
    const dispose = vi.spyOn(runtime, 'dispose');

    expect(contribution.moduleKey).toBe('official.settings');
    expect(contribution.routes[0]).toMatchObject({
      name: 'officialSettingsRoot',
      path: '/system',
      meta: {
        requiresAuth: true,
        tenantModuleKey: 'official.settings',
        requiredPermissions: 'official.settings.read',
      },
      children: [
        {
          name: 'OfficialSettings',
          path: 'settings',
          meta: {
            requiresAuth: true,
            tenantModuleKey: 'official.settings',
            requiredPermissions: 'official.settings.read',
          },
        },
      ],
    });
    runtime.dispose();
    expect(dispose).toHaveBeenCalledOnce();
  });

  it('uses stable collision-free heading IDs for qualified setting keys', async () => {
    const runtime = createSettingsRuntime({
      canRead: () => true,
      canManage: () => true,
      transport: createTransport(
        vi.fn(async () =>
          listResult(
            record({
              module_key: 'foo.bar',
              setting_key: 'baz',
              name: 'Dot module setting',
            }),
            record({
              module_key: 'foo-bar',
              setting_key: 'baz',
              name: 'Dash module setting',
            })
          )
        )
      ),
    });
    const wrapper = mount(SettingsPage, {
      global: {
        plugins: [
          createI18n({
            legacy: false,
            locale: 'en-US',
            messages: { 'en-US': enUS },
          }),
        ],
        provide: { [settingsRuntimeKey as symbol]: runtime },
      },
    });
    await flushPromises();

    const headingIds = wrapper
      .findAll('article.setting-item')
      .map((article) => article.attributes('aria-labelledby'));
    expect(headingIds).toHaveLength(2);
    expect(new Set(headingIds).size).toBe(2);
    expect(
      headingIds.every(
        (id) => id !== undefined && wrapper.find(`[id="${id}"]`).exists()
      )
    ).toBe(true);
  });

  it('renders grouped scalar, enum, secret, and unsupported states without secret values', async () => {
    const runtime = createSettingsRuntime({
      canRead: () => true,
      canManage: () => true,
      transport: createTransport(
        vi.fn(async () =>
          listResult(
            record(),
            record({
              setting_key: 'mode',
              name: 'Mode',
              schema: { type: 'string', enum: ['safe', 'fast'] },
              value: 'safe',
            }),
            record({
              setting_key: 'service-token',
              name: 'Service token',
              schema: { type: 'string' },
              secret: true,
              value: undefined,
              ciphertext: 'must-not-leak',
            }),
            record({
              setting_key: 'advanced',
              name: 'Advanced',
              schema: {
                type: 'object',
                enum: [{ nested: true }, { nested: false }],
              },
              value: { nested: true },
            }),
            record({
              setting_key: 'regions',
              name: 'Regions',
              schema: { type: 'array', enum: [['cn'], ['us']] },
              value: ['cn'],
            })
          )
        )
      ),
    });
    const wrapper = mount(SettingsPage, {
      global: {
        plugins: [
          createI18n({
            legacy: false,
            locale: 'en-US',
            messages: { 'en-US': enUS },
          }),
        ],
        provide: { [settingsRuntimeKey as symbol]: runtime },
      },
    });

    await flushPromises();

    expect(
      wrapper.get('[data-settings-group="example.target"]').text()
    ).toContain('example.target');
    expect(wrapper.find('[data-editor-kind="number"]').exists()).toBe(true);
    expect(wrapper.find('[data-editor-kind="enum"]').exists()).toBe(true);
    expect(wrapper.find('[data-editor-kind="secret"]').exists()).toBe(true);
    expect(wrapper.findAll('[data-editor-kind="unsupported"]')).toHaveLength(2);
    expect(
      wrapper.findAll('[data-editor-kind="unsupported"]')[0]?.text()
    ).toContain('read-only');
    expect(wrapper.html()).not.toContain('must-not-leak');
    expect(JSON.stringify(runtime.state.records)).not.toContain(
      'must-not-leak'
    );
  });

  it('labels every setting article and command distinctly and disables a pending resource', async () => {
    const saveResult = deferred<SettingsTransportResult>();
    const transport = createTransport(
      vi.fn(async () =>
        listResult(
          record(),
          record({
            setting_key: 'service-token',
            name: 'Service token',
            schema: { type: 'string' },
            secret: true,
            value: undefined,
          })
        )
      )
    );
    vi.mocked(transport.replace).mockImplementationOnce(
      async () => saveResult.promise
    );
    const runtime = createSettingsRuntime({
      canRead: () => true,
      canManage: () => true,
      transport,
    });
    const wrapper = mount(SettingsPage, {
      global: {
        plugins: [
          createI18n({
            legacy: false,
            locale: 'en-US',
            messages: { 'en-US': enUS },
          }),
        ],
        provide: { [settingsRuntimeKey as symbol]: runtime },
      },
    });
    await flushPromises();

    for (const article of wrapper.findAll('article.setting-item')) {
      const labelledBy = article.attributes('aria-labelledby');
      expect(labelledBy).toBeTruthy();
      expect(
        article.element.querySelector(`[id="${labelledBy}"]`)
      ).not.toBeNull();
    }
    expect(
      wrapper
        .get(
          'button[aria-label="Save Daily limit (example.target/daily-limit)"]'
        )
        .attributes('aria-label')
    ).toBe('Save Daily limit (example.target/daily-limit)');
    expect(
      wrapper
        .get(
          'button[aria-label="Unset Daily limit (example.target/daily-limit)"]'
        )
        .attributes('aria-label')
    ).toBe('Unset Daily limit (example.target/daily-limit)');
    expect(
      wrapper
        .get(
          'button[aria-label="Show typed value for Service token (example.target/service-token)"]'
        )
        .attributes('aria-label')
    ).toBe('Show typed value for Service token (example.target/service-token)');

    runtime.updateForm('example.target/service-token', 'new-secret');
    const save = runtime.save('example.target/service-token');
    await nextTick();

    const secretArticle = wrapper.get(
      '[data-setting-key="example.target/service-token"]'
    );
    expect(secretArticle.get('input').attributes('disabled')).toBeDefined();
    expect(
      secretArticle
        .get('button[aria-label^="Show typed value"]')
        .attributes('disabled')
    ).toBeDefined();
    expect(
      secretArticle
        .get('button[aria-label^="Save Service token"]')
        .attributes('disabled')
    ).toBeDefined();
    expect(
      secretArticle
        .get('button[aria-label^="Unset Service token"]')
        .attributes('disabled')
    ).toBeDefined();

    saveResult.resolve(
      result(
        {
          data: record({
            setting_key: 'service-token',
            name: 'Service token',
            schema: { type: 'string' },
            secret: true,
            value: undefined,
            revision: '3',
          }),
        },
        200,
        { ETag: '"setting-3"' }
      )
    );
    await save;
  });

  it('rejects only an exactly empty secret and accepts whitespace for Module schema validation', async () => {
    const transport = createTransport(
      vi.fn(async () =>
        listResult(
          record({
            setting_key: 'service-token',
            name: 'Service token',
            schema: { type: 'string' },
            secret: true,
            value: undefined,
          })
        )
      )
    );
    vi.mocked(transport.replace).mockResolvedValueOnce(
      result(
        {
          data: record({
            setting_key: 'service-token',
            name: 'Service token',
            schema: { type: 'string' },
            secret: true,
            value: undefined,
            revision: '3',
          }),
        },
        200,
        { ETag: '"setting-3"' }
      )
    );
    const runtime = createSettingsRuntime({
      canRead: () => true,
      canManage: () => true,
      transport,
    });
    const wrapper = mount(SettingsPage, {
      global: {
        plugins: [
          createI18n({
            legacy: false,
            locale: 'en-US',
            messages: { 'en-US': enUS },
          }),
        ],
        provide: { [settingsRuntimeKey as symbol]: runtime },
      },
    });
    await flushPromises();

    const saveButton = wrapper.get(
      'button[aria-label="Save Service token (example.target/service-token)"]'
    );
    expect(saveButton.attributes('disabled')).toBeDefined();
    await expect(
      runtime.save('example.target/service-token')
    ).resolves.toBeUndefined();
    expect(transport.replace).not.toHaveBeenCalled();
    expect(runtime.state.errors['example.target/service-token']).toMatchObject({
      kind: 'validation',
    });

    runtime.updateForm('example.target/service-token', '   ');
    await nextTick();
    expect(saveButton.attributes('disabled')).toBeUndefined();
    await expect(
      runtime.save('example.target/service-token')
    ).resolves.toBeUndefined();
    await nextTick();

    expect(transport.replace).toHaveBeenCalledWith(
      'example.target',
      'service-token',
      expect.objectContaining({ value: '   ' })
    );
    expect(
      runtime.state.errors['example.target/service-token']
    ).toBeUndefined();
  });

  it('disables every reload action during another resource mutation and restores safe reload', async () => {
    const otherSave = deferred<SettingsTransportResult>();
    const list = vi
      .fn()
      .mockResolvedValueOnce(
        listResult(
          record(),
          record({
            setting_key: 'secondary',
            name: 'Secondary',
            schema: { type: 'string' },
            value: 'before',
          })
        )
      )
      .mockResolvedValueOnce(
        result(
          {
            data: {
              items: [
                record({ revision: '3', value: 30, etag: '"setting-3"' }),
                record({
                  setting_key: 'secondary',
                  name: 'Secondary',
                  schema: { type: 'string' },
                  value: 'after',
                }),
              ],
            },
          },
          200,
          { ETag: '"settings-collection-2"' }
        )
      );
    const transport = createTransport(list);
    vi.mocked(transport.replace)
      .mockResolvedValueOnce(
        result(
          {
            type: '/docs/problems/precondition-failed',
            title: 'Precondition failed',
            status: 412,
            detail: 'The setting changed.',
            code: 'PRECONDITION_FAILED',
            request_id: 'req_reload_pending',
          },
          412
        )
      )
      .mockImplementationOnce(async () => otherSave.promise);
    const runtime = createSettingsRuntime({
      canRead: () => true,
      canManage: () => true,
      transport,
    });
    const wrapper = mount(SettingsPage, {
      global: {
        plugins: [
          createI18n({
            legacy: false,
            locale: 'en-US',
            messages: { 'en-US': enUS },
          }),
        ],
        provide: { [settingsRuntimeKey as symbol]: runtime },
      },
    });
    await flushPromises();

    runtime.updateForm('example.target/daily-limit', 30);
    await runtime.save('example.target/daily-limit');
    runtime.updateForm('example.target/secondary', 'after');
    const pendingSave = runtime.save('example.target/secondary');
    await nextTick();

    const pageReload = wrapper.get('button[aria-label="Reload settings"]');
    const conflictReload = wrapper.get(
      'button[aria-label="Reload Daily limit (example.target/daily-limit)"]'
    );
    expect(pageReload.attributes('disabled')).toBeDefined();
    expect(pageReload.attributes('aria-disabled')).toBe('true');
    expect(conflictReload.attributes('disabled')).toBeDefined();
    expect(conflictReload.attributes('aria-disabled')).toBe('true');

    otherSave.resolve(
      result(
        {
          data: record({
            setting_key: 'secondary',
            name: 'Secondary',
            schema: { type: 'string' },
            value: 'after',
            revision: '3',
          }),
        },
        200,
        { ETag: '"setting-secondary-3"' }
      )
    );
    await pendingSave;
    await nextTick();

    expect(pageReload.attributes('disabled')).toBeUndefined();
    expect(pageReload.attributes('aria-disabled')).toBe('false');
    expect(conflictReload.attributes('disabled')).toBeUndefined();
    expect(conflictReload.attributes('aria-disabled')).toBe('false');

    await conflictReload.trigger('click');
    await flushPromises();

    expect(list).toHaveBeenCalledTimes(2);
    expect(
      runtime.state.conflicts['example.target/daily-limit']
    ).toBeUndefined();
  });

  it('keeps unconfigured scalar forms clean until explicit false and zero selections', async () => {
    const unconfiguredBoolean = record({
      setting_key: 'feature-enabled',
      name: 'Feature enabled',
      schema: { type: 'boolean' },
      configured: false,
      source_scope: null,
      value: null,
      etag: null,
    });
    const unconfiguredNumber = record({
      setting_key: 'retry-count',
      name: 'Retry count',
      schema: { type: 'integer' },
      configured: false,
      source_scope: null,
      value: null,
      etag: null,
    });
    const configuredBoolean = record({
      ...unconfiguredBoolean,
      configured: true,
      source_scope: 'tenant',
      value: false,
      revision: '1',
      etag: '"setting-feature-1"',
    });
    const configuredNumber = record({
      ...unconfiguredNumber,
      configured: true,
      source_scope: 'tenant',
      value: 0,
      revision: '1',
      etag: '"setting-retry-1"',
    });
    const list = vi
      .fn()
      .mockResolvedValueOnce(
        listResult(unconfiguredBoolean, unconfiguredNumber)
      )
      .mockResolvedValueOnce(
        result(
          { data: { items: [configuredBoolean, configuredNumber] } },
          200,
          {
            ETag: '"settings-collection-2"',
          }
        )
      );
    const transport = createTransport(list);
    vi.mocked(transport.replace)
      .mockResolvedValueOnce(
        result({ data: configuredBoolean }, 200, {
          ETag: '"setting-feature-1"',
        })
      )
      .mockResolvedValueOnce(
        result({ data: configuredNumber }, 200, { ETag: '"setting-retry-1"' })
      );
    const runtime = createSettingsRuntime({
      canRead: () => true,
      canManage: () => true,
      transport,
    });
    const wrapper = mount(SettingsPage, {
      global: {
        plugins: [
          createI18n({
            legacy: false,
            locale: 'en-US',
            messages: { 'en-US': enUS },
          }),
        ],
        provide: { [settingsRuntimeKey as symbol]: runtime },
      },
    });
    await flushPromises();

    expect(runtime.state.forms['example.target/feature-enabled']).toMatchObject(
      { value: null, dirty: false }
    );
    expect(runtime.state.forms['example.target/retry-count']).toMatchObject({
      value: null,
      dirty: false,
    });
    expect(
      wrapper
        .find(
          '[data-setting-key="example.target/feature-enabled"] [role="switch"]'
        )
        .exists()
    ).toBe(false);
    expect(
      wrapper
        .get('[data-boolean-unconfigured="example.target/feature-enabled"]')
        .text()
    ).toContain(enUS['officialSettings.value.notConfigured']);
    expect(
      (
        wrapper.get('[data-setting-key="example.target/retry-count"] input')
          .element as HTMLInputElement
      ).value
    ).toBe('');

    const booleanSave = wrapper.get(
      'button[aria-label="Save Feature enabled (example.target/feature-enabled)"]'
    );
    const numberSave = wrapper.get(
      'button[aria-label="Save Retry count (example.target/retry-count)"]'
    );
    expect(booleanSave.attributes('disabled')).toBeDefined();
    expect(numberSave.attributes('disabled')).toBeDefined();
    await runtime.save('example.target/feature-enabled');
    expect(
      runtime.state.errors['example.target/feature-enabled']
    ).toMatchObject({ kind: 'validation' });
    expect(transport.replace).not.toHaveBeenCalled();

    await wrapper
      .get(
        'button[aria-label="Set false for Feature enabled (example.target/feature-enabled)"]'
      )
      .trigger('click');
    runtime.updateForm('example.target/retry-count', 0);
    await nextTick();

    expect(runtime.state.forms['example.target/feature-enabled']).toMatchObject(
      { value: false, dirty: true }
    );
    expect(runtime.state.forms['example.target/retry-count']).toMatchObject({
      value: 0,
      dirty: true,
    });
    expect(booleanSave.attributes('disabled')).toBeUndefined();
    expect(numberSave.attributes('disabled')).toBeUndefined();

    await runtime.save('example.target/feature-enabled');
    await runtime.save('example.target/retry-count');

    expect(transport.replace).toHaveBeenNthCalledWith(
      1,
      'example.target',
      'feature-enabled',
      expect.objectContaining({
        value: false,
      })
    );
    expect(transport.replace).toHaveBeenNthCalledWith(
      2,
      'example.target',
      'retry-count',
      expect.objectContaining({
        value: 0,
      })
    );
    expect(runtime.state.forms['example.target/feature-enabled']?.dirty).toBe(
      false
    );
    expect(runtime.state.forms['example.target/retry-count']?.dirty).toBe(
      false
    );

    runtime.updateForm('example.target/retry-count', 1);
    expect(runtime.state.forms['example.target/retry-count']?.dirty).toBe(true);
    await runtime.reload('example.target/retry-count');

    expect(runtime.state.forms['example.target/feature-enabled']).toMatchObject(
      { value: false, dirty: false }
    );
    expect(runtime.state.forms['example.target/retry-count']).toMatchObject({
      value: 0,
      dirty: false,
    });
    expect(list).toHaveBeenCalledTimes(2);
  });
});
