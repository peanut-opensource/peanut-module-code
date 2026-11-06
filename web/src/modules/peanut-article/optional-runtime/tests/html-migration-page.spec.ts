// @vitest-environment happy-dom
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { defineComponent, h, reactive, ref } from 'vue';
import ArticleList from '../../views/list/index.vue';
const mock = vi.hoisted(() => ({
  row: {
    id: 7,
    body_document_id: null as number | null,
    cid: 1,
    title: 'Article',
    content: '<p>Original HTML</p>',
    is_show: 0,
    image: '',
    desc: '',
    abstract: '',
    author: '',
    sort: 0,
    click_virtual: 0,
  },
  list: vi.fn(),
}));
const user = reactive({
  id: 1,
  accountId: 1,
  tenantName: 'A',
  role: 'admin',
  permissions: ['edit'],
  $subscribe: () => () => {},
});
vi.mock('@/store', () => ({ useUserStore: () => user }));
vi.mock('@/utils/auth', () => ({
  getSessionSnapshot: () => ({ generation: 1 }),
}));
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }));
vi.mock('@peanut-admin/vue', () => ({
  useAsyncAction: () => ({
    loading: ref(false),
    error: ref(''),
    cancel: vi.fn(),
    run: vi.fn(),
  }),
}));
vi.mock('@/components/file-picker/index.vue', () => ({
  default: { template: '<span />' },
}));
vi.mock('@/modules/peanut-file/api', () => ({ uploadFile: vi.fn() }));
vi.mock('../../views/list/ArticleBodyEditor.vue', () => ({
  default: { props: ['articleId'], template: '<div data-body-editor />' },
}));
vi.mock('../../views/list/ArticleBodyHistory.vue', () => ({
  default: { template: '<span />' },
}));
vi.mock('../../views/list/ArticleHtmlMigration.vue', () => ({
  default: {
    emits: ['completed'],
    template:
      '<button data-migration @click="$emit(\'completed\', {article_id: 7, document_id: 11})">migrate</button>',
  },
}));
vi.mock('../../api', () => ({
  getArticleList: mock.list,
  getArticleCateAll: async () => ({ data: [] }),
  getArticleDetail: vi.fn(),
  addArticle: vi.fn(),
  deleteArticle: vi.fn(),
  updateArticleStatus: vi.fn(),
}));
const passthrough = defineComponent({
  setup:
    (_, { slots }) =>
    () =>
      h('div', [slots.default?.(), slots.footer?.()]),
});
const form = defineComponent({
  setup: (_, { slots, expose }) => {
    expose({ clearValidate: () => {}, validate: async () => true });
    return () => h('form', slots.default?.());
  },
});
const column = defineComponent({
  setup:
    (_, { slots }) =>
    () =>
      h('div', slots.default?.({ row: mock.row })),
});
const button = defineComponent({
  setup:
    (_, { slots, attrs }) =>
    () =>
      h('button', attrs, slots.default?.()),
});
const render = () =>
  mount(ArticleList, {
    global: {
      mocks: { $t: (key: string) => key },
      directives: { permission: () => {}, loading: () => {} },
      stubs: {
        'Breadcrumb': true,
        'el-card': passthrough,
        'el-form': form,
        'el-form-item': passthrough,
        'el-row': passthrough,
        'el-col': passthrough,
        'el-space': passthrough,
        'el-table': passthrough,
        'el-table-column': column,
        'el-dialog': passthrough,
        'el-button': button,
        'el-alert': true,
        'el-input': true,
        'el-select': true,
        'el-switch': true,
        'el-image': true,
        'el-popconfirm': true,
        'el-pagination': true,
        'el-upload': true,
        'el-input-number': true,
      },
    },
  });
const openEdit = async (wrapper: ReturnType<typeof render>) => {
  await flushPromises();
  const edit = wrapper
    .findAll('button')
    .find((item) => item.text() === 'article.button.edit');
  if (!edit) throw new Error('Expected article edit button');
  await edit.trigger('click');
  await flushPromises();
};
beforeEach(() => {
  mock.row.body_document_id = null;
  mock.list.mockResolvedValue({
    data: { lists: [mock.row], pageNo: 1, pageSize: 15, count: 1 },
  });
});
describe('Article migrated body routing', () => {
  it('reopens a bound article in the editor while its original HTML remains present', async () => {
    mock.row.body_document_id = 11;
    const wrapper = render();
    await openEdit(wrapper);
    expect(wrapper.find('[data-body-editor]').exists()).toBe(true);
    expect(wrapper.find('[data-migration]').exists()).toBe(false);
    expect(mock.row.content).toBe('<p>Original HTML</p>');
    wrapper.unmount();
  });
  it('switches from explicit migration to the existing body editor on completion', async () => {
    const wrapper = render();
    await openEdit(wrapper);
    expect(wrapper.find('[data-body-editor]').exists()).toBe(false);
    await wrapper.get('[data-migration]').trigger('click');
    await flushPromises();
    expect(wrapper.find('[data-body-editor]').exists()).toBe(true);
    expect(wrapper.find('[data-migration]').exists()).toBe(false);
    expect(mock.row.content).toBe('<p>Original HTML</p>');
    wrapper.unmount();
  });
});
