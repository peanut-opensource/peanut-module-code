// @vitest-environment happy-dom
import { mount, flushPromises } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { defineComponent, h, shallowRef, nextTick } from 'vue';
import ContentViewer from '../src/ContentViewer.vue';
import ContentEditor from '../src/ContentEditor.vue';
import BasicFileViewer from '../src/BasicFileViewer.vue';
import contribution from '../contribution';
import ContentToolRegistry from '../src/registry';
import parseSurfaceCapabilities from '../src/capabilities';
import type {
  AuthorizedPreviewAccess,
  ContentSurfaceProps,
  FileResourceContent,
  SurfaceAdapterRegistration,
  SurfaceCapabilities,
} from '../src/contracts';

const content = (mime = 'image/png'): FileResourceContent => ({
  kind: 'file_resource',
  identity: 'file-revision-1',
  fileKey: 'file_0123456789abcdef0123456789abcdef',
  sourceSha256: 'a'.repeat(64),
  verifiedMimeType: mime,
  displayName: 'preview',
});
const capabilities = (
  mime = 'image/png',
  id = 'builtin.image'
): SurfaceCapabilities => ({
  contentKind: 'file_resource',
  verifiedMimeType: mime,
  viewerAdapterId: id,
  editorAdapterId: null,
  canView: true,
  canEdit: false,
  canDownloadOriginal: false,
  state: 'read_only',
  unavailableReason: null,
  capabilityRevision: 'cap-1',
});
const access = (mime = 'image/png'): AuthorizedPreviewAccess => ({
  url: '/authorized/preview',
  fileKey: content().fileKey,
  sourceSha256: content().sourceSha256,
  mimeType: mime,
  purpose: 'preview',
  bytes: 'derivative',
  expiresAt: Date.now() + 60000,
  dispose: vi.fn(),
});
const registry = () =>
  new ContentToolRegistry([
    {
      ...contribution,
      contentAdapters: contribution.contentAdapters?.map((adapter) => ({
        ...adapter,
        component: BasicFileViewer,
      })),
    },
  ]);
const props = (): ContentSurfaceProps => ({
  content: content(),
  capabilities: capabilities(),
  contextKey: 'tenant-1:member-1',
  allowedOperations: ['view'],
  callbacks: { resolvePreview: vi.fn(async () => access()) },
  registry: registry(),
});

describe('content tool registry', () => {
  it('validates real capability response shapes without coercing falsey values', () => {
    expect(parseSurfaceCapabilities(capabilities())).toEqual(capabilities());
    for (const value of [
      null,
      [],
      { ...capabilities(), canView: 'false' },
      { ...capabilities(), state: ['available'] },
      { ...capabilities(), verifiedMimeType: 'image/*' },
      { ...capabilities(), viewerAdapterId: {} },
      { ...capabilities(), capabilityRevision: '' },
    ]) {
      expect(() => parseSurfaceCapabilities(value)).toThrow(
        'CONTENT_CAPABILITIES_INVALID'
      );
    }
  });
  it('collects the real contribution and selects only explicit matching IDs', () => {
    const registered = registry();
    expect(
      registered.selected('builtin.image', content(), 'view')
    ).not.toBeNull();
    expect(registered.selected(null, content(), 'view')).toBeNull();
    expect(
      registered.selected('builtin.image', content('application/pdf'), 'view')
    ).toBeNull();
    expect(registered.selected('builtin.image', content(), 'edit')).toBeNull();
    expect(() => new ContentToolRegistry([contribution, contribution])).toThrow(
      'CONTENT_ADAPTER_DUPLICATE'
    );
    expect(
      () =>
        new ContentToolRegistry([
          { ...contribution, moduleKey: 'evil.provider' },
        ])
    ).toThrow('CONTENT_ADAPTER_INVALID');
  });
  it('rejects incompatible protocols rather than loading arbitrary tools', () => {
    const first = contribution.contentAdapters?.[0];
    expect(first).toBeDefined();
    if (!first) throw new Error('test requires builtin');
    const invalid = { ...first };
    Object.defineProperty(invalid, 'protocolVersion', { value: 'other/1' });
    expect(
      () =>
        new ContentToolRegistry([
          {
            moduleKey: 'official.content-tools',
            routes: [],
            // Invalid protocol is deliberately passed through the runtime boundary.
            contentAdapters: [invalid],
          },
        ])
    ).toThrow('CONTENT_ADAPTER_INVALID');
  });
});

describe('content surfaces', () => {
  it.each([
    ['image/png', 'builtin.image', 'img'],
    ['application/pdf', 'builtin.pdf', 'object'],
    ['audio/mpeg', 'builtin.audio', 'audio'],
    ['video/mp4', 'builtin.video', 'video'],
  ])('uses an actual controlled %s viewer', async (mime, id, element) => {
    if (mime === 'application/pdf') {
      vi.stubGlobal(
        'fetch',
        vi.fn(
          async () =>
            new Response(new TextEncoder().encode('%PDF-1.7\npreview'), {
              headers: {
                'Content-Type': 'application/pdf',
                'Content-Disposition': 'attachment',
              },
            })
        )
      );
      vi.spyOn(URL, 'createObjectURL').mockReturnValue('blob:controlled-pdf');
      vi.spyOn(URL, 'revokeObjectURL').mockImplementation(() => {});
    }
    const preview = access(mime);
    const selected = props();
    selected.content = content(mime);
    selected.capabilities = capabilities(mime, id);
    selected.callbacks = {
      resolvePreview: vi.fn(async () => preview),
      downloadOriginal: vi.fn(),
    };
    const wrapper = mount(ContentViewer, { props: selected });
    await flushPromises();
    expect(wrapper.find(element).exists()).toBe(true);
    expect(wrapper.find('button').exists()).toBe(false);
    expect(wrapper.emitted('ready')).toHaveLength(1);
    wrapper.unmount();
    expect(preview.dispose).toHaveBeenCalledOnce();
    if (mime === 'application/pdf') {
      expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:controlled-pdf');
      vi.unstubAllGlobals();
      vi.restoreAllMocks();
    }
  });
  it('refuses original bytes when there is no original-download permission', async () => {
    const preview = { ...access(), bytes: 'original' as const };
    const selected = props();
    selected.callbacks = { resolvePreview: async () => preview };
    const wrapper = mount(ContentViewer, { props: selected });
    await flushPromises();
    expect(wrapper.find('img').exists()).toBe(false);
    expect(wrapper.emitted('error')?.[0]).toEqual([
      'CONTENT_PREVIEW_UNAVAILABLE',
    ]);
    expect(preview.dispose).toHaveBeenCalledOnce();
    wrapper.unmount();
  });
  it('clears an expired preview and releases its temporary authorization', async () => {
    vi.useFakeTimers();
    try {
      const preview = { ...access(), expiresAt: Date.now() + 100 };
      const selected = props();
      selected.callbacks = { resolvePreview: async () => preview };
      const wrapper = mount(ContentViewer, { props: selected });
      await Promise.resolve();
      await nextTick();
      expect(wrapper.find('img').exists()).toBe(true);
      await vi.advanceTimersByTimeAsync(101);
      await nextTick();
      expect(wrapper.find('img').exists()).toBe(false);
      expect(preview.dispose).toHaveBeenCalledOnce();
      wrapper.unmount();
    } finally {
      vi.useRealTimers();
    }
  });
  it('keeps an unhealthy provider unavailable even if props claim view permission', async () => {
    const selected = props();
    selected.capabilities = { ...capabilities(), state: 'service_unavailable' };
    const wrapper = mount(ContentViewer, { props: selected });
    await flushPromises();
    expect(wrapper.text()).toContain('服务暂不可用');
    expect(selected.callbacks.resolvePreview).not.toHaveBeenCalled();
    wrapper.unmount();
  });
  it.each([
    'javascript:alert(1)',
    '//evil.example/preview',
    '/unsafe\\host',
    'https://user:pass@example.test/file',
  ])('rejects unsafe access URL %s', async (url) => {
    const preview = { ...access(), url };
    const selected = props();
    selected.callbacks = { resolvePreview: async () => preview };
    const wrapper = mount(ContentViewer, { props: selected });
    await flushPromises();
    expect(wrapper.find('img').exists()).toBe(false);
    expect(preview.dispose).toHaveBeenCalledOnce();
    wrapper.unmount();
  });
  it('rejects wrong file/version and expired access', async () => {
    for (const changed of [
      { fileKey: 'other' },
      { sourceSha256: 'b'.repeat(64) },
      { expiresAt: 1 },
    ]) {
      const preview = { ...access(), ...changed };
      const selected = props();
      selected.callbacks = { resolvePreview: async () => preview };
      const wrapper = mount(ContentViewer, { props: selected });
      await flushPromises();
      expect(wrapper.find('img').exists()).toBe(false);
      expect(preview.dispose).toHaveBeenCalledOnce();
      wrapper.unmount();
    }
  });
  it.each([
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/octet-stream',
    'image/svg+xml',
  ])(
    'keeps unsupported %s unavailable without a download fallback',
    async (mime) => {
      const selected = props();
      selected.content = content(mime);
      selected.capabilities = {
        ...capabilities(mime),
        state: 'preview_unavailable',
      };
      const wrapper = mount(ContentViewer, { props: selected });
      await flushPromises();
      expect(wrapper.text()).toContain('尚无可用预览器');
      expect(selected.callbacks.resolvePreview).not.toHaveBeenCalled();
      expect(wrapper.find('button').exists()).toBe(false);
      wrapper.unmount();
    }
  );
  it('intersects owner operations with server capabilities before access/download', async () => {
    const selected = props();
    selected.allowedOperations = [];
    selected.capabilities = { ...capabilities(), canDownloadOriginal: true };
    selected.callbacks.downloadOriginal = vi.fn();
    const wrapper = mount(ContentViewer, { props: selected });
    await flushPromises();
    expect(wrapper.text()).toContain('未获授权');
    expect(wrapper.find('button').exists()).toBe(false);
    expect(selected.callbacks.resolvePreview).not.toHaveBeenCalled();
    wrapper.unmount();
  });
  it('allows an independently authorized download without granting preview', async () => {
    const selected = props();
    selected.allowedOperations = ['download_original'];
    selected.capabilities = {
      ...capabilities(),
      canView: false,
      canDownloadOriginal: true,
      state: 'permission_denied',
    };
    selected.callbacks.downloadOriginal = vi.fn(async () => undefined);
    const wrapper = mount(ContentViewer, { props: selected });
    await wrapper.find('button').trigger('click');
    await flushPromises();
    expect(selected.callbacks.downloadOriginal).toHaveBeenCalledOnce();
    expect(selected.callbacks.resolvePreview).not.toHaveBeenCalled();
    wrapper.unmount();
  });
  it('disposes a late preview and aborts the old identity session', async () => {
    let finish: ((value: AuthorizedPreviewAccess) => void) | undefined;
    let signal: AbortSignal | undefined;
    const selected = props();
    selected.callbacks = {
      resolvePreview: (_content, current) => {
        signal = current;
        return new Promise((resolve) => {
          finish = resolve;
        });
      },
    };
    const currentProps = shallowRef(selected);
    const wrapper = mount(
      defineComponent({
        setup: () => () => h(ContentViewer, currentProps.value),
      })
    );
    await flushPromises();
    const preview = access();
    currentProps.value = {
      ...selected,
      contextKey: 'tenant-2:member-2',
      allowedOperations: [],
    };
    await nextTick();
    expect(signal?.aborted).toBe(true);
    finish?.(preview);
    await flushPromises();
    expect(preview.dispose).toHaveBeenCalledOnce();
    expect(wrapper.find('img').exists()).toBe(false);
    wrapper.unmount();
  });
  it('does not claim an image or Office editor exists', async () => {
    const selected = props();
    selected.allowedOperations = ['edit'];
    selected.capabilities = { ...capabilities(), state: 'edit_unsupported' };
    const wrapper = mount(ContentEditor, { props: selected });
    await flushPromises();
    expect(wrapper.text()).toContain('尚无可用编辑器');
    expect(wrapper.find('img').exists()).toBe(false);
    wrapper.unmount();
  });
  it('delegates real provider editing and emits save requests without saved receipts', async () => {
    const provider = defineComponent({
      emits: ['ready', 'dirty-change', 'save-request'],
      setup(_props, { emit }) {
        return () =>
          h(
            'button',
            {
              onClick: () => {
                emit('dirty-change', true);
                emit('save-request', { text: 'draft' });
              },
            },
            'edit'
          );
      },
    });
    const registration: SurfaceAdapterRegistration = {
      id: 'fixture.rich-text',
      providerModuleKey: 'fixture.editor',
      adapterVersion: '1.0.0',
      protocolVersion: 'content-surface/1',
      contentKinds: ['rich_text'],
      mimeTypes: [],
      intents: ['edit'],
      environments: ['browser'],
      serviceDependencies: [],
      supportsCollaboration: false,
      unavailableReason: null,
      component: provider,
    };
    const selected = props();
    selected.content = { kind: 'rich_text', identity: 'doc-1', value: {} };
    selected.capabilities = {
      ...capabilities(),
      contentKind: 'rich_text',
      verifiedMimeType: null,
      canView: false,
      canEdit: true,
      state: 'available',
      editorAdapterId: registration.id,
    };
    selected.allowedOperations = ['edit'];
    selected.registry = new ContentToolRegistry([
      {
        moduleKey: 'fixture.editor',
        routes: [],
        contentAdapters: [registration],
      },
    ]);
    const wrapper = mount(ContentEditor, { props: selected });
    await wrapper.find('button').trigger('click');
    expect(wrapper.emitted('dirty-change')?.[0]).toEqual([true]);
    expect(wrapper.emitted('save-request')?.[0]).toEqual([{ text: 'draft' }]);
    expect(wrapper.emitted('save-state')).toBeUndefined();
    wrapper.unmount();
  });
});
