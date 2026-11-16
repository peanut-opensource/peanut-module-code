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
export const DocumentRevisionSuggestions = defineAsyncComponent(
  () => import('../components/DocumentRevisionSuggestions.vue')
);
export type {
  DocumentCommentScope,
  DocumentCommentSource,
  DocumentCommentThread,
  DocumentCommentMessage,
  DocumentCommentReceipt,
  DocumentCommentCommand,
  DocumentRevisionSuggestion,
} from './comment-contracts';
export type { DocumentChange, DocumentChangesPage } from './suggestions';
