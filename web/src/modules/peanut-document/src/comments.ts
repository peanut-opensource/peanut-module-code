import type {
  DocumentCommentScope,
  DocumentCommentSource,
  DocumentCommentBinding,
  DocumentCommentThread,
  DocumentCommentMessage,
  DocumentCommentThreadsPage,
  DocumentCommentThreadPage,
  DocumentCommentCommand,
  DocumentCommentReceipt,
  DocumentCommentAnchor,
  DocumentCommentTransport,
  DocumentCommentPending,
} from './comment-contracts';

export class DocumentCommentRejection extends Error {
  constructor(readonly status: number, readonly errorCode: string) {
    super(errorCode);
  }
}
const fail = (): never => {
  throw new Error('DOCUMENT_COMMENT_RESPONSE_INVALID');
};
const record = (value: unknown): Record<string, unknown> => {
  if (!value || typeof value !== 'object' || Array.isArray(value))
    return fail();
  return value as Record<string, unknown>;
};
const integer = (value: unknown, min = 1): value is number =>
  typeof value === 'number' && Number.isSafeInteger(value) && value >= min;
const digest = (value: unknown) =>
  typeof value === 'string' && /^[0-9a-f]{64}$/.test(value);
const status = (value: unknown) => value === 'open' || value === 'resolved';
const sameOwner = (
  left: DocumentCommentSource['owner'],
  right: DocumentCommentSource['owner']
) =>
  left.module_key === right.module_key &&
  left.object_type === right.object_type &&
  left.object_id === right.object_id &&
  left.slot === right.slot;
const binding = (value: Record<string, unknown>) =>
  digest(value.version_digest) &&
  digest(value.text_sha256) &&
  value.text_profile === 'peanut.richtext.plain-text/1';
const sameBinding = (
  value: Record<string, unknown>,
  source: DocumentCommentBinding
) =>
  value.version_digest === source.version_digest &&
  value.text_sha256 === source.text_sha256 &&
  value.text_profile === source.text_profile;
const scopeKey = (scope: DocumentCommentScope) =>
  JSON.stringify([
    scope.contextKey,
    scope.documentId,
    scope.revisionId,
    scope.owner?.module_key,
    scope.owner?.object_type,
    scope.owner?.object_id,
    scope.owner?.slot,
  ]);

export function parseCommentSource(
  value: unknown,
  scope: DocumentCommentScope
): DocumentCommentSource {
  const row = record(value);
  const owner = record(row.owner);
  if (
    row.document_id !== scope.documentId ||
    row.revision_id !== scope.revisionId ||
    !integer(row.revision_no) ||
    !integer(row.source_state_seq, 0) ||
    !binding(row) ||
    row.offset_unit !== 'unicode-code-point' ||
    typeof row.text !== 'string' ||
    !integer(row.text_length, 0) ||
    Array.from(row.text).length !== row.text_length ||
    ['module_key', 'object_type', 'object_id', 'slot'].some(
      (key) => typeof owner[key] !== 'string' || !owner[key]
    )
  )
    return fail();
  const source = value as DocumentCommentSource;
  if (scope.owner && !sameOwner(source.owner, scope.owner)) return fail();
  return source;
}
function parseThread(
  value: unknown,
  source: DocumentCommentSource
): DocumentCommentThread {
  const row = record(value);
  if (
    !integer(row.thread_id) ||
    !integer(row.thread_revision) ||
    !status(row.status) ||
    row.document_id !== source.document_id ||
    row.revision_id !== source.revision_id ||
    !sameBinding(row, source) ||
    !integer(row.start_offset, 0) ||
    !integer(row.end_offset) ||
    row.end_offset <= row.start_offset ||
    row.end_offset > source.text_length ||
    row.end_offset - row.start_offset > 4096 ||
    row.quote !==
      Array.from(source.text)
        .slice(row.start_offset, row.end_offset)
        .join('') ||
    !integer(row.created_by_member_id) ||
    !integer(row.updated_by_member_id) ||
    typeof row.created_at !== 'string' ||
    typeof row.updated_at !== 'string'
  )
    return fail();
  return value as DocumentCommentThread;
}
function parseMessage(
  value: unknown,
  thread: DocumentCommentThread
): DocumentCommentMessage {
  const row = record(value);
  if (
    !integer(row.message_id) ||
    row.thread_id !== thread.thread_id ||
    !integer(row.thread_revision) ||
    row.thread_revision > thread.thread_revision ||
    !['create', 'reply', 'resolve', 'reopen'].includes(String(row.action)) ||
    !integer(row.actor_member_id) ||
    typeof row.created_at !== 'string' ||
    (['create', 'reply'].includes(String(row.action))
      ? typeof row.body !== 'string' ||
        Array.from(row.body).length < 1 ||
        Array.from(row.body).length > 4000
      : row.body !== null)
  )
    return fail();
  return value as DocumentCommentMessage;
}
export function parseCommentThreads(
  value: unknown,
  scope: DocumentCommentScope
): DocumentCommentThreadsPage {
  const row = record(value);
  const source = parseCommentSource(row.source, scope);
  if (
    typeof row.can_write !== 'boolean' ||
    !Array.isArray(row.items) ||
    row.items.length > 100 ||
    (row.next_before_thread_id !== null && !integer(row.next_before_thread_id))
  )
    return fail();
  const items = row.items.map((item) => parseThread(item, source));
  if (
    new Set(items.map((item) => item.thread_id)).size !== items.length ||
    items.some(
      (item, i) => i > 0 && item.thread_id >= items[i - 1].thread_id
    ) ||
    (row.next_before_thread_id !== null &&
      row.next_before_thread_id !== items[items.length - 1]?.thread_id)
  )
    return fail();
  return {
    source,
    can_write: row.can_write,
    items,
    next_before_thread_id: row.next_before_thread_id as number | null,
  };
}
export function parseCommentThread(
  value: unknown,
  scope: DocumentCommentScope,
  threadId: number
): DocumentCommentThreadPage {
  const row = record(value);
  const source = parseCommentSource(row.source, scope);
  const thread = parseThread(row.thread, source);
  if (
    thread.thread_id !== threadId ||
    typeof row.can_write !== 'boolean' ||
    !Array.isArray(row.messages) ||
    row.messages.length > 100 ||
    (row.next_after_message_id !== null && !integer(row.next_after_message_id))
  )
    return fail();
  const messages = row.messages.map((item) => parseMessage(item, thread));
  if (
    new Set(messages.map((item) => item.message_id)).size !== messages.length ||
    messages.some(
      (item, i) =>
        i > 0 &&
        (item.message_id <= messages[i - 1].message_id ||
          item.thread_revision <= messages[i - 1].thread_revision)
    ) ||
    (row.next_after_message_id !== null &&
      row.next_after_message_id !== messages[messages.length - 1]?.message_id)
  )
    return fail();
  return {
    source,
    can_write: row.can_write,
    thread,
    messages,
    next_after_message_id: row.next_after_message_id as number | null,
  };
}
export function parseCommentReceipt(
  value: unknown,
  command: DocumentCommentCommand
): DocumentCommentReceipt {
  const row = record(value);
  const input = command.input;
  if (
    row.document_id !== input.document_id ||
    row.revision_id !== input.revision_id ||
    row.request_id !== input.request_id ||
    row.action !== command.action ||
    !sameBinding(row, input) ||
    !integer(row.thread_id) ||
    !integer(row.message_id) ||
    !integer(row.actor_member_id) ||
    row.status !== (command.action === 'resolve' ? 'resolved' : 'open') ||
    typeof row.created_at !== 'string'
  )
    return fail();
  if (
    command.action === 'create'
      ? row.thread_revision !== 1
      : row.thread_id !== command.input.thread_id ||
        row.thread_revision !== command.input.expected_thread_revision + 1
  )
    return fail();
  return value as DocumentCommentReceipt;
}

/** Translate a real textarea selection; a half-surrogate boundary is rejected, never rounded. */
export function commentAnchor(
  text: string,
  start: number,
  end: number
): DocumentCommentAnchor {
  const splitsPair = (index: number) =>
    index > 0 &&
    index < text.length &&
    text.charCodeAt(index - 1) >= 0xd800 &&
    text.charCodeAt(index - 1) <= 0xdbff &&
    text.charCodeAt(index) >= 0xdc00 &&
    text.charCodeAt(index) <= 0xdfff;
  if (
    !integer(start, 0) ||
    !integer(end) ||
    end <= start ||
    end > text.length ||
    splitsPair(start) ||
    splitsPair(end)
  )
    throw new Error('DOCUMENT_COMMENT_RANGE_INVALID');
  const quote = text.slice(start, end);
  if (Array.from(quote).length > 4096)
    throw new Error('DOCUMENT_COMMENT_RANGE_INVALID');
  return {
    start_offset: Array.from(text.slice(0, start)).length,
    end_offset: Array.from(text.slice(0, end)).length,
    quote,
  };
}

/** Actual component state machine. Pending inputs are immutable and retained per context/revision. */
export class RevisionCommentSession {
  scope?: DocumentCommentScope;
  source?: DocumentCommentSource;
  threads: DocumentCommentThread[] = [];
  active?: DocumentCommentThread;
  messages: DocumentCommentMessage[] = [];
  canWrite = false;
  loading = false;
  loadingThread = false;
  nextThread: number | null = null;
  nextMessage: number | null = null;
  error?: string;
  pending?: DocumentCommentPending;
  lastReceipt?: DocumentCommentReceipt;
  private generation = 0;
  private selection = 0;
  private listRequest?: AbortController;
  private threadRequest?: AbortController;
  private readonly requests = new Map<string, DocumentCommentPending>();
  private disposed = false;

  constructor(
    private readonly transport: DocumentCommentTransport,
    private readonly requestId: () => string = () =>
      globalThis.crypto.randomUUID()
  ) {}

  setScope(scope: DocumentCommentScope): void {
    this.generation += 1;
    this.selection += 1;
    this.listRequest?.abort();
    this.threadRequest?.abort();
    this.scope = undefined;
    this.pending = undefined;
    this.clearView();
    this.error = undefined;
    this.lastReceipt = undefined;
    this.loading = false;
    this.loadingThread = false;
    if (
      !integer(scope.documentId) ||
      !integer(scope.revisionId) ||
      !scope.contextKey.trim()
    )
      throw new Error('DOCUMENT_COMMENT_CONTEXT_REQUIRED');
    this.scope = Object.freeze({
      ...scope,
      owner: scope.owner ? Object.freeze({ ...scope.owner }) : undefined,
    });
    this.pending = this.requests.get(scopeKey(scope));
  }
  private clearView(): void {
    this.source = undefined;
    this.threads = [];
    this.active = undefined;
    this.messages = [];
    this.canWrite = false;
    this.nextThread = null;
    this.nextMessage = null;
  }
  private current(generation: number): boolean {
    return !this.disposed && generation === this.generation;
  }
  private readFailure(error: unknown): void {
    this.clearView();
    this.error =
      error instanceof DocumentCommentRejection
        ? error.errorCode
        : error instanceof Error
        ? error.message
        : 'DOCUMENT_COMMENT_LOAD_FAILED';
  }

  async load(append = false): Promise<void> {
    const scope = this.scope;
    if (!scope || this.disposed || (append && this.nextThread === null)) return;
    const generation = this.generation;
    this.listRequest?.abort();
    const request = new AbortController();
    this.listRequest = request;
    this.loading = true;
    this.error = undefined;
    if (!append) {
      this.selection += 1;
      this.threadRequest?.abort();
      this.active = undefined;
      this.messages = [];
      this.nextMessage = null;
      this.loadingThread = false;
    }
    try {
      const page = parseCommentThreads(
        await this.transport.threads(
          scope,
          append ? this.nextThread ?? undefined : undefined,
          request.signal
        ),
        scope
      );
      if (!this.current(generation) || this.listRequest !== request) return;
      if (this.source && !sameBinding(record(page.source), this.source))
        return fail();
      if (
        append &&
        (this.threads.some((thread) =>
          page.items.some((item) => item.thread_id === thread.thread_id)
        ) ||
          (this.threads.length > 0 &&
            page.items.length > 0 &&
            page.items[0].thread_id >=
              this.threads[this.threads.length - 1].thread_id))
      )
        return fail();
      this.source = page.source;
      this.threads = append ? [...this.threads, ...page.items] : page.items;
      this.canWrite = page.can_write;
      this.nextThread = page.next_before_thread_id;
    } catch (error) {
      if (
        this.current(generation) &&
        this.listRequest === request &&
        !request.signal.aborted
      )
        this.readFailure(error);
    } finally {
      if (this.current(generation) && this.listRequest === request)
        this.loading = false;
    }
  }

  async select(threadId: number, append = false): Promise<void> {
    const scope = this.scope;
    if (
      !scope ||
      !this.source ||
      this.disposed ||
      (this.pending && !append) ||
      (append &&
        (this.nextMessage === null || this.active?.thread_id !== threadId))
    )
      return;
    const generation = this.generation;
    this.selection += 1;
    const selection = this.selection;
    this.threadRequest?.abort();
    const request = new AbortController();
    this.threadRequest = request;
    this.loadingThread = true;
    this.error = undefined;
    if (!append) {
      this.active = undefined;
      this.messages = [];
      this.nextMessage = null;
    }
    try {
      const page = parseCommentThread(
        await this.transport.thread(
          scope,
          threadId,
          append ? this.nextMessage ?? undefined : undefined,
          request.signal
        ),
        scope,
        threadId
      );
      if (!this.current(generation) || selection !== this.selection) return;
      if (!this.source || !sameBinding(record(page.source), this.source))
        return fail();
      if (
        append &&
        (this.messages.some((message) =>
          page.messages.some((item) => item.message_id === message.message_id)
        ) ||
          (this.messages.length > 0 &&
            page.messages.length > 0 &&
            (page.messages[0].message_id <=
              this.messages[this.messages.length - 1].message_id ||
              page.messages[0].thread_revision <=
                this.messages[this.messages.length - 1].thread_revision)))
      )
        return fail();
      this.active = page.thread;
      this.messages = append
        ? [...this.messages, ...page.messages]
        : page.messages;
      this.canWrite = page.can_write;
      this.nextMessage = page.next_after_message_id;
    } catch (error) {
      if (
        this.current(generation) &&
        selection === this.selection &&
        !request.signal.aborted
      )
        this.readFailure(error);
    } finally {
      if (this.current(generation) && selection === this.selection)
        this.loadingThread = false;
    }
  }

  private base() {
    if (
      !this.scope ||
      !this.source ||
      !this.canWrite ||
      this.pending ||
      this.loading ||
      this.loadingThread
    )
      throw new Error('DOCUMENT_COMMENT_NOT_WRITABLE');
    return {
      document_id: this.scope.documentId,
      revision_id: this.scope.revisionId,
      request_id: this.requestId(),
      version_digest: this.source.version_digest,
      text_profile: this.source.text_profile,
      text_sha256: this.source.text_sha256,
    };
  }
  async create(anchor: DocumentCommentAnchor, body: string): Promise<void> {
    this.body(body);
    if (
      !this.source ||
      anchor.end_offset <= anchor.start_offset ||
      anchor.start_offset < 0 ||
      anchor.end_offset > this.source.text_length ||
      anchor.end_offset - anchor.start_offset > 4096 ||
      Array.from(this.source.text)
        .slice(anchor.start_offset, anchor.end_offset)
        .join('') !== anchor.quote
    )
      throw new Error('DOCUMENT_COMMENT_RANGE_INVALID');
    await this.start({
      action: 'create',
      input: { ...this.base(), ...anchor, body },
    });
  }
  async reply(body: string): Promise<void> {
    this.body(body);
    if (!this.active || this.active.status !== 'open')
      throw new Error('DOCUMENT_COMMENT_STATE_CONFLICT');
    await this.start({
      action: 'reply',
      input: {
        ...this.base(),
        thread_id: this.active.thread_id,
        expected_thread_revision: this.active.thread_revision,
        body,
      },
    });
  }
  async transition(action: 'resolve' | 'reopen'): Promise<void> {
    if (
      !this.active ||
      this.active.status !== (action === 'resolve' ? 'open' : 'resolved')
    )
      throw new Error('DOCUMENT_COMMENT_STATE_CONFLICT');
    await this.start({
      action,
      input: {
        ...this.base(),
        thread_id: this.active.thread_id,
        expected_thread_revision: this.active.thread_revision,
      },
    });
  }
  private body(value: string): void {
    if (!value.trim() || Array.from(value).length > 4000)
      throw new Error('DOCUMENT_COMMENT_INPUT_INVALID');
  }
  private async start(command: DocumentCommentCommand): Promise<void> {
    if (!this.scope) return;
    Object.freeze(command.input);
    Object.freeze(command);
    const pending: DocumentCommentPending = {
      command,
      state: 'sending',
      uncertain: false,
    };
    this.requests.set(scopeKey(this.scope), pending);
    this.pending = pending;
    await this.send(pending);
  }
  async retry(): Promise<void> {
    if (this.pending && this.pending.state !== 'sending')
      await this.send(this.pending);
  }
  dismissRejected(): void {
    if (
      this.scope &&
      this.pending?.state === 'rejected' &&
      !this.pending.uncertain
    ) {
      this.requests.delete(scopeKey(this.scope));
      this.pending = undefined;
    }
  }
  private async send(pending: DocumentCommentPending): Promise<void> {
    const scope = this.scope;
    if (!scope || this.disposed) return;
    const key = scopeKey(scope);
    const generation = this.generation;
    pending.state = 'sending';
    pending.errorCode = undefined;
    this.pending = { ...pending };
    this.requests.set(key, this.pending);
    const tracked = this.pending;
    let receipt: DocumentCommentReceipt;
    try {
      receipt = parseCommentReceipt(
        await this.transport.submit(
          tracked.command,
          new AbortController().signal
        ),
        tracked.command
      );
    } catch (error) {
      tracked.state =
        error instanceof DocumentCommentRejection ? 'rejected' : 'unknown';
      tracked.uncertain =
        tracked.uncertain || !(error instanceof DocumentCommentRejection);
      tracked.errorCode =
        error instanceof DocumentCommentRejection
          ? error.errorCode
          : error instanceof Error
          ? error.message
          : 'DOCUMENT_COMMENT_REQUEST_UNKNOWN';
      if (
        !this.disposed &&
        this.scope &&
        scopeKey(this.scope) === key &&
        this.pending?.command === tracked.command
      ) {
        this.pending = { ...tracked };
        this.requests.set(key, this.pending);
        this.error = tracked.errorCode;
        // Current read/write authority must be fetched again after a rejection or unknown result.
        this.clearView();
      }
      return;
    }
    this.requests.delete(key);
    if (
      this.disposed ||
      !this.scope ||
      scopeKey(this.scope) !== key ||
      this.pending?.command !== tracked.command
    )
      return;
    this.pending = undefined;
    this.lastReceipt = receipt;
    // An old receipt is never applied as current thread state, including a return to this scope.
    await this.load();
    if (this.current(generation) && this.source)
      await this.select(receipt.thread_id);
  }
  dispose(): void {
    this.disposed = true;
    this.generation += 1;
    this.listRequest?.abort();
    this.threadRequest?.abort();
    this.clearView();
  }
}
