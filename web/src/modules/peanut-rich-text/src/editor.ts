import { defineAsyncComponent } from 'vue';

export {
  previewRichTextHtml,
  type RichTextHtmlImportLimitation,
  type RichTextHtmlImportPreview,
} from './html-import';

export const RichTextEditor = defineAsyncComponent(
  () => import('../components/RichTextEditor.vue')
);

export type { RichTextCollaborationConfig } from '../components/types';
export type { RichTextDocumentValue } from './document';
export { createCollaborationProvider } from './collaboration/provider';
export type { ProviderOptions } from './collaboration/provider';
export type { ContentCollaborationBinding as CollaborationBinding } from '@peanut-admin/peanut-content-tools/surface';
