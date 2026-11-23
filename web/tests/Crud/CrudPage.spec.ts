import { describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { ref } from 'vue';
import {
  ElAlert,
  ElButton,
  ElInput,
  ElPagination,
  ElSelect,
  ElTable,
  ElTableColumn,
} from 'element-plus';
import CrudPage from '@/components/crud/CrudPage.vue';

const tab = vi.hoisted(() => ({ deactivate: () => undefined }));
vi.mock('@/hooks/useCrudTab', () => ({
  default: (deactivate: () => void) => {
    tab.deactivate = deactivate;
    return ref(true);
  },
}));
const components = {
  ElAlert,
  ElButton,
  ElInput,
  ElPagination,
  ElSelect,
  ElTable,
  ElTableColumn,
};

describe('CrudPage', () => {
  it('only permits an authorized row edit and saves the changed field', async () => {
    const save = vi.fn().mockResolvedValue(undefined);
    const row = { id: 1, name: '原名称' };
    const wrapper = mount(CrudPage, {
      props: {
        loading: false,
        data: [row],
        pagination: { page: 1, pageSize: 10, total: 1 },
        fields: [{ key: 'name', label: '名称', editable: true }],
        canEdit: () => true,
        saveEdit: save,
      },
      global: { components, directives: { loading: () => undefined } },
    });
    await flushPromises();
    const edit = wrapper
      .findAll('button')
      .find((item) => item.text() === '编辑');
    expect(edit).toBeDefined();
    await edit!.trigger('click');
    await wrapper.get('input').setValue('新名称');
    const confirm = wrapper
      .findAll('button')
      .find((item) => item.text() === '确定');
    expect(confirm).toBeDefined();
    await confirm!.trigger('click');
    await flushPromises();
    expect(save).toHaveBeenCalledWith(
      row,
      { name: '新名称' },
      expect.any(AbortSignal)
    );
    expect(wrapper.emitted('edited')).toHaveLength(1);
  });
  it('does not show edit for a denied row', async () => {
    const wrapper = mount(CrudPage, {
      props: {
        loading: false,
        data: [{ name: 'a' }],
        pagination: { page: 1, pageSize: 10, total: 1 },
        fields: [{ key: 'name', label: '名称', editable: true }],
        canEdit: () => false,
        saveEdit: vi.fn(),
      },
      global: { components, directives: { loading: () => undefined } },
    });
    await flushPromises();
    expect(
      wrapper.findAll('button').some((item) => item.text() === '编辑')
    ).toBe(false);
  });
  it('aborts an in-flight edit when the tab is deactivated', async () => {
    const save = vi
      .fn()
      .mockImplementation(() => new Promise<void>(() => undefined));
    const row = { name: 'a' };
    const wrapper = mount(CrudPage, {
      props: {
        loading: false,
        data: [row],
        pagination: { page: 1, pageSize: 10, total: 1 },
        fields: [{ key: 'name', label: '名称', editable: true }],
        canEdit: () => true,
        saveEdit: save,
      },
      global: { components, directives: { loading: () => undefined } },
    });
    await flushPromises();
    await wrapper
      .findAll('button')
      .find((item) => item.text() === '编辑')!
      .trigger('click');
    await wrapper.get('input').setValue('b');
    await wrapper
      .findAll('button')
      .find((item) => item.text() === '确定')!
      .trigger('click');
    await flushPromises();
    const signal: AbortSignal = save.mock.calls[0][2];
    tab.deactivate();
    expect(signal.aborted).toBe(true);
  });

  it('shows a retry state instead of an empty table after an initial load failure', async () => {
    const wrapper = mount(CrudPage, {
      props: {
        loading: false,
        data: [],
        error: 'Do not expose raw server details',
        pagination: { page: 1, pageSize: 10, total: 0 },
      },
      global: { components, directives: { loading: () => undefined } },
    });
    expect(wrapper.get('.crud-page__load-error').text()).toContain(
      '列表加载失败'
    );
    expect(wrapper.text()).not.toContain('Do not expose raw server details');
    expect(wrapper.find('.el-table').exists()).toBe(false);
    await wrapper
      .findAll('button')
      .find((item) => item.text() === '重试')!
      .trigger('click');
    expect(wrapper.emitted('retry')).toHaveLength(1);
  });

  it('keeps prior rows visible and offers retry after a refresh failure', async () => {
    const row = { id: 1, name: '上次加载的岗位' };
    const wrapper = mount(CrudPage, {
      props: {
        loading: false,
        data: [row],
        error: 'network failure',
        pagination: { page: 2, pageSize: 10, total: 1 },
        fields: [{ key: 'name', label: '名称' }],
      },
      global: { components, directives: { loading: () => undefined } },
    });
    await flushPromises();
    expect(wrapper.get('.crud-page__load-error').text()).toContain(
      '仍显示上次结果'
    );
    const table = wrapper.findComponent(ElTable);
    expect(table.exists()).toBe(true);
    expect(table.props('data')).toEqual([row]);
    expect(wrapper.text()).not.toContain('network failure');
  });

  it('keeps the normal empty slot when the request succeeded with no rows', () => {
    const wrapper = mount(CrudPage, {
      props: {
        loading: false,
        data: [],
        error: null,
        pagination: { page: 1, pageSize: 10, total: 0 },
      },
      slots: { empty: '<span class="custom-empty">业务暂无记录</span>' },
      global: { components, directives: { loading: () => undefined } },
    });
    expect(wrapper.find('.crud-page__load-error').exists()).toBe(false);
    expect(wrapper.get('.custom-empty').text()).toBe('业务暂无记录');
    expect(wrapper.find('.el-table').exists()).toBe(true);
  });

  it('does not show a failure state while loading or when no error was recorded', () => {
    const wrapper = mount(CrudPage, {
      props: {
        loading: true,
        data: [],
        error: 'stale failure while loading',
        pagination: { page: 1, pageSize: 10, total: 0 },
      },
      global: { components, directives: { loading: () => undefined } },
    });
    expect(wrapper.find('.crud-page__load-error').exists()).toBe(false);
    expect(wrapper.text()).not.toContain('stale failure while loading');
    expect(wrapper.find('.el-table').exists()).toBe(true);
  });
});
