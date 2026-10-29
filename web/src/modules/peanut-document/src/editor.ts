import { defineAsyncComponent } from 'vue';

export const DocumentEditor = defineAsyncComponent(
  () => import('../components/DocumentEditor.vue')
);
export type {
  DocumentSurfaceOptions,
  DocumentSaveState,
  AuthorizedDocumentTransport,
} from './contracts';

export const DocumentOperations = defineAsyncComponent(
  () => import('../components/DocumentOperations.vue')
);
export type {
  DocumentBusinessAction,
  DocumentOperationFeedback,
} from './contracts';
export type { DocumentBusinessCommand } from './operations';
