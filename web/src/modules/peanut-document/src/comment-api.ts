import axios, { type AxiosRequestConfig } from 'axios';
import type { DocumentCommentTransport } from './comment-contracts';
import { DocumentCommentRejection } from './comment-rejection';

/** Preserve native HTTP status/codes; the outcome contract also distinguishes unconfirmed writes. */
export async function documentCommentRequest(
  config: AxiosRequestConfig,
  signal: AbortSignal
): Promise<unknown> {
  let rejection: DocumentCommentRejection | undefined;
  const defaults = axios.defaults.transformResponse;
  const transforms = Array.isArray(defaults) ? [...defaults] : [];
  if (typeof defaults === 'function') transforms.push(defaults);
  try {
    return (
      await axios.request<unknown>({
        ...config,
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
              rejection = new DocumentCommentRejection(
                status,
                value.data.error_code
              );
            return value;
          },
        ],
      })
    ).data;
  } catch (error) {
    if (rejection && !signal.aborted) throw rejection;
    throw error;
  }
}
export const documentCommentTransport: DocumentCommentTransport = {
  threads: (scope, before, signal) =>
    documentCommentRequest(
      {
        method: 'GET',
        url: '/adminapi/peanut.document.comments',
        params: {
          document_id: scope.documentId,
          revision_id: scope.revisionId,
          before_thread_id: before,
          limit: 20,
        },
      },
      signal
    ),
  thread: (scope, threadId, after, signal) =>
    documentCommentRequest(
      {
        method: 'GET',
        url: '/adminapi/peanut.document.comment.thread',
        params: {
          document_id: scope.documentId,
          revision_id: scope.revisionId,
          thread_id: threadId,
          after_message_id: after,
          limit: 50,
        },
      },
      signal
    ),
  submit: (command, signal) =>
    documentCommentRequest(
      {
        method: 'POST',
        url: `/adminapi/peanut.document.${
          (
            {
              suggest: 'suggestion.create',
              accept: 'suggestion.accept',
              reject: 'suggestion.reject',
            } as Partial<Record<typeof command.action, string>>
          )[command.action] ?? `comment.${command.action}`
        }`,
        data: command.input,
      },
      signal
    ),
};
export default documentCommentTransport;
