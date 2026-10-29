import { Extension, Mark, Node } from '@tiptap/core';
import StarterKit from '@tiptap/starter-kit';
import { h, render } from 'vue';
import ContentViewer from '@/modules/peanut-content-tools/src/ContentViewer.vue';
import type ContentToolRegistry from '@/modules/peanut-content-tools/src/registry';
import type {
  StableMediaReference,
  SurfaceCallbacks,
} from '@/modules/peanut-content-tools/src/contracts';

const richTextExtensions = (options: {
  callbacks: SurfaceCallbacks;
  registry?: ContentToolRegistry;
  contextKey: string;
  collaborative?: boolean;
}) => {
  const mediaNodes = ['image', 'video', 'audio', 'file', 'media'].map((name) =>
    Node.create({
      name,
      group: 'block',
      atom: true,
      addAttributes: () => ({
        fileKey: { default: null },
        kind: { default: 'file' },
        variantKey: { default: undefined },
        alt: { default: undefined },
        title: { default: undefined },
      }),
      // Clipboard HTML cannot create File references. Only the authorized picker inserts them.
      parseHTML: () => [],
      renderHTML: ({ node }) => [
        'figure',
        { 'data-file-key': node.attrs.fileKey },
        ['span', {}, node.attrs.alt || node.attrs.title || '受控媒体'],
      ],
      addNodeView:
        () =>
        ({ node }) => {
          const { registry } = options;
          const dom = document.createElement('figure');
          dom.textContent =
            node.attrs.alt || node.attrs.title || '媒体预览不可用';
          const controller = new AbortController();
          let disposed = false;
          const reference = Object.fromEntries(
            Object.entries(node.attrs).filter(
              ([, v]) => v !== undefined && v !== null
            )
          ) as unknown as StableMediaReference;
          if (
            registry &&
            options.callbacks.resolveMedia &&
            /^file_[0-9a-f]{32}$/.test(reference.fileKey)
          ) {
            options.callbacks
              .resolveMedia(reference, controller.signal)
              .then((surface) => {
                if (disposed || controller.signal.aborted) return;
                if (
                  surface.content.fileKey !== reference.fileKey ||
                  !surface.capabilities.canView
                )
                  throw new Error('RICH_TEXT_MEDIA_ACCESS_INVALID');
                render(
                  h(ContentViewer, {
                    ...surface,
                    registry,
                    contextKey: options.contextKey,
                  }),
                  dom
                );
              })
              .catch(() => {
                if (!disposed) dom.textContent = '媒体预览不可用';
              });
          }
          return {
            dom,
            destroy: () => {
              disposed = true;
              controller.abort();
              render(null, dom);
            },
          };
        },
    })
  );
  const tables = [
    Node.create({
      name: 'table',
      group: 'block',
      content: 'tableRow+',
      parseHTML: () => [{ tag: 'table' }],
      renderHTML: () => ['table', ['tbody', 0]],
    }),
    Node.create({
      name: 'tableRow',
      content: '(tableCell | tableHeader)+',
      parseHTML: () => [{ tag: 'tr' }],
      renderHTML: () => ['tr', 0],
    }),
    ...['tableCell', 'tableHeader'].map((name) =>
      Node.create({
        name,
        content: 'block+',
        addAttributes: () => ({
          colspan: { default: 1 },
          rowspan: { default: 1 },
          colwidth: { default: null },
        }),
        parseHTML: () => [{ tag: name === 'tableCell' ? 'td' : 'th' }],
        renderHTML: ({ HTMLAttributes }) => [
          name === 'tableCell' ? 'td' : 'th',
          HTMLAttributes,
          0,
        ],
      })
    ),
  ];
  return [
    StarterKit.extend({
      addExtensions() {
        return (this.parent?.() ?? []).map((extension) =>
          extension instanceof Mark && extension.name === 'link'
            ? extension.extend({
                addAttributes() {
                  return Object.fromEntries(
                    Object.entries(this.parent?.() ?? {}).filter(
                      ([name]) => name !== 'title'
                    )
                  );
                },
              })
            : extension
        );
      },
    })
      .configure({
        link: {
          autolink: false,
          openOnClick: false,
          HTMLAttributes: {
            rel: 'noopener noreferrer',
            target: '_blank',
            class: null,
          },
        },
      })
      .configure({ undoRedo: options.collaborative ? false : {} }),
    Extension.create({
      name: 'alignment',
      addGlobalAttributes: () => [
        {
          types: ['paragraph', 'heading'],
          attributes: {
            textAlign: {
              default: null,
              renderHTML: (attrs) =>
                attrs.textAlign
                  ? { style: `text-align: ${attrs.textAlign}` }
                  : {},
            },
          },
        },
      ],
    }),
    Mark.create({
      name: 'textStyle',
      addAttributes: () => ({
        color: {
          default: null,
          renderHTML: (attrs) =>
            typeof attrs.color === 'string' &&
            /^#[0-9a-fA-F]{3,8}$/.test(attrs.color)
              ? { style: `color: ${attrs.color}` }
              : {},
        },
      }),
      parseHTML: () => [{ tag: 'span' }],
      renderHTML: ({ HTMLAttributes }) => ['span', HTMLAttributes, 0],
    }),
    ...tables,
    ...mediaNodes,
  ];
};

export default richTextExtensions;
