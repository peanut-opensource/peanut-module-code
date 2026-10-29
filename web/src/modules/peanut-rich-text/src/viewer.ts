import { defineAsyncComponent } from 'vue';

export const RichTextViewer = defineAsyncComponent(
  () => import('../components/RichTextViewer.vue')
);
export type { RichTextDocumentValue } from './document';
