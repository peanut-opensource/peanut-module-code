import { defineAsyncComponent } from 'vue';

export const DocumentViewer = defineAsyncComponent(
  () => import('../components/DocumentViewer.vue')
);
export type { DocumentSurfaceOptions, DocumentDetail } from './contracts';
