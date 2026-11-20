// @vitest-environment happy-dom
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { defineComponent, h, reactive, ref } from 'vue';
import { createMemoryHistory, createRouter, RouterView } from 'vue-router';
import contribution from '../../contribution';
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
  detail: vi.fn(),
}));
const user = reactive({
  id: 1,
  accountId: 1,
  tenantName: 'A',
  role: 'admin',
  permissions: [
    'peanut.article.list',
    'peanut.article.detail',
    'peanut.article.edit',
  ],
  $subscribe: () => () => {},
});
vi.mock('@/store', () => ({ useUserStore: () => user }));
vi.mock('@/hooks/permission', () => ({
  hasPermission: (
    permission: string,
    _path: string | undefined,
    grants = user.permissions
  ) => grants.includes(permission),
}));
vi.mock('@peanut-admin/ui-vue', () => ({
  PageContent: { template: '<main><slot /></main>' },
  PageHeader: { template: '<header><slot /><slot name="actions" /></header>' },
  PageToolbar: { template: '<div><slot /></div>' },
}));
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
  getArticleExportInfo: vi.fn(),
  exportArticles: vi.fn(),
  recycleArticles: vi.fn(),
  getArticleCateAll: async () => ({ data: [] }),
  getArticleDetail: mock.detail,
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
const render = async (location = '/article/list') => {
  const editorRoute = contribution.routes[0].children?.find(
    ({ name }) => name === 'ArticleEdit'
  );
  if (!editorRoute || !editorRoute.component)
    throw new Error('Expected contributed article editor route');
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/article/list', name: 'ArticleList', component: ArticleList },
      {
        ...editorRoute,
        path: '/article/' + editorRoute.path,
        component: editorRoute.component,
      },
    ],
  });
  await router.push(location);
  await router.isReady();
  const wrapper = mount(RouterView, {
    global: {
      plugins: [router],
      mocks: { $t: (key: string) => key },
      directives: { permission: () => {}, loading: () => {} },
      stubs: {
        'Breadcrumb': true,
        'el-card': passthrough,
        'el-form': form,
        'el-form-item': passthrough,
        'el-option': true,
        'el-checkbox': true,
        'el-dropdown': true,
        'el-dropdown-menu': true,
        'el-dropdown-item': true,
        'el-icon': true,
        'el-empty': true,
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
  await flushPromises();
  return { wrapper, router };
};
const openEdit = async (
  wrapper: Awaited<ReturnType<typeof render>>['wrapper']
) => {
  await flushPromises();
  const edit = wrapper
    .findAll('button')
    .find((item) => item.text() === 'article.button.edit');
  if (!edit) throw new Error('Expected article edit button');
  await edit.trigger('click');
  await flushPromises();
};
beforeEach(() => {
  mock.detail.mockReset();
  user.permissions = [
    'peanut.article.list',
    'peanut.article.detail',
    'peanut.article.edit',
  ];
  mock.row.body_document_id = null;
  mock.detail.mockImplementation(async () => ({ data: { ...mock.row } }));
  mock.list.mockResolvedValue({
    data: { lists: [mock.row], pageNo: 1, pageSize: 15, count: 1 },
  });
});
describe('Article migrated body routing', () => {
  it('reopens a bound article in the editor while its original HTML remains present', async () => {
    mock.row.body_document_id = 11;
    const { wrapper, router } = await render();
    await openEdit(wrapper);
    await vi.waitFor(() =>
      expect(router.currentRoute.value.path).toBe('/article/edit/7')
    );
    await flushPromises();
    expect(mock.detail).toHaveBeenCalledWith(7, expect.any(AbortSignal));
    expect(wrapper.find('[data-body-editor]').exists()).toBe(true);
    expect(wrapper.find('[data-migration]').exists()).toBe(false);
    expect(mock.row.content).toBe('<p>Original HTML</p>');
    wrapper.unmount();
  });
  it('switches from explicit migration to the existing body editor on completion', async () => {
    const { wrapper } = await render('/article/edit/7');
    expect(wrapper.find('[data-body-editor]').exists()).toBe(false);
    await wrapper.get('[data-migration]').trigger('click');
    await flushPromises();
    expect(wrapper.find('[data-body-editor]').exists()).toBe(true);
    expect(wrapper.find('[data-migration]').exists()).toBe(false);
    expect(mock.row.content).toBe('<p>Original HTML</p>');
    wrapper.unmount();
  });
  it('rejects a direct edit URL without the required read permission', async () => {
    user.permissions = ['peanut.article.list', 'peanut.article.edit'];
    const { wrapper } = await render('/article/edit/7');
    expect(mock.detail).not.toHaveBeenCalled();
    expect(wrapper.find('[data-body-editor]').exists()).toBe(false);
    wrapper.unmount();
  });
  it('only mounts the document editor after an exact article match', async () => {
    mock.detail.mockResolvedValue({ data: { ...mock.row, id: 8 } });
    const { wrapper } = await render('/article/edit/7');
    expect(wrapper.find('[data-body-editor]').exists()).toBe(false);
    expect(wrapper.find('[data-migration]').exists()).toBe(false);
    wrapper.unmount();
  });
});
