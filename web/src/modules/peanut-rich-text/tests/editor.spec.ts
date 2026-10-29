// @vitest-environment happy-dom
import { describe, expect, it, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { Editor } from '@tiptap/core';
import ContentEditor from '@/modules/official-content-tools/src/ContentEditor.vue';
import FileAssetSelector from '@/modules/official-file/optional-runtime/src/FileAssetSelector.vue';
import ContentToolRegistry from '@/modules/official-content-tools/src/registry';
import type { SurfaceCallbacks } from '@/modules/official-content-tools/src/contracts';
import { emptyDocument, parseRichTextDocument } from '../src/document';
import richTextExtensions from '../src/extensions';
import RichTextEditor from '../components/RichTextEditor.vue';
import contribution from '../contribution';
import RichTextFilePicker from '../components/RichTextFilePicker.vue';
import RichTextViewer from '../components/RichTextViewer.vue';

const required = <T>(value: T | undefined): T => {
  if (value === undefined) throw new Error('Expected rendered test control');
  return value;
};

const reference = {
  fileKey: `file_${'a'.repeat(32)}`,
  kind: 'image',
  variantKey: 'thumbnail',
};
const documentWith = (content: unknown[]) => ({
  ...emptyDocument(),
  content: { type: 'doc', content },
});
describe('RichText public projection semantics', () => {
  it.each([
    'https:example.com',
    'https:/example.com',
    'https:///example.com',
    'https:\\example.com',
  ])(
    'rejects hostless persisted link %s before browser normalization',
    (href) => {
      expect(() =>
        parseRichTextDocument(
          documentWith([
            {
              type: 'paragraph',
              content: [
                {
                  type: 'text',
                  text: 'link',
                  marks: [{ type: 'link', attrs: { href } }],
                },
              ],
            },
          ])
        )
      ).toThrow('RICH_TEXT_PROJECTION_INVALID');
    }
  );
  it.each([
    'https://example.com/path?x=1#part',
    'HTTP://example.com',
    'mailto:member@example.com',
  ])('preserves the accepted raw link %s in actual Tiptap output', (href) => {
    const value = parseRichTextDocument(
      documentWith([
        {
          type: 'paragraph',
          content: [
            {
              type: 'text',
              text: 'link',
              marks: [{ type: 'link', attrs: { href } }],
            },
          ],
        },
      ])
    );
    const instance = new Editor({
      extensions: richTextExtensions({ callbacks: {}, contextKey: 'link' }),
      content: value.content,
    });
    const actual = parseRichTextDocument({
      ...value,
      content: instance.getJSON(),
    });
    expect(
      actual.content.content?.[0].content?.[0].marks?.[0].attrs?.href
    ).toBe(href);
    instance.destroy();
  });
  it('renders validated color as CSS in actual Tiptap and the public viewer', async () => {
    const value = parseRichTextDocument(
      documentWith([
        {
          type: 'paragraph',
          content: [
            {
              type: 'text',
              text: 'colored',
              marks: [{ type: 'textStyle', attrs: { color: '#ff0000' } }],
            },
          ],
        },
      ])
    );
    const instance = new Editor({
      extensions: richTextExtensions({ callbacks: {}, contextKey: 'color' }),
      content: value.content,
    });
    expect(instance.getHTML()).toContain('style="color:');
    expect(instance.getHTML()).not.toContain(' color=');
    expect(
      instance.getJSON().content?.[0].content?.[0].marks?.[0].attrs?.color
    ).toBe('#ff0000');
    instance.destroy();
    const wrapper = mount(RichTextViewer, {
      props: { modelValue: value, contextKey: 'color' },
    });
    await flushPromises();
    expect(
      (wrapper.find('.ProseMirror span').element as HTMLElement).style.color
    ).toBe('#ff0000');
    expect(wrapper.emitted('update:modelValue')).toBeUndefined();
    wrapper.unmount();
  });
  it('rejects CSS injection and never renders it even for direct Tiptap input', () => {
    const content = [
      {
        type: 'paragraph',
        content: [
          {
            type: 'text',
            text: 'unsafe',
            marks: [
              {
                type: 'textStyle',
                attrs: { color: '#fff; background: url(https://evil.test)' },
              },
            ],
          },
        ],
      },
    ];
    expect(() => parseRichTextDocument(documentWith(content))).toThrow(
      'RICH_TEXT_PROJECTION_INVALID'
    );
    const instance = new Editor({
      extensions: richTextExtensions({
        callbacks: {},
        contextKey: 'unsafe-color',
      }),
      content: { type: 'doc', content },
    });
    expect(instance.getHTML()).not.toContain('style=');
    expect(instance.getHTML()).not.toContain('evil.test');
    instance.destroy();
  });
  it('preserves canonical media keys and table/link structure in the real Tiptap schema', () => {
    const value = parseRichTextDocument(
      documentWith([
        { type: 'media', attrs: reference },
        {
          type: 'table',
          content: [
            {
              type: 'tableRow',
              content: [
                {
                  type: 'tableCell',
                  attrs: { colspan: 1, rowspan: 1, colwidth: null },
                  content: [
                    {
                      type: 'paragraph',
                      content: [
                        {
                          type: 'text',
                          text: 'cell',
                          marks: [{ type: 'bold' }],
                        },
                      ],
                    },
                  ],
                },
              ],
            },
          ],
        },
      ])
    );
    const instance = new Editor({
      extensions: richTextExtensions({ callbacks: {}, contextKey: 'test' }),
      content: value.content,
    });
    const actual = parseRichTextDocument({
      ...value,
      content: instance.getJSON(),
    });
    expect(actual.content.content?.[0].attrs).toMatchObject(reference);
    expect(actual.content.content?.[1].type).toBe('table');
    instance.destroy();
  });
  it.each([
    { type: 'mediaPlaceholder', attrs: { ref: 'asset:image:001' } },
    { type: 'image', attrs: { fileKey: 'https://evil.test/a', kind: 'image' } },
    {
      type: 'image',
      attrs: { ...reference, src: '/api/storage/delivery?t=secret' },
    },
    {
      type: 'paragraph',
      content: [
        {
          type: 'text',
          text: 'bad',
          // eslint-disable-next-line no-script-url -- Deliberate attack fixture must be rejected by the public projection.
          marks: [{ type: 'link', attrs: { href: 'javascript:alert(1)' } }],
        },
      ],
    },
    {
      type: 'paragraph',
      content: [
        {
          type: 'text',
          text: 'bad',
          marks: [
            {
              type: 'link',
              attrs: { href: 'https://host/api/storage/delivery?t=secret' },
            },
          ],
        },
      ],
    },
    { type: 'paragraph', attrs: { onclick: 'alert(1)' } },
    { type: 'table', content: [] },
  ])(
    'rejects unsafe/legacy projection without accepting a temporary URL',
    (node) => {
      expect(() => parseRichTextDocument(documentWith([node]))).toThrow(
        'RICH_TEXT_PROJECTION_INVALID'
      );
    }
  );
});
describe('real RichText lifecycle and callbacks', () => {
  it('viewer never emits updates or saves', async () => {
    const wrapper = mount(RichTextViewer, {
      props: { modelValue: emptyDocument(), contextKey: 'tenant/member' },
    });
    await flushPromises();
    expect(wrapper.find('[role="document"]').exists()).toBe(true);
    expect(wrapper.find('[role="toolbar"]').exists()).toBe(false);
    expect(wrapper.emitted('update:modelValue')).toBeUndefined();
    expect(wrapper.emitted('save-request')).toBeUndefined();
    wrapper.unmount();
  });
  it.each([true, false])(
    'recovers the same read-only viewer from rejected content (initially invalid: %s)',
    async (initiallyInvalid) => {
      const rejected = {
        ...emptyDocument(),
        content: {
          type: 'doc',
          content: [
            { type: 'mediaPlaceholder', attrs: { ref: 'unsafe-legacy' } },
          ],
        },
      };
      const corrected = parseRichTextDocument(
        documentWith([
          {
            type: 'paragraph',
            content: [{ type: 'text', text: 'Recovered safely' }],
          },
        ])
      );
      const wrapper = mount(RichTextViewer, {
        props: {
          modelValue: initiallyInvalid ? rejected : emptyDocument(),
          contextKey: 'same-identity',
        },
      });
      try {
        await flushPromises();
        if (!initiallyInvalid) {
          await wrapper.setProps({ modelValue: rejected });
          await flushPromises();
        }
        expect(wrapper.text()).toContain('未修改原内容');
        expect(wrapper.find('.ProseMirror').exists()).toBe(false);
        expect(wrapper.html()).not.toContain('unsafe-legacy');
        await wrapper.setProps({ modelValue: corrected });
        await flushPromises();
        expect(wrapper.text()).toContain('Recovered safely');
        expect(wrapper.text()).not.toContain('未修改原内容');
        expect(wrapper.find('[role="document"]').exists()).toBe(true);
        expect(wrapper.find('[role="toolbar"]').exists()).toBe(false);
        const editorWrapper = wrapper.findComponent(RichTextEditor);
        expect(editorWrapper.emitted('ready')?.length).toBe(
          initiallyInvalid ? 1 : 2
        );
        expect(editorWrapper.emitted('update:modelValue')).toBeUndefined();
        expect(editorWrapper.emitted('dirty-change')).toBeUndefined();
        expect(wrapper.emitted('save-request')).toBeUndefined();
      } finally {
        wrapper.unmount();
      }
    }
  );
  it('uses the owner File picker and preserves stable reference without saving addresses', async () => {
    const wrapper = mount(RichTextEditor, {
      props: {
        modelValue: emptyDocument(),
        contextKey: 'a',
        callbacks: {
          pickMedia: vi.fn(
            async () =>
              reference as {
                fileKey: string;
                kind: 'image';
                variantKey: string;
              }
          ),
        },
      },
    });
    await flushPromises();
    await required(
      wrapper
        .findAll('button')
        .find((button) => button.text() === '选择文件素材')
    ).trigger('click');
    await flushPromises();
    const update = wrapper.emitted('update:modelValue')?.[0][0];
    expect(JSON.stringify(update)).toContain(reference.fileKey);
    expect(JSON.stringify(update)).toContain('thumbnail');
    expect(wrapper.emitted('update:collaborationState')).toBeUndefined();
    wrapper.unmount();
  });
  it('identity change aborts late picker and destroys pending media resolution', async () => {
    let pickerSignal: AbortSignal | undefined;
    let mediaSignal: AbortSignal | undefined;
    const callbacks: SurfaceCallbacks = {
      pickMedia: (signal) => {
        pickerSignal = signal;
        return new Promise<never>(vi.fn());
      },
      resolveMedia: (_ref, signal) => {
        mediaSignal = signal;
        return new Promise<never>(vi.fn());
      },
    };
    const value = parseRichTextDocument(
      documentWith([{ type: 'media', attrs: reference }])
    );
    const wrapper = mount(RichTextEditor, {
      props: {
        modelValue: value,
        contextKey: 'identity-1',
        callbacks,
        registry: new ContentToolRegistry([]),
      },
    });
    await flushPromises();
    await required(
      wrapper
        .findAll('button')
        .find((button) => button.text() === '选择文件素材')
    ).trigger('click');
    const oldMedia = mediaSignal;
    await wrapper.setProps({ contextKey: 'identity-2' } as Parameters<
      typeof wrapper.setProps
    >[0]);
    expect(pickerSignal?.aborted).toBe(true);
    expect(oldMedia?.aborted).toBe(true);
    wrapper.unmount();
    expect(mediaSignal?.aborted).toBe(true);
  });
  it('collaboration unavailable never connects, emits state or seeds JSON', async () => {
    const wrapper = mount(RichTextEditor, {
      props: {
        modelValue: emptyDocument(),
        collaboration: {
          enabled: true,
          url: 'wss://untrusted.test',
          document_name: 'old',
          token: 'old',
          expires_at: null,
        },
      },
    });
    await flushPromises();
    expect(wrapper.text()).toContain('协同模式不可用');
    await wrapper.setProps({ modelValue: emptyDocument() });
    await flushPromises();
    expect(wrapper.text()).toContain('协同模式不可用');
    expect(wrapper.find('.ProseMirror').exists()).toBe(false);
    expect(wrapper.emitted('update:collaborationState')).toBeUndefined();
    expect(wrapper.emitted('update:modelValue')).toBeUndefined();
    wrapper.unmount();
  });
  it('legacy input shows placeholder without rewriting original data', async () => {
    const wrapper = mount(RichTextEditor, {
      props: {
        modelValue: documentWith([
          { type: 'mediaPlaceholder', attrs: { ref: 'asset:image:001' } },
        ]) as ReturnType<typeof emptyDocument>,
      },
    });
    await flushPromises();
    expect(wrapper.text()).toContain('未修改原内容');
    expect(wrapper.emitted('update:modelValue')).toBeUndefined();
    wrapper.unmount();
  });
});

describe('actual contributed adapter and File picker', () => {
  it('uses the same contributed tool with an explicit save request, never calls owner save', async () => {
    const save = vi.fn();
    const wrapper = mount(ContentEditor, {
      props: {
        content: {
          kind: 'rich_text',
          identity: 'doc1',
          value: emptyDocument(),
        },
        contextKey: 'tenant:member',
        capabilities: {
          contentKind: 'rich_text',
          verifiedMimeType: null,
          viewerAdapterId: 'rich-text.tiptap',
          editorAdapterId: 'rich-text.tiptap',
          canView: true,
          canEdit: true,
          canDownloadOriginal: false,
          state: 'available',
          unavailableReason: null,
          capabilityRevision: '1',
        },
        allowedOperations: ['view', 'edit'],
        callbacks: {
          save,
          pickMedia: async () =>
            reference as { fileKey: string; kind: 'image'; variantKey: string },
        },
        registry: new ContentToolRegistry([contribution]),
      },
    });
    await flushPromises();
    await vi.waitFor(() =>
      expect(wrapper.find('.rich-text-editor').exists()).toBe(true)
    );
    await required(
      wrapper
        .findAll('button')
        .find((button) => button.text() === '选择文件素材')
    ).trigger('click');
    await flushPromises();
    expect(wrapper.emitted('save-request')).toBeUndefined();
    await required(
      wrapper.findAll('button').find((button) => button.text() === '请求保存')
    ).trigger('click');
    expect(wrapper.emitted('save-request')).toHaveLength(1);
    expect(save).not.toHaveBeenCalled();
    wrapper.unmount();
  });
  it.each(['link', 'media'])(
    'keeps valid dirty content saveable after a rejected %s operation',
    async (operation) => {
      const save = vi.fn();
      let rejectMedia = false;
      const wrapper = mount(ContentEditor, {
        props: {
          content: {
            kind: 'rich_text',
            identity: 'same-doc',
            value: emptyDocument(),
          },
          contextKey: 'same-identity',
          capabilities: {
            contentKind: 'rich_text',
            verifiedMimeType: null,
            viewerAdapterId: 'rich-text.tiptap',
            editorAdapterId: 'rich-text.tiptap',
            canView: true,
            canEdit: true,
            canDownloadOriginal: false,
            state: 'available',
            unavailableReason: null,
            capabilityRevision: '1',
          },
          allowedOperations: ['view', 'edit'],
          callbacks: {
            save,
            pickMedia: async () => ({
              fileKey: rejectMedia ? 'invalid-file-key' : reference.fileKey,
              kind: 'image' as const,
              variantKey: 'thumbnail',
            }),
          },
          registry: new ContentToolRegistry([contribution]),
        },
      });
      try {
        await flushPromises();
        await vi.waitFor(() =>
          expect(wrapper.find('.rich-text-editor').exists()).toBe(true)
        );
        const button = (label: string) =>
          required(
            wrapper.findAll('button').find((entry) => entry.text() === label)
          );
        await button('选择文件素材').trigger('click');
        await flushPromises();
        expect(button('请求保存').attributes('disabled')).toBeUndefined();
        if (operation === 'link') {
          // Deliberate operation-level rejection must not poison valid content.
          await wrapper
            .find('[aria-label="链接地址"]')
            .setValue('javascript:alert(1)');
          await button('设置链接').trigger('click');
        } else {
          rejectMedia = true;
          await button('选择文件素材').trigger('click');
          await flushPromises();
        }
        expect(wrapper.emitted('error')).toContainEqual([
          operation === 'link'
            ? 'RICH_TEXT_LINK_INVALID'
            : 'RICH_TEXT_MEDIA_INVALID',
        ]);
        expect(button('请求保存').attributes('disabled')).toBeUndefined();
        await button('请求保存').trigger('click');
        const saved = wrapper.emitted('save-request');
        expect(saved).toHaveLength(1);
        const projection = parseRichTextDocument(saved?.[0][0]);
        expect(JSON.stringify(projection)).toContain(reference.fileKey);
        expect(JSON.stringify(projection)).not.toContain('javascript:');
        expect(JSON.stringify(projection)).not.toContain('invalid-file-key');
        expect(save).not.toHaveBeenCalled();
        await wrapper.setProps({
          content: {
            kind: 'rich_text',
            identity: 'same-doc',
            value: { ...emptyDocument(), content: { type: 'unrecognized' } },
          },
        });
        await flushPromises();
        expect(wrapper.find('.ProseMirror').exists()).toBe(false);
        expect(button('请求保存').attributes('disabled')).toBeDefined();
      } finally {
        wrapper.unmount();
      }
    }
  );
  it('renders the existing File selector, rejects denied selection, and emits only stable references', async () => {
    let canRead = false;
    const wrapper = mount(RichTextFilePicker, {
      props: { contextKey: 'a', canRead: () => canRead },
    });
    await flushPromises();
    const asset = {
      materialId: 1,
      fileKey: reference.fileKey,
      originalName: 'image.png',
      mediaType: 'image/png',
      width: 1,
      height: 1,
      previewUri: '/temporary-preview',
      variants: [],
    };
    wrapper.findComponent(FileAssetSelector).vm.$emit('select', asset);
    expect(wrapper.emitted('select')).toBeUndefined();
    canRead = true;
    wrapper.findComponent(FileAssetSelector).vm.$emit('select', asset);
    expect(wrapper.emitted('select')?.[0][0]).toEqual({
      fileKey: reference.fileKey,
      kind: 'image',
      title: 'image.png',
    });
    wrapper.unmount();
  });
});
