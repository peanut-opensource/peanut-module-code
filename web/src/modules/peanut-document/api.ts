import axios from 'axios';
import type { RichTextDocumentValue } from '@/modules/peanut-rich-text/src/document';
import type {
  DocumentDetail,
  DocumentCapabilities,
  DocumentSaveReceipt,
  DocumentSaveTargetReceipt,
  DocumentVersionsPage,
} from './src/contracts';

export const getDocumentVersions = (
  documentId: number,
  beforeRevisionNo: number | undefined,
  signal: AbortSignal
) =>
  axios.get<DocumentVersionsPage>('/adminapi/peanut.document.versions', {
    params: {
      document_id: documentId,
      limit: 20,
      before_revision_no: beforeRevisionNo,
    },
    signal,
  });

export const getDocumentDetail = (
  documentId: number,
  revisionId: number | undefined,
  signal: AbortSignal
) =>
  axios.get<DocumentDetail>('/adminapi/peanut.document.detail', {
    params: { document_id: documentId, revision_id: revisionId },
    signal,
  });
export const getDocumentCapabilities = (
  documentId: number,
  revisionId: number | undefined,
  intent: 'view' | 'edit',
  signal: AbortSignal
) =>
  axios.get<DocumentCapabilities>('/adminapi/peanut.document.capabilities', {
    params: { document_id: documentId, revision_id: revisionId, intent },
    signal,
  });
export const saveSoloDocument = (
  documentId: number,
  expectedStateSeq: number,
  value: RichTextDocumentValue,
  requestId: string,
  signal: AbortSignal
) =>
  axios.post<DocumentSaveReceipt>(
    '/adminapi/peanut.document.solo.save',
    {
      document_id: documentId,
      expected_state_seq: expectedStateSeq,
      structured_content: value,
      request_id: requestId,
    },
    { signal }
  );

export const queryDocumentOperation = (
  documentId: number,
  operationId: string,
  signal: AbortSignal
) =>
  axios.get<unknown>('/adminapi/peanut.document.operation', {
    params: { document_id: documentId, operation_id: operationId },
    signal,
  });

export const queryDocumentSaveTarget = (
  documentId: number,
  requestId: string,
  signal: AbortSignal
) =>
  axios.get<DocumentSaveTargetReceipt>('/adminapi/peanut.document.target', {
    params: { document_id: documentId, request_id: requestId },
    signal,
  });

export const cancelDocumentSaveTarget = (
  documentId: number,
  requestId: string,
  signal: AbortSignal
) =>
  axios.post<unknown>(
    '/adminapi/peanut.document.target.cancel',
    {
      document_id: documentId,
      request_id: requestId,
    },
    { signal }
  );

export class DocumentOperationRejection extends Error {
  constructor(readonly status: number, readonly errorCode: string) {
    super(errorCode);
  }
}

export const submitDocumentBusinessOperation = async (
  command: import('./src/operations').DocumentBusinessCommand,
  signal: AbortSignal
) => {
  const routes = {
    'version.create': '/adminapi/peanut.document.version.create',
    'version.restore': '/adminapi/peanut.document.version.restore',
    'archive': '/adminapi/peanut.document.archive',
    'restore': '/adminapi/peanut.document.restore',
  } as const;
  let rejection: DocumentOperationRejection | undefined;
  const defaults = axios.defaults.transformResponse;
  const transforms = Array.isArray(defaults) ? [...defaults] : [];
  if (typeof defaults === 'function') transforms.push(defaults);
  // Preserve the native rejection before the shared interceptor reduces it to a message.
  // The original client and its session/refresh interceptors still process this request.
  try {
    return await axios.post<unknown>(routes[command.action], command.input, {
      signal,
      transformResponse: [
        ...transforms,
        (value: unknown, _headers: unknown, status?: number) => {
          rejection = undefined;
          if (
            status !== undefined &&
            status >= 400 &&
            value &&
            typeof value === 'object' &&
            'code' in value &&
            value.code === status * 100 &&
            'data' in value &&
            value.data &&
            typeof value.data === 'object' &&
            'error_code' in value.data &&
            typeof value.data.error_code === 'string'
          )
            rejection = new DocumentOperationRejection(
              status,
              value.data.error_code
            );
          return value;
        },
      ],
    });
  } catch (error) {
    if (rejection && !signal.aborted) throw rejection;
    throw error;
  }
};
