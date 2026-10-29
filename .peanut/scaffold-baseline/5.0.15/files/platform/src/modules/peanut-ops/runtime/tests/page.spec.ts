// @vitest-environment happy-dom
import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { defineComponent, h, provide } from 'vue';
import OpsConsolePage from '../src/OpsConsolePage.vue';
import { LOG_SEVERITIES } from '../src/contracts';
import { createOpsConsoleRuntime, opsConsoleRuntimeKey } from '../src/runtime';
import { envelope, maintenanceData, result, statusData } from './fixtures';

const logEntryData = {
  event_key: 'runtime.warning',
  severity: 'warning',
  component_key: 'http.runtime',
  message: 'An operational event occurred.',
  occurred_at: '2026-07-24T02:00:00.000Z',
  request_id: null,
  occurrences: 1,
};

const passthrough = { template: '<div><slot /><slot name="actions" /></div>' };
const mountPage = (runtime: ReturnType<typeof createOpsConsoleRuntime>) => {
  const host = defineComponent({
    setup() {
      provide(opsConsoleRuntimeKey, runtime);
      return () => h(OpsConsolePage);
    },
  });
  return mount(host, {
    global: {
      stubs: {
        PageContent: passthrough,
        PageHeader: passthrough,
        ElTabs: passthrough,
        ElTabPane: passthrough,
        EmptyState: true,
        ForbiddenState: {
          props: ['message'],
          template: '<div>{{ message }}</div>',
        },
        ModuleUnavailableState: true,
        SessionExpiredState: true,
        ElSelect: passthrough,
        ElOption: true,
        ElInput: true,
        ElDatePicker: true,
        ElButton: {
          props: ['disabled', 'loading'],
          template: '<button :disabled="disabled"><slot /></button>',
        },
      },
    },
  });
};

describe('ops-console page', () => {
  it('shows operational evidence while keeping ungranted actions disabled', async () => {
    const runtime = createOpsConsoleRuntime({
      transport: {
        overview: async () => result(200, envelope(statusData)),
        maintenance: async () => result(200, envelope(maintenanceData)),
        logs: async () =>
          result(200, envelope({ items: [], next_cursor: null })),
        submitBackup: async () => {
          throw new Error('not called');
        },
        submitRestore: async () => {
          throw new Error('not called');
        },
        task: async () => {
          throw new Error('not called');
        },
        scheduleMaintenance: async () => {
          throw new Error('not called');
        },
        closeMaintenance: async () => {
          throw new Error('not called');
        },
      },
      providers: [
        {
          key: 'reference.mysql',
          backup: true,
          restoreTargets: ['verification'],
        },
      ],
      maintenanceReasons: ['upgrade'],
      logSources: ['application'],
      canRead: () => true,
      canBackup: () => false,
      canRestore: () => false,
      canMaintain: () => false,
      canReadLogs: () => true,
    });
    const wrapper = mountPage(runtime);
    await vi.waitFor(() =>
      expect(wrapper.text()).toContain('UPGRADE_PREFLIGHT_READY')
    );
    expect(wrapper.text()).toContain('UPGRADE_PREFLIGHT_READY');
    expect(
      wrapper
        .find('select[aria-label="Severity"]')
        .findAll('option')
        .map((option) => option.text())
    ).toEqual([...LOG_SEVERITIES]);
    expect(
      wrapper
        .findAll('button')
        .find((button) => button.text() === 'Create backup')
        ?.attributes('disabled')
    ).toBeDefined();
    expect(
      wrapper
        .findAll('button')
        .find((button) => button.text() === 'Restore and verify')
        ?.attributes('disabled')
    ).toBeDefined();
  });

  it('keeps draft log filters separate until Apply and never combines them with the old cursor', async () => {
    const logs = vi
      .fn()
      .mockResolvedValueOnce(
        result(
          200,
          envelope({ items: [logEntryData], next_cursor: 'cursor_page0001' })
        )
      )
      .mockResolvedValueOnce(
        result(200, envelope({ items: [], next_cursor: null }))
      )
      .mockResolvedValueOnce(
        result(200, envelope({ items: [], next_cursor: null }))
      );
    const runtime = createOpsConsoleRuntime({
      transport: {
        overview: async () => result(200, envelope(statusData)),
        maintenance: async () => result(200, envelope(maintenanceData)),
        logs,
        submitBackup: async () => {
          throw new Error('not called');
        },
        submitRestore: async () => {
          throw new Error('not called');
        },
        task: async () => {
          throw new Error('not called');
        },
        scheduleMaintenance: async () => {
          throw new Error('not called');
        },
        closeMaintenance: async () => {
          throw new Error('not called');
        },
      },
      providers: [],
      maintenanceReasons: [],
      logSources: ['application', 'audit'],
      canRead: () => true,
      canBackup: () => false,
      canRestore: () => false,
      canMaintain: () => false,
      canReadLogs: () => true,
    });
    const wrapper = mountPage(runtime);
    await vi.waitFor(() =>
      expect(runtime.state.logNextCursor).toBe('cursor_page0001')
    );
    expect(logs).toHaveBeenCalledTimes(1);

    await wrapper.find('select[aria-label="Source"]').setValue('audit');
    await wrapper.find('select[aria-label="Severity"]').setValue('error');
    expect(runtime.state).toMatchObject({
      logSource: 'application',
      logSeverity: 'warning',
      logNextCursor: 'cursor_page0001',
    });

    await wrapper
      .findAll('button')
      .find((button) => button.text() === 'Load more')!
      .trigger('click');
    await vi.waitFor(() => expect(logs).toHaveBeenCalledTimes(2));
    expect(logs).toHaveBeenNthCalledWith(
      2,
      'application',
      'warning',
      'cursor_page0001',
      50,
      expect.any(AbortSignal)
    );
    await vi.waitFor(() => expect(runtime.state.logsLoading).toBe(false));

    await wrapper
      .findAll('button')
      .find((button) => button.text() === 'Apply')!
      .trigger('click');
    expect(runtime.state).toMatchObject({
      logSource: 'audit',
      logSeverity: 'error',
      logs: [],
      logCursor: null,
      logNextCursor: null,
    });
    await vi.waitFor(() => expect(logs).toHaveBeenCalledTimes(3));
    expect(logs).toHaveBeenNthCalledWith(
      3,
      'audit',
      'error',
      null,
      50,
      expect.any(AbortSignal)
    );
  });
});
