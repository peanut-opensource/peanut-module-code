<template>
  <section class="rich-text-editor">
    <p v-if="error" role="status">{{ error }}</p>
    <div
      v-if="!readOnly && !error"
      class="rich-text-editor__toolbar"
      role="group"
      aria-label="正文格式"
    >
      <div
        class="rich-text-editor__tool-group"
        role="group"
        aria-label="文字格式与历史"
      >
        <button
          v-for="command in toolbarCommands"
          :key="command.id"
          type="button"
          :disabled="!toolbarState[command.id].enabled"
          :aria-pressed="
            command.toggle ? toolbarState[command.id].active : undefined
          "
          @mousedown.prevent
          @click="run(command.id)"
          >{{ command.label }}</button
        >
      </div>
      <div
        class="rich-text-editor__tool-group rich-text-editor__links"
        role="group"
        aria-label="正文链接"
      >
        <input
          v-model="link"
          :disabled="!editable"
          aria-label="链接地址"
          placeholder="https://example.com"
          @keydown.enter.prevent="setLink"
        />
        <button type="button" :disabled="!editable" @click="setLink"
          >设置链接</button
        >
        <button
          type="button"
          :disabled="!editable || !linkActive"
          @mousedown.prevent
          @click="removeLink"
          >移除链接</button
        >
      </div>
      <button
        v-if="callbacks?.pickMedia"
        type="button"
        :disabled="picking || !editable"
        @click="insertMedia"
        >选择文件素材</button
      >
    </div>
    <p v-if="linkError" role="status">{{ linkError }}</p>
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
  import {
    computed,
    onBeforeUnmount,
    onMounted,
    ref,
    shallowRef,
    toRaw,
    watch,
  } from 'vue';
  import { Editor } from '@tiptap/core';
  import DOMPurify from 'dompurify';
  import Collaboration from '@tiptap/extension-collaboration';
  import { Doc } from 'yjs';
  import type {
    SurfaceCallbacks,
    ContentCollaborationBinding,
  } from '@peanut-admin/peanut-content-tools/surface';
  import type { ContentToolRegistry } from '@peanut-admin/peanut-content-tools/surface';
  import {
    DOCUMENT_VERSION,
    parseRichTextDocument,
    validLink,
    type RichTextDocumentValue,
    type TextAppendBinding,
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
      callbacks?: SurfaceCallbacks & TextAppendBinding;
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
  const linkError = ref('');
  const toolbarCommands = [
    { id: 'bold', label: '加粗', toggle: true },
    { id: 'italic', label: '斜体', toggle: true },
    { id: 'heading', label: '二级标题', toggle: true },
    { id: 'bullet', label: '无序列表', toggle: true },
    { id: 'ordered', label: '有序列表', toggle: true },
    { id: 'undo', label: '撤销', toggle: false },
    { id: 'redo', label: '重做', toggle: false },
  ] as const;
  type ToolbarCommand = (typeof toolbarCommands)[number]['id'];
  const toolbarState = ref<
    Record<ToolbarCommand, { enabled: boolean; active: boolean }>
  >({
    bold: { enabled: false, active: false },
    italic: { enabled: false, active: false },
    heading: { enabled: false, active: false },
    bullet: { enabled: false, active: false },
    ordered: { enabled: false, active: false },
    undo: { enabled: false, active: false },
    redo: { enabled: false, active: false },
  });
  const linkActive = ref(false);
  const editable = computed(
    () => !props.readOnly && toolbarState.value.bold.enabled
  );
  const updateToolbar = (current: Editor) => {
    const enabled = current.isEditable;
    toolbarState.value = {
      bold: { enabled, active: current.isActive('bold') },
      italic: { enabled, active: current.isActive('italic') },
      heading: { enabled, active: current.isActive('heading', { level: 2 }) },
      bullet: { enabled, active: current.isActive('bulletList') },
      ordered: { enabled, active: current.isActive('orderedList') },
      undo: { enabled: enabled && current.can().undo(), active: false },
      redo: { enabled: enabled && current.can().redo(), active: false },
    };
    linkActive.value = current.isActive('link');
  };
  let mounted = false;
  let picker: AbortController | undefined;
  let boundDocument: Doc | undefined;
  let emittedProjection: RichTextDocumentValue | undefined;
  let releaseTextAppender: (() => void) | undefined;
  const dispose = () => {
    releaseTextAppender?.();
    releaseTextAppender = undefined;
    emittedProjection = undefined;
    boundDocument?.off('destroy', dispose);
    boundDocument = undefined;
    picker?.abort();
    picker = undefined;
    picking.value = false;
    editor.value?.destroy();
    editor.value = undefined;
    link.value = '';
    linkError.value = '';
    linkActive.value = false;
    Object.values(toolbarState.value).forEach((state) => {
      state.enabled = false;
      state.active = false;
    });
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
      const document = binding?.document;
      if (document instanceof Doc) {
        boundDocument = document;
        document.on('destroy', dispose);
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
        onTransaction: ({ editor: current }) => updateToolbar(current),
        onUpdate: ({ editor: current }) => {
          if (props.readOnly || unavailable()) return;
          if (props.collaborationBinding) return;
          try {
            const projection = envelope(current);
            emittedProjection = projection;
            emit('update:modelValue', projection);
            emit('dirty-change', true);
          } catch {
            current.setEditable(false);
            error.value = '内容结构无效，无法保存';
            emit('error', 'RICH_TEXT_PROJECTION_INVALID');
          }
        },
      });
      updateToolbar(editor.value);
      const instance = editor.value;
      if (!props.readOnly && props.callbacks.registerTextAppender) {
        releaseTextAppender = props.callbacks.registerTextAppender((text) => {
          const live = props.collaborationBinding;
          if (
            editor.value !== instance || instance.isDestroyed || !instance.isEditable ||
            props.readOnly || (live && (!live.ready || live.frozen || !live.writable)) ||
            typeof text !== 'string' || !text.trim()
          ) return false;
          try {
            const { state, schema } = instance;
            const paragraphs = text.split(/\r\n|\r|\n/).map(line =>
              schema.nodes.paragraph.create(null, line ? schema.text(line) : undefined)
            );
            const end = state.doc.content.size;
            // Validate the entire prospective document before dispatching one undoable edit.
            const emptyPlaceholder = state.doc.childCount === 1 &&
              state.doc.firstChild?.eq(schema.nodes.paragraph.create());
            const from = emptyPlaceholder ? 0 : end;
            const tr = state.tr.replaceWith(from, end, paragraphs);
            parseRichTextDocument({ ...props.modelValue, content: tr.doc.toJSON() });
            instance.view.dispatch(tr);
            return true;
          } catch {
            return false;
          }
        });
      }
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
    (writable) => {
      const current = editor.value;
      if (!current) return;
      current.setEditable(!props.readOnly && writable !== false);
      updateToolbar(current);
    },
    { flush: 'sync' }
  );
  watch(
    () => props.modelValue,
    (next) => {
      // Documents are replaced as a whole; only our own validated echo skips parsing.
      const localEcho =
        emittedProjection !== undefined && toRaw(next) === emittedProjection;
      emittedProjection = undefined;
      if (!mounted || props.collaborationBinding) return;
      if (localEcho) return;
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
    }
  );
  onBeforeUnmount(() => {
    mounted = false;
    dispose();
  });
  const run = (command: ToolbarCommand) => {
    if (props.readOnly || !editor.value?.isEditable) return;
    const chain = editor.value.chain().focus();
    const commands: Record<ToolbarCommand, () => void> = {
      bold: () => chain.toggleBold().run(),
      italic: () => chain.toggleItalic().run(),
      heading: () => chain.toggleHeading({ level: 2 }).run(),
      bullet: () => chain.toggleBulletList().run(),
      ordered: () => chain.toggleOrderedList().run(),
      undo: () => chain.undo().run(),
      redo: () => chain.redo().run(),
    };
    commands[command]();
  };
  const setLink = () => {
    if (props.readOnly || !editor.value?.isEditable) return;
    if (!validLink(link.value.trim())) {
      linkError.value = '请输入有效的 http、https 或 mailto 链接。';
      return;
    }
    linkError.value = '';
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
  const removeLink = () => {
    if (props.readOnly || !editor.value?.isEditable) return;
    editor.value.chain().focus().extendMarkRange('link').unsetLink().run();
    link.value = '';
    linkError.value = '';
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
  .rich-text-editor {
    min-width: 0;
    max-width: 100%;
    overflow-wrap: anywhere;
  }
  .rich-text-editor__toolbar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 12px;
    margin-bottom: 12px;
    padding: 12px;
    border: 1px solid var(--el-border-color-lighter, #ebeef5);
    border-radius: var(--el-border-radius-base, 4px);
    background: var(--el-fill-color-light, #f5f7fa);
  }
  .rich-text-editor__tool-group {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px;
    min-width: 0;
    max-width: 100%;
  }
  .rich-text-editor__links {
    flex: 1 1 320px;
  }
  .rich-text-editor__toolbar :is(button, input) {
    box-sizing: border-box;
    min-width: 0;
    max-width: 100%;
    min-height: 32px;
    margin: 0;
    padding: 5px 12px;
    border: 1px solid var(--el-border-color, #dcdfe6);
    border-radius: var(--el-border-radius-base, 4px);
    color: var(--el-text-color-regular, #606266);
    background: var(--el-bg-color, #fff);
    font: inherit;
    font-size: var(--el-font-size-base, 14px);
    line-height: 20px;
  }
  .rich-text-editor__toolbar button {
    cursor: pointer;
    white-space: normal;
    overflow-wrap: anywhere;
  }
  .rich-text-editor__toolbar input {
    flex: 1 1 180px;
    width: 180px;
  }
  .rich-text-editor__toolbar input::placeholder {
    color: var(--el-text-color-placeholder, #a8abb2);
  }
  .rich-text-editor__toolbar button:not(:disabled):hover {
    border-color: var(--el-color-primary-light-5, #a0cfff);
    color: var(--el-color-primary, #409eff);
    background: var(--el-color-primary-light-9, #ecf5ff);
  }
  .rich-text-editor__toolbar button:not(:disabled):active {
    border-color: var(--el-color-primary, #409eff);
    color: var(--el-color-primary-dark-2, #337ecc);
    background: var(--el-color-primary-light-8, #d9ecff);
  }
  .rich-text-editor__toolbar :is(button, input):disabled {
    cursor: not-allowed;
    border-color: var(--el-disabled-border-color, #e4e7ed);
    color: var(--el-disabled-text-color, #a8abb2);
    background: var(--el-disabled-bg-color, #f5f7fa);
  }
  .rich-text-editor__toolbar :is(button, input):focus-visible {
    outline: 2px solid var(--el-color-primary, #409eff);
    outline-offset: 2px;
  }
  .rich-text-editor__toolbar input:not(:disabled):focus {
    border-color: var(--el-color-primary, #409eff);
  }
  .rich-text-editor__toolbar button[aria-pressed='true']:not(:disabled) {
    border-color: var(--el-color-primary-light-5, #a0cfff);
    color: var(--el-color-primary, #409eff);
    background: var(--el-color-primary-light-9, #ecf5ff);
  }
  .rich-text-editor__content {
    box-sizing: border-box;
    min-width: 0;
    max-width: 100%;
    min-height: 180px;
    overflow-x: auto;
    padding: 12px;
    border: 1px solid var(--el-border-color);
  }
  .rich-text-editor__content :deep(.ProseMirror) {
    min-width: 0;
    overflow-wrap: anywhere;
  }
  .rich-text-editor__content :deep(.ProseMirror:focus-visible) {
    outline: 2px solid var(--el-color-primary, #409eff);
    outline-offset: -2px;
  }
  .rich-text-editor__content :deep(pre) {
    max-width: 100%;
    overflow-x: auto;
  }
</style>
