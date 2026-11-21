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
    await wrapper
      .findAll('button')
      .find((item) => item.text() === '确定')!
      .trigger('click');
    const signal: AbortSignal = save.mock.calls[0][2];
    tab.deactivate();
    expect(signal.aborted).toBe(true);
  });
});
