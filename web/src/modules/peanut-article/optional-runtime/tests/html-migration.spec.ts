// @vitest-environment happy-dom
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { reactive } from 'vue';
import ArticleHtmlMigration from '../../views/list/ArticleHtmlMigration.vue';
import { ArticleHtmlMigrationRejected } from '../../api';
const mock = vi.hoisted(() => ({
  preview: vi.fn(),
  migrate: vi.fn(),
  convert: vi.fn(),
  generation: 'session-a',
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
  getSessionSnapshot: () => ({ generation: mock.generation }),
}));
vi.mock('../../api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('../../api')>()),
  previewArticleHtml: mock.preview,
  migrateArticleHtml: mock.migrate,
}));
vi.mock('@peanut-admin/peanut-rich-text/editor', () => ({
  previewRichTextHtml: mock.convert,
}));
vi.mock('@peanut-admin/peanut-rich-text/viewer', () => ({
  RichTextViewer: {
    props: ['modelValue'],
    template: '<div data-viewer>{{ modelValue }}</div>',
  },
}));
const document = {
  schemaVersion: 'peanut.richtext/1',
  editorModel: 'tiptap-prosemirror',
  content: { type: 'doc', content: [{ type: 'paragraph' }] },
  annotations: [],
};
const source = {
  article_id: 7,
  source_html: '<p>Hello</p>',
  source_sha256: 'a'.repeat(64),
  owner_revision: 'b'.repeat(64),
};
const completed = {
  article_id: 7,
  request_id: 'request',
  document_id: 11,
  state_seq: 1,
  source_sha256: source.source_sha256,
  phase: 'completed',
  published: false,
};
const render = () =>
  mount(ArticleHtmlMigration, {
    props: { articleId: 7, contextKey: 'owner-a' },
  });
beforeEach(() => {
  vi.clearAllMocks();
  mock.generation = 'session-a';
  mock.preview.mockResolvedValue({ data: source });
  mock.convert.mockReturnValue({ ok: true, document, limitations: [] });
  mock.migrate.mockImplementation(async (payload) => ({
    data: { ...completed, request_id: payload.request_id },
  }));
});
describe('Article explicit HTML migration', () => {
  it('requires explicit preview and confirmation; displays escaped source and visual preview', async () => {
    const wrapper = render();
    expect(mock.preview).not.toHaveBeenCalled();
    await wrapper.get('[data-preview]').trigger('click');
    await flushPromises();
    expect(wrapper.get('pre').text()).toBe(source.source_html);
    expect(wrapper.find('pre p').exists()).toBe(false);
    expect(wrapper.find('[data-viewer]').exists()).toBe(true);
    expect(
      wrapper.get<HTMLButtonElement>('[data-migrate]').element.disabled
    ).toBe(true);
    await wrapper.get('input[type=checkbox]').setValue(true);
    await wrapper.get('[data-migrate]').trigger('click');
    await flushPromises();
    expect(mock.migrate).toHaveBeenCalledOnce();
    expect(mock.migrate.mock.calls[0][0]).toMatchObject({
      id: 7,
      confirmed: true,
      structured_content: document,
      owner_revision: source.owner_revision,
    });
    expect(wrapper.emitted('completed')).toHaveLength(1);
    wrapper.unmount();
  });
  it('rejects unsupported conversion and reports its concrete limitation', async () => {
    mock.convert.mockReturnValue({
      ok: false,
      limitations: [{ code: 'HTML_UNSUPPORTED', message: '不支持 iframe' }],
    });
    const wrapper = render();
    await wrapper.get('[data-preview]').trigger('click');
    await flushPromises();
    expect(wrapper.text()).toContain('不支持 iframe');
    expect(wrapper.find('[data-migrate]').exists()).toBe(false);
    expect(mock.migrate).not.toHaveBeenCalled();
    wrapper.unmount();
  });
  it('retains the identical payload and request after an unknown network result', async () => {
    mock.migrate.mockRejectedValueOnce(new Error('network unknown'));
    const wrapper = render();
    await wrapper.get('[data-preview]').trigger('click');
    await flushPromises();
    await wrapper.get('input[type=checkbox]').setValue(true);
    await wrapper.get('[data-migrate]').trigger('click');
    await flushPromises();
    const original = mock.migrate.mock.calls[0][0];
    expect(
      wrapper.get<HTMLButtonElement>('[data-preview]').element.disabled
    ).toBe(true);
    await wrapper.get('[data-retry]').trigger('click');
    await flushPromises();
    expect(mock.migrate.mock.calls[1][0]).toBe(original);
    expect(mock.preview).toHaveBeenCalledOnce();
    wrapper.unmount();
  });
  it('requires a fresh preview and confirmation after an exact source conflict', async () => {
    mock.migrate.mockRejectedValueOnce(new ArticleHtmlMigrationRejected());
    const wrapper = render();
    await wrapper.get('[data-preview]').trigger('click');
    await flushPromises();
    await wrapper.get('input[type=checkbox]').setValue(true);
    await wrapper.get('[data-migrate]').trigger('click');
    await flushPromises();
    expect(wrapper.find('[data-retry]').exists()).toBe(false);
    expect(wrapper.find('[data-migrate]').exists()).toBe(false);
    expect(
      wrapper.get<HTMLButtonElement>('[data-preview]').element.disabled
    ).toBe(false);
    await wrapper.get('[data-preview]').trigger('click');
    await flushPromises();
    expect(
      wrapper.get<HTMLButtonElement>('[data-migrate]').element.disabled
    ).toBe(true);
    wrapper.unmount();
  });
  it('aborts and ignores late replies when the owner changes', async () => {
    let resolve!: (value: unknown) => void;
    mock.preview.mockReturnValue(
      new Promise((done) => {
        resolve = done;
      })
    );
    const wrapper = render();
    await wrapper.get('[data-preview]').trigger('click');
    const signal = mock.preview.mock.calls[0][1];
    await wrapper.setProps({ articleId: 8 });
    expect(signal.aborted).toBe(true);
    resolve({ data: source });
    await flushPromises();
    expect(wrapper.find('pre').exists()).toBe(false);
    wrapper.unmount();
  });
  it('ignores migration completion after session replacement and clears on storage notification', async () => {
    let resolve!: (value: unknown) => void;
    mock.migrate.mockReturnValue(
      new Promise((done) => {
        resolve = done;
      })
    );
    const wrapper = render();
    await wrapper.get('[data-preview]').trigger('click');
    await flushPromises();
    await wrapper.get('input[type=checkbox]').setValue(true);
    await wrapper.get('[data-migrate]').trigger('click');
    const signal = mock.migrate.mock.calls[0][1];
    mock.generation = 'session-b';
    window.dispatchEvent(new StorageEvent('storage'));
    await flushPromises();
    expect(signal.aborted).toBe(true);
    resolve({ data: completed });
    await flushPromises();
    expect(wrapper.emitted('completed')).toBeUndefined();
    expect(wrapper.find('pre').exists()).toBe(false);
    wrapper.unmount();
  });
});
