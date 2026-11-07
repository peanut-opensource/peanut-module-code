import type { DocumentOwner } from './contracts';

/** The host changes contextKey when tenant/member/authorization context changes. */
export interface DocumentCommentScope {
  documentId: number;
  revisionId: number;
  contextKey: string;
  owner?: DocumentOwner;
}
export interface DocumentCommentBinding {
  version_digest: string;
  text_profile: 'peanut.richtext.plain-text/1';
  text_sha256: string;
}
export interface DocumentCommentSource extends DocumentCommentBinding {
  document_id: number;
  revision_id: number;
  revision_no: number;
  source_state_seq: number;
  offset_unit: 'unicode-code-point';
  text: string;
  text_length: number;
  owner: DocumentOwner;
}
export interface DocumentCommentAnchor {
  start_offset: number;
  end_offset: number;
  quote: string;
}
export type DocumentCommentAction = 'create' | 'reply' | 'resolve' | 'reopen';
export type DocumentCommentStatus = 'open' | 'resolved';
export interface DocumentCommentThread
  extends DocumentCommentBinding,
    DocumentCommentAnchor {
  document_id: number;
  revision_id: number;
  thread_id: number;
  thread_revision: number;
  status: DocumentCommentStatus;
  created_by_member_id: number;
  updated_by_member_id: number;
  created_at: string;
  updated_at: string;
}
export interface DocumentCommentMessage {
  message_id: number;
  thread_id: number;
  thread_revision: number;
  action: DocumentCommentAction;
  body: string | null;
  actor_member_id: number;
  created_at: string;
}
export interface DocumentCommentThreadsPage {
  source: DocumentCommentSource;
  can_write: boolean;
  items: DocumentCommentThread[];
  next_before_thread_id: number | null;
}
export interface DocumentCommentThreadPage {
  source: DocumentCommentSource;
  can_write: boolean;
  thread: DocumentCommentThread;
  messages: DocumentCommentMessage[];
  next_after_message_id: number | null;
}
interface CommandBase extends DocumentCommentBinding {
  document_id: number;
  revision_id: number;
  request_id: string;
}
interface ExistingThread {
  thread_id: number;
  expected_thread_revision: number;
}
export type DocumentCommentCommand =
  | {
      action: 'create';
      input: CommandBase & DocumentCommentAnchor & { body: string };
    }
  | { action: 'reply'; input: CommandBase & ExistingThread & { body: string } }
  | { action: 'resolve' | 'reopen'; input: CommandBase & ExistingThread };
export interface DocumentCommentReceipt extends DocumentCommentBinding {
  document_id: number;
  revision_id: number;
  request_id: string;
  action: DocumentCommentAction;
  thread_id: number;
  thread_revision: number;
  status: DocumentCommentStatus;
  message_id: number;
  actor_member_id: number;
  created_at: string;
}
export interface DocumentCommentTransport {
  threads(
    scope: DocumentCommentScope,
    before: number | undefined,
    signal: AbortSignal
  ): Promise<unknown>;
  thread(
    scope: DocumentCommentScope,
    threadId: number,
    after: number | undefined,
    signal: AbortSignal
  ): Promise<unknown>;
  submit(
    command: DocumentCommentCommand,
    signal: AbortSignal
  ): Promise<unknown>;
}
export interface DocumentCommentPending {
  readonly command: DocumentCommentCommand;
  state: 'sending' | 'unknown' | 'rejected';
  uncertain: boolean;
  errorCode?: string;
}
