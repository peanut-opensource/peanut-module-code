<template>
  <section class="rich-text-editor">
    <p v-if="error" role="status">{{ error }}</p>
    <div v-if="!readOnly && !error" role="toolbar" aria-label="正文格式">
      <button
        v-for="command in [
          'bold',
          'italic',
          'heading',
          'bullet',
          'ordered',
          'undo',
          'redo',
        ]"
        :key="command"
        type="button"
        @mousedown.prevent="run(command)"
        >{{ command }}</button
      >
      <input
        v-model="link"
        aria-label="链接地址"
        placeholder="https://example.com"
      />
      <button type="button" @click="setLink">设置链接</button>
      <button
        v-if="callbacks?.pickMedia"
        type="button"
        :disabled="picking"
        @click="insertMedia"
        >选择文件素材</button
      >
    </div>
    <div ref="host" class="rich-text-editor__content" />
    <p
      v-if="
        (collaboration?.enabled || collaborationState) && !collaborationBinding
      "
      role="status"
      >协同服务尚未可用；未连接、未保存协同状态。</p
    >
  </section>
</template>

<script setup lang="ts">
  import { onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue';
  import { Editor } from '@tiptap/core';
  import DOMPurify from 'dompurify';
  import Collaboration from '@tiptap/extension-collaboration';
  import { Doc } from 'yjs';
  import type {
    SurfaceCallbacks,
    ContentCollaborationBinding,
  } from '@/modules/peanut-content-tools/src/contracts';
  import type ContentToolRegistry from '@/modules/peanut-content-tools/src/registry';
  import {
    DOCUMENT_VERSION,
    parseRichTextDocument,
    validLink,
    type RichTextDocumentValue,
  } from '../src/document';
  import richTextExtensions from '../src/extensions';
  import type { RichTextCollaborationConfig } from './types';

  const props = withDefaults(
    defineProps<{
      modelValue: RichTextDocumentValue;
      collaborationState?: string;
      collaboration?: RichTextCollaborationConfig | null;
      contextKey?: string;
      readOnly?: boolean;
      callbacks?: SurfaceCallbacks;
      registry?: ContentToolRegistry;
      collaborationBinding?: ContentCollaborationBinding;
    }>(),
    { contextKey: 'standalone', readOnly: false, callbacks: () => ({}) }
  );
  const emit = defineEmits<{
    (event: 'update:modelValue', value: RichTextDocumentValue): void;
    (event: 'update:collaborationState', value: string): void;
    (event: 'ready'): void;
    (event: 'dirty-change', value: boolean): void;
    (event: 'error', code: string): void;
  }>();
  const host = ref<HTMLDivElement>();
  const editor = shallowRef<Editor>();
  const error = ref('');
  const link = ref('');
  const picking = ref(false);
  let mounted = false;
  let picker: AbortController | undefined;
  let boundDocument: Doc | undefined;
  const dispose = () => {
    boundDocument?.off('destroy', dispose);
    boundDocument = undefined;
    picker?.abort();
    picker = undefined;
    picking.value = false;
    editor.value?.destroy();
    editor.value = undefined;
    link.value = '';
  };
  const unavailable = () =>
    !props.collaborationBinding &&
    (!!props.collaboration?.enabled || !!props.collaborationState);
  const envelope = (instance: Editor) =>
    parseRichTextDocument({
      schemaVersion: DOCUMENT_VERSION,
      editorModel: 'tiptap-prosemirror',
      content: instance.getJSON(),
      annotations: [],
    });
  const start = () => {
    dispose();
    error.value = '';
    if (!mounted || !host.value) return;
    if (unavailable()) {
      error.value = '协同模式不可用';
      emit('error', 'RICH_TEXT_COLLABORATION_UNAVAILABLE');
      return;
    }
    try {
      const binding = props.collaborationBinding;
      if (
        binding &&
        (binding.protocol !== 'yjs/1' || !(binding.document instanceof Doc))
      ) {
        throw new Error('RICH_TEXT_COLLABORATION_BINDING_INVALID');
      }
      if (binding && !binding.ready && !binding.frozen) return;
      if (binding?.document instanceof Doc) {
        boundDocument = binding.document;
        boundDocument.on('destroy', dispose);
      }
      const value = parseRichTextDocument(props.modelValue);
      editor.value = new Editor({
        element: host.value,
        editable: !props.readOnly && binding?.writable !== false,
        extensions: [
          ...richTextExtensions({
            callbacks: props.callbacks,
            registry: props.registry,
            contextKey: props.contextKey,
            collaborative: !!binding,
          }),
          ...(binding && binding.document instanceof Doc
            ? [
                Collaboration.configure({
                  document: binding.document,
                  field: 'default',
                }),
              ]
            : []),
        ],
        ...(binding ? {} : { content: value.content }),
        editorProps: {
          attributes: {
            'role': props.readOnly ? 'document' : 'textbox',
            'aria-label': '富文本正文',
          },
          transformPastedHTML: (html) =>
            DOMPurify.sanitize(html, {
              ALLOWED_TAGS: [
                'a',
                'blockquote',
                'br',
                'code',
                'em',
                'h1',
                'h2',
                'h3',
                'h4',
                'h5',
                'h6',
                'li',
                'ol',
                'p',
                'pre',
                'strong',
                's',
                'u',
                'ul',
                'hr',
                'table',
                'tbody',
                'tr',
                'td',
                'th',
              ],
              ALLOWED_ATTR: ['href', 'rel', 'target', 'colspan', 'rowspan'],
            }),
        },
        onUpdate: ({ editor: current }) => {
          if (props.readOnly || unavailable()) return;
          if (props.collaborationBinding) return;
          try {
            const projection = envelope(current);
            emit('update:modelValue', projection);
            emit('dirty-change', true);
          } catch {
            current.setEditable(false);
            error.value = '内容结构无效，无法保存';
            emit('error', 'RICH_TEXT_PROJECTION_INVALID');
          }
        },
      });
      emit('ready');
    } catch {
      dispose();
      error.value = '内容结构或旧媒体引用无法识别，未修改原内容';
      emit('error', 'RICH_TEXT_PROJECTION_INVALID');
    }
  };
  onMounted(() => {
    mounted = true;
    start();
  });
  watch(
    [
      () => props.contextKey,
      () => props.readOnly,
      () => props.collaboration?.enabled,
      () => props.collaborationState,
      () => props.callbacks,
      () => props.registry,
      () => props.collaborationBinding?.document,
      () => props.collaborationBinding?.generation,
      () => props.collaborationBinding?.ready,
      () => props.collaborationBinding?.frozen,
    ],
    start
  );
  watch(
    () => props.collaborationBinding?.writable,
    (writable) =>
      editor.value?.setEditable(!props.readOnly && writable !== false),
    { flush: 'sync' }
  );
  watch(
    () => props.modelValue,
    () => {
      if (!mounted || props.collaborationBinding) return;
      // A rejected projection disposes the instance; corrected input must
      // pass the same validation and read-only/collaboration gates again.
      if (!editor.value || error.value) {
        start();
        return;
      }
      try {
        const value = parseRichTextDocument(props.modelValue);
        if (
          JSON.stringify(value.content) !==
          JSON.stringify(editor.value.getJSON())
        )
          editor.value.commands.setContent(value.content, {
            emitUpdate: false,
          });
      } catch {
        dispose();
        error.value = '内容结构或旧媒体引用无法识别，未修改原内容';
        emit('error', 'RICH_TEXT_PROJECTION_INVALID');
      }
    },
    { deep: true }
  );
  onBeforeUnmount(() => {
    mounted = false;
    dispose();
  });
  const run = (command: string) => {
    if (props.readOnly || !editor.value?.isEditable) return;
    const chain = editor.value.chain().focus();
    const commands: Record<string, () => void> = {
      bold: () => chain.toggleBold().run(),
      italic: () => chain.toggleItalic().run(),
      heading: () => chain.toggleHeading({ level: 2 }).run(),
      bullet: () => chain.toggleBulletList().run(),
      ordered: () => chain.toggleOrderedList().run(),
      undo: () => chain.undo().run(),
      redo: () => chain.redo().run(),
    };
    commands[command]?.();
  };
  const setLink = () => {
    if (props.readOnly || !editor.value?.isEditable) return;
    if (!validLink(link.value.trim())) {
      emit('error', 'RICH_TEXT_LINK_INVALID');
      return;
    }
    editor.value
      .chain()
      .focus()
      .extendMarkRange('link')
      .setLink({
        href: link.value.trim(),
        target: '_blank',
        rel: 'noopener noreferrer',
        class: null,
      })
      .run();
  };
  const insertMedia = async () => {
    if (
      props.readOnly ||
      picking.value ||
      !editor.value?.isEditable ||
      !props.callbacks.pickMedia
    )
      return;
    const current = new AbortController();
    picker = current;
    picking.value = true;
    try {
      const reference = await props.callbacks.pickMedia(current.signal);
      if (
        current.signal.aborted ||
        picker !== current ||
        !reference ||
        !editor.value?.isEditable
      )
        return;
      const node = { type: 'media', attrs: reference };
      parseRichTextDocument({
        ...props.modelValue,
        content: { type: 'doc', content: [node] },
      });
      editor.value.chain().focus().insertContent(node).run();
    } catch {
      if (!current.signal.aborted) emit('error', 'RICH_TEXT_MEDIA_INVALID');
    } finally {
      if (picker === current) {
        picking.value = false;
        picker = undefined;
      }
    }
  };
</script>

<style scoped>
  .rich-text-editor__content {
    min-height: 180px;
    padding: 12px;
    border: 1px solid var(--el-border-color);
  }
  .rich-text-editor__content :deep(.ProseMirror) {
    outline: none;
  }
</style>
