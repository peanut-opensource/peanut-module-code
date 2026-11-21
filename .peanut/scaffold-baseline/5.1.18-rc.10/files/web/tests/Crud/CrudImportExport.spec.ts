import { describe, expect, it, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { ref } from 'vue';
import CrudImportExport from '@/components/crud/CrudImportExport.vue';

vi.mock('@/hooks/useCrudTab', () => ({ default: () => ref(true) }));
const button = {
  template: '<button @click="$emit(\'click\')"><slot /></button>',
};
const stubs = {
  ElButton: button,
  ElSelect: { name: 'ElSelect', template: '<div><slot /></div>' },
  ElOption: true,
  ElAlert: { props: ['title'], template: '<div>{{ title }}</div>' },
  ElDialog: { template: '<div v-if="false"><slot /></div>' },
};

describe('CrudImportExport', () => {
  it('does not offer export without permission', () => {
    const api = { validate: vi.fn(), submit: vi.fn(), export: vi.fn() };
    const wrapper = mount(CrudImportExport, {
      props: { api, canImport: false, canExport: false },
      global: { stubs },
    });
    expect(wrapper.findAll('button')).toHaveLength(0);
    expect(api.export).not.toHaveBeenCalled();
  });
  it('passes the selected format and displays export errors outside the dialog', async () => {
    const api = {
      validate: vi.fn(),
      submit: vi.fn(),
      export: vi.fn().mockRejectedValue(new Error('导出失败')),
    };
    const wrapper = mount(CrudImportExport, {
      props: { api, canImport: false, canExport: true },
      global: { stubs },
    });
    wrapper.findComponent({ name: 'ElSelect' }).vm.$emit('update:modelValue', 'csv');
    await wrapper.get('button').trigger('click');
    await flushPromises();
    expect(api.export).toHaveBeenCalledWith('csv', expect.any(AbortSignal));
    expect(wrapper.text()).toContain('导出失败');
  });
});
