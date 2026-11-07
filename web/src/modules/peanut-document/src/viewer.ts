import { defineAsyncComponent } from 'vue';

export const DocumentViewer = defineAsyncComponent(
  () => import('../components/DocumentViewer.vue')
);
export type {
  DocumentSurfaceOptions,
  DocumentViewerOptions,
  DocumentDetail,
} from './contracts';
export const DocumentRevisionComments = defineAsyncComponent(
  () => import('../components/DocumentRevisionComments.vue')
);
export type {
  DocumentCommentScope,
  DocumentCommentSource,
  DocumentCommentThread,
  DocumentCommentMessage,
  DocumentCommentReceipt,
  DocumentCommentCommand,
} from './comment-contracts';
