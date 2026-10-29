import type { components } from '@/generated/openapi';
import type { SurfaceCallbacks } from '@/modules/peanut-content-tools/src/contracts';

export type DocumentDetail =
  components['schemas']['DocumentDetailResponse']['data'];
export type DocumentVersionsPage =
  components['schemas']['DocumentVersionsResponse']['data'];
export type DocumentCapabilities = Omit<
  components['schemas']['DocumentCapabilitiesResponse']['data'],
  'can_view'
> & {
  /** Edit-purpose owner metadata does not grant independent viewing. */
  can_view?: boolean;
};
export type DocumentSaveReceipt =
  components['schemas']['DocumentSaveResponse']['data'];
export type DocumentSaveTargetReceipt =
  components['schemas']['DocumentTargetResponse']['data'];
export type DocumentOwner = NonNullable<DocumentDetail['owner']>;
export type DocumentSaveState =
  | 'idle'
  | 'syncing'
  | 'persisting'
  | 'saved'
  | 'error'
  | 'readonly';

export type DocumentBusinessOperation =
  components['schemas']['DocumentBusinessOperationResponse']['data'];
export type DocumentBusinessResult =
  components['schemas']['DocumentBusinessResult'];
export type DocumentBusinessAction = DocumentBusinessOperation['action'];
export type DocumentSoloBusinessReceipt =
  | components['schemas']['DocumentVersionResponse']['data']
  | components['schemas']['DocumentMetadataResponse']['data'];
export interface DocumentOperationFeedback {
  documentId: number;
  operationId: string;
  action: DocumentBusinessAction;
  state: 'pending' | 'unknown' | 'failed' | 'completed';
  released: boolean;
  result?: DocumentBusinessResult;
  receipt?: DocumentSoloBusinessReceipt;
  errorCode?: string;
}

/** Injected by the authorized owner; never inferred from a document ID. */
export interface AuthorizedDocumentTransport {
  documentId: number;
  owner: DocumentOwner;
  authorizationRevision: string;
  capabilityRevision: string;
  mode: 'write';
  url: string;
  documentName: string;
}
export interface DocumentSurfaceOptions {
  documentId: number;
  contextKey?: string;
  owner?: DocumentOwner;
  callbacks?: SurfaceCallbacks;
  allowDownloads?: boolean;
  /** Owner-selected UI affordances only; every command is authorized by the server. */
  businessActions?: DocumentBusinessAction[];
  /** Explicit owner edit-purpose endpoint; no independent detail/view request. */
  metadataResolver?: (signal: AbortSignal) => Promise<{
    detail: DocumentDetail;
    capabilities: DocumentCapabilities;
  }>;
  resolveTransport?: (
    detail: DocumentDetail,
    capabilities: DocumentCapabilities,
    signal: AbortSignal
  ) => Promise<AuthorizedDocumentTransport>;
}
