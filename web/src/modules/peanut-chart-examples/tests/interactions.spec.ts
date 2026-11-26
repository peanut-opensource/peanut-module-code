// @vitest-environment happy-dom
import { mount, flushPromises } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import ChartExamples from '../views/index.vue';

const app = vi.hoisted(() => ({ instance: null as unknown }));
vi.mock('@/store', async () => {
  const { reactive } = await import('vue');
  const state = reactive({
    theme: 'light',
    toggleTheme(enabled: boolean) {
      state.theme = enabled ? 'dark' : 'light';
    },
  });
  app.instance = state;
  return { useAppStore: () => state };
});

vi.mock('@peanut-admin/peanut-chart/charts', async () => {
  const { defineComponent, h, onMounted } = await import('vue');
  return {
    Chart: defineComponent({
      name: 'Chart',
      props: ['option', 'theme', 'loading'],
      emits: ['click', 'rendered', 'finished'],
      setup(props, { emit, expose }) {
        expose({
          getDataURL: () => 'data:image/png;base64,iVBORw0KGgo=',
        });
        onMounted(() => emit('finished'));
        return () =>
          h(
            'button',
            {
              type: 'button',
              class: 'synthetic-echarts-click',
              'data-theme': props.theme,
              'data-loading': String(props.loading),
              onClick: () => emit('click', { seriesName: '演示访问量', name: '周一' }),
            },
            'Chart'
          );
      },
    }),
  };
});

const Switch = defineComponent({
  name: 'ElSwitch',
  props: { modelValue: Boolean, activeText: String },
  emits: ['update:modelValue'],
  setup(props, { emit }) {
    return () =>
      h('button', {
        type: 'button',
        'aria-label': props.activeText,
        onClick: () => emit('update:modelValue', !props.modelValue),
      });
  },
});
const Button = defineComponent({
  name: 'ElButton',
  props: { disabled: Boolean },
  emits: ['click'],
  setup(props, { emit, slots }) {
    return () =>
      h('button', { type: 'button', disabled: props.disabled, onClick: () => emit('click') }, slots.default?.());
  },
});
const simple = (name: string) =>
  defineComponent({ name, setup(_props, { slots }) { return () => h('div', slots.default?.()); } });

describe('ChartExamples native consumer interactions', () => {
  it('exposes six actual chart options, zoom capability, click event and loading gate', async () => {
    const wrapper = mount(ChartExamples, {
      global: {
        stubs: {
          PageContent: simple('PageContent'),
          PageHeader: simple('PageHeader'),
          ElSpace: simple('ElSpace'),
          ElRow: simple('ElRow'),
          ElCol: simple('ElCol'),
          ElCard: simple('ElCard'),
          ElSwitch: Switch,
          ElButton: Button,
        },
      },
    });
    try {
      await flushPromises();
      const charts = wrapper.findAll('.synthetic-echarts-click');
      expect(charts).toHaveLength(6);
      const options = wrapper.findAllComponents({ name: 'Chart' }).map(x => x.props('option'));
      expect(options.map((x: any) => x.series[0].type)).toEqual(['line', 'bar', 'pie', 'radar', 'scatter', 'gauge']);
      expect(options[0].dataZoom).toMatchObject([{ type: 'inside' }, { type: 'slider' }]);
      const exportButton = () => wrapper.findAll('button').find(x => x.text() === '导出折线图 PNG');
      expect(exportButton()?.attributes('disabled')).toBeUndefined();
      await wrapper.find('[aria-label="加载状态"]').trigger('click');
      await flushPromises();
      expect(charts[0].attributes('data-loading')).toBe('true');
      expect(exportButton()?.attributes('disabled')).toBeDefined();
      await wrapper.find('[aria-label="加载状态"]').trigger('click');
      await charts[0].trigger('click');
      expect(wrapper.find('[role="status"]').text()).toContain('演示访问量 周一');
      await wrapper.find('[aria-label="深色主题"]').trigger('click');
      await flushPromises();
      expect((app.instance as any).theme).toBe('dark');
      expect(charts.every(x => x.attributes('data-theme') === 'dark')).toBe(true);
      await wrapper.find('[aria-label="深色主题"]').trigger('click');
      await flushPromises();
      expect((app.instance as any).theme).toBe('light');
    } finally {
      wrapper.unmount();
    }
  });
});

