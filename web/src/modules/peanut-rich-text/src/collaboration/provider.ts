import {
  HocuspocusProvider,
  HocuspocusProviderWebsocket,
} from '@hocuspocus/provider';
import * as Y from 'yjs';
import randomUuid from '@/utils/random-uuid';
import {
  LocalEditTracker,
  coversSnapshot,
  type CommittedProof,
  type SaveTarget,
} from './tracker.js';

export type { CommittedProof } from './tracker.js';

export interface AuthenticatedPresence {
  memberId: number;
  nickname: string;
}

export interface ProviderOptions {
  url: string;
  documentId: number;
  documentName: string;
  token: string;
  onRecoveryRequired: (code: string) => void;
  onSavedChanged?: (saved: boolean) => void;
  onReadyChanged?: (ready: boolean) => void;
  onWritableChanged?: (writable: boolean) => void;
  onCommitted?: (proof: CommittedProof) => void;
  onPresenceChanged?: (members: AuthenticatedPresence[]) => void;
}

interface TargetRequest {
  target: SaveTarget | undefined;
  promise: Promise<CommittedProof>;
  resolve: (proof: CommittedProof) => void;
  reject: (error: Error) => void;
  timeout: ReturnType<typeof setTimeout>;
  settled: boolean;
}

function requestIdentity(value: unknown): value is string {
  return typeof value === 'string' && /^[A-Za-z0-9_-]{1,128}$/.test(value);
}

function decodeProof(value: Record<string, unknown>): {
  proof: CommittedProof;
  snapshot: Y.Snapshot;
} {
  if (
    value.type !== 'committed' ||
    !Number.isSafeInteger(value.documentId) ||
    (value.documentId as number) < 1 ||
    !Number.isSafeInteger(value.roomEpoch) ||
    (value.roomEpoch as number) < 1 ||
    !Number.isSafeInteger(value.stateSeq) ||
    (value.stateSeq as number) < 0 ||
    typeof value.captureId !== 'string' ||
    !value.captureId ||
    value.captureId.length > 200 ||
    typeof value.binarySha256 !== 'string' ||
    !/^[0-9a-f]{64}$/.test(value.binarySha256) ||
    typeof value.snapshotBase64 !== 'string' ||
    !value.snapshotBase64 ||
    value.snapshotBase64.length > 8 * 1024 * 1024 ||
    (value.requestId !== undefined && !requestIdentity(value.requestId))
  )
    throw new Error('COMMITTED_PROOF_INVALID');
  const binary = atob(value.snapshotBase64);
  if (btoa(binary) !== value.snapshotBase64)
    throw new Error('COMMITTED_SNAPSHOT_INVALID');
  const bytes = Uint8Array.from(binary, (character) => character.charCodeAt(0));
  const snapshot = Y.decodeSnapshot(bytes);
  const canonical = Y.encodeSnapshot(snapshot);
  if (
    canonical.length !== bytes.length ||
    !canonical.every((byte, index) => byte === bytes[index])
  )
    throw new Error('COMMITTED_SNAPSHOT_INVALID');
  return { proof: value as unknown as CommittedProof, snapshot };
}

/** Each authorized connection owns a fresh Doc; disconnected edits are never replayed. */
export function createCollaborationProvider(options: ProviderOptions): {
  document: Y.Doc;
  provider: HocuspocusProvider;
  tracker: LocalEditTracker;
  readonly ready: boolean;
  readonly writable: boolean;
  requestSaveTarget: (requestId: string) => Promise<CommittedProof>;
  destroy: () => void;
} {
  const url = new URL(options.url);
  if (
    !(
      url.protocol === 'wss:' ||
      (url.protocol === 'ws:' && globalThis.location?.protocol === 'http:')
    ) ||
    !Number.isSafeInteger(options.documentId) ||
    options.documentId < 1 ||
    !options.documentName ||
    !options.token
  )
    throw new Error('TRUSTED_PROVIDER_CONFIGURATION_REQUIRED');
  const document = new Y.Doc({ gc: false });
  const generation = randomUuid().replace(/-/g, '');
  let closed = false;
  let frozen = false;
  let connectionStopped = false;
  let synced = false;
  let ready = false;
  let writeScope = false;
  let epoch: number | undefined;
  let lastProof: CommittedProof | undefined;
  let lastSnapshot: Y.Snapshot | undefined;
  let pendingRoster:
    | { epoch: number; members: AuthenticatedPresence[] }
    | undefined;
  let websocket: HocuspocusProviderWebsocket | undefined;
  let provider: HocuspocusProvider | undefined;
  let progressTimer: ReturnType<typeof setInterval> | undefined;
  const progressRequests = new Map<
    string,
    { sentAt: number; settled: boolean }
  >();
  const targets = new Map<string, TargetRequest>();
  let recover: (code?: string) => void;
  const tracker = new LocalEditTracker(document, generation, () => recover());

  const publishSaved = (): void => {
    options.onSavedChanged?.(ready && !frozen && tracker.saved);
  };
  const onUpdate = (): void => {
    if (!closed && !frozen && ready) publishSaved();
  };
  const rejectTargets = (code: string): void => {
    targets.forEach((request) => {
      clearTimeout(request.timeout);
      if (!request.settled) {
        request.settled = true;
        request.target = undefined;
        request.reject(new Error(code));
      }
    });
  };
  const stopConnection = (): void => {
    if (connectionStopped) return;
    connectionStopped = true;
    if (progressTimer !== undefined) clearInterval(progressTimer);
    progressTimer = undefined;
    progressRequests.clear();
    // Detach first: SDK destroy flushes buffered updates, which must not replay after failure.
    provider?.detach();
    websocket?.destroy();
    provider?.destroy();
  };
  const cleanup = (): void => {
    if (closed) return;
    closed = true;
    ready = false;
    rejectTargets('COLLABORATION_CLOSED');
    document.off('update', onUpdate);
    stopConnection();
    tracker.destroy();
    document.destroy();
    options.onPresenceChanged?.([]);
  };
  recover = (code = 'RECOVERY_REQUIRED'): void => {
    if (closed || frozen) return;
    frozen = true;
    ready = false;
    tracker.freeze();
    rejectTargets(code);
    stopConnection();
    // Keep the local Doc for controlled draft export until its owner disposes it.
    options.onReadyChanged?.(false);
    options.onWritableChanged?.(false);
    options.onSavedChanged?.(false);
    options.onPresenceChanged?.([]);
    options.onRecoveryRequired(code);
  };
  const recoverConnection = ({
    event,
  }: {
    event: { reason: string };
  }): void => {
    if (event.reason === 'Connection Timeout') {
      recover('COLLABORATION_CONNECTION_TIMEOUT');
      return;
    }
    recover(
      /^[A-Z][A-Z0-9_]{0,127}$/.test(event.reason)
        ? event.reason
        : 'COLLABORATION_DISCONNECTED'
    );
  };
  const queryProgress = (): void => {
    if (closed || frozen || !provider?.isAuthenticated) return;
    const now = Date.now();
    if (
      [...progressRequests.values()].some(
        (request) => !request.settled && now - request.sentAt > 60000
      )
    ) {
      recover('PROGRESS_TIMEOUT');
      return;
    }
    const requestId = randomUuid();
    if (progressRequests.size >= 64) {
      const oldest = [...progressRequests].find(
        ([, request]) => request.settled
      );
      if (oldest) progressRequests.delete(oldest[0]);
    }
    progressRequests.set(requestId, { sentAt: now, settled: false });
    try {
      provider.sendStateless(JSON.stringify({ type: 'progress', requestId }));
    } catch {
      recover('PROGRESS_SEND_FAILED');
    }
  };
  const enableIfReady = (): void => {
    if (ready || frozen || !synced || epoch === undefined) return;
    tracker.enableEdits(writeScope);
    ready = true;
    options.onWritableChanged?.(writeScope);
    options.onReadyChanged?.(true);
    publishSaved();
  };
  const acceptCommitted = (message: Record<string, unknown>): void => {
    const { proof, snapshot } = decodeProof(message);
    if (proof.documentId !== options.documentId)
      throw new Error('COMMITTED_DOCUMENT_INVALID');
    const progressResponse =
      proof.requestId !== undefined && progressRequests.has(proof.requestId);
    if (epoch === undefined) {
      // Unsolicited broadcasts cannot establish the current epoch.
      if (!progressResponse) return;
      epoch = proof.roomEpoch;
    }
    if (proof.roomEpoch < epoch) return;
    if (proof.roomEpoch > epoch) throw new Error('ROOM_EPOCH_CHANGED');
    if (progressResponse && proof.requestId) {
      const request = progressRequests.get(proof.requestId);
      if (request) request.settled = true;
    }
    if (lastProof && proof.stateSeq < lastProof.stateSeq) {
      const target = proof.requestId ? targets.get(proof.requestId) : undefined;
      if (target && !target.settled) {
        if (!target.target || !tracker.coversTarget(snapshot, target.target))
          throw new Error('SAVE_TARGET_NOT_COMMITTED');
        clearTimeout(target.timeout);
        target.settled = true;
        target.target = undefined;
        target.resolve(proof);
      }
      // A fixed target can commit before a newer broadcast reaches this client.
      // Its matching ACK resolves only that target; current saved proof stays newer.
      return;
    }
    if (
      lastProof &&
      proof.stateSeq === lastProof.stateSeq &&
      (proof.captureId !== lastProof.captureId ||
        proof.binarySha256 !== lastProof.binarySha256 ||
        proof.snapshotBase64 !== lastProof.snapshotBase64)
    )
      throw new Error('COMMITTED_PROOF_CONFLICT');
    if (proof.requestId !== undefined) {
      if (!progressResponse && !targets.has(proof.requestId))
        throw new Error('COMMITTED_REQUEST_UNKNOWN');
    }
    if (lastSnapshot && !coversSnapshot(snapshot, lastSnapshot))
      throw new Error('COMMITTED_SNAPSHOT_REGRESSED');
    const target = proof.requestId ? targets.get(proof.requestId) : undefined;
    if (
      target &&
      !target.settled &&
      (!target.target || !tracker.coversTarget(snapshot, target.target))
    )
      throw new Error('SAVE_TARGET_NOT_COMMITTED');
    lastProof = proof;
    lastSnapshot = snapshot;
    tracker.acknowledge(snapshot);
    if (pendingRoster) {
      if (pendingRoster.epoch === epoch)
        options.onPresenceChanged?.(pendingRoster.members);
      pendingRoster = undefined;
    }
    enableIfReady();
    publishSaved();
    options.onCommitted?.(proof);
    if (target && !target.settled) {
      clearTimeout(target.timeout);
      target.settled = true;
      target.target = undefined;
      target.resolve(proof);
    }
  };
  const acceptMembers = (message: Record<string, unknown>): void => {
    if (
      message.documentId !== options.documentId ||
      !Number.isSafeInteger(message.roomEpoch) ||
      (message.roomEpoch as number) < 1 ||
      !Array.isArray(message.members) ||
      message.members.length > 1000
    )
      throw new Error('MEMBERS_INVALID');
    const members = message.members.map((value: unknown) => {
      if (!value || typeof value !== 'object' || Array.isArray(value))
        throw new Error('MEMBER_INVALID');
      const member = value as Record<string, unknown>;
      if (
        !Number.isSafeInteger(member.memberId) ||
        (member.memberId as number) < 1 ||
        typeof member.nickname !== 'string' ||
        !member.nickname.trim() ||
        [...member.nickname].length > 160
      )
        throw new Error('MEMBER_INVALID');
      return { memberId: member.memberId as number, nickname: member.nickname };
    });
    if (
      new Set(members.map((member) => member.memberId)).size !== members.length
    )
      throw new Error('MEMBERS_DUPLICATE');
    if (epoch === undefined) {
      pendingRoster = { epoch: message.roomEpoch as number, members };
      return;
    }
    if ((message.roomEpoch as number) < epoch) return;
    if (message.roomEpoch !== epoch) throw new Error('ROOM_EPOCH_CHANGED');
    options.onPresenceChanged?.(members);
  };
  websocket = new HocuspocusProviderWebsocket({
    url: options.url,
    autoConnect: false,
    messageReconnectTimeout: 60000,
  });
  provider = new HocuspocusProvider({
    name: options.documentName,
    document,
    websocketProvider: websocket,
    awareness: null,
    flushDelay: 50,
    forceSyncInterval: false,
    token: options.token,
    onAuthenticated: ({ scope }) => {
      if (closed || frozen) return;
      if (ready && writeScope !== (scope === 'read-write')) {
        recover('COLLABORATION_SCOPE_CHANGED');
        return;
      }
      writeScope = scope === 'read-write';
      if (progressTimer !== undefined) clearInterval(progressTimer);
      queryProgress();
      // Server 4.6.0 checks inbound idle time but does not emit Ping. Cached
      // progress replies keep both socket checks alive without PHP/save per query.
      progressTimer = setInterval(queryProgress, 15000);
    },
    onSynced: ({ state }) => {
      if (!state || closed || frozen) return;
      synced = true;
      enableIfReady();
    },
    onStateless: ({ payload }) => {
      if (closed || frozen || !provider?.isAuthenticated) return;
      try {
        const message: unknown = JSON.parse(payload);
        if (!message || typeof message !== 'object' || Array.isArray(message))
          throw new Error('STATELESS_INVALID');
        const value = message as Record<string, unknown>;
        if (value.type === 'committed') acceptCommitted(value);
        else if (value.type === 'members') acceptMembers(value);
        else if (value.type === 'error') {
          if (
            typeof value.code !== 'string' ||
            !/^[A-Z][A-Z0-9_]{0,127}$/.test(value.code)
          )
            throw new Error('COLLABORATION_ERROR_INVALID');
          throw new Error(value.code);
        } else throw new Error('STATELESS_TYPE_INVALID');
      } catch (error) {
        recover(
          error instanceof Error
            ? error.message
            : 'COLLABORATION_PROGRESS_INVALID'
        );
      }
    },
    onAuthenticationFailed: () => recover('COLLABORATION_AUTH_FAILED'),
    onDisconnect: recoverConnection,
    onClose: recoverConnection,
  });
  document.on('update', onUpdate);
  provider.attach();
  websocket.connect().catch(() => recover('COLLABORATION_CONNECT_FAILED'));
  return {
    document,
    provider,
    tracker,
    get ready() {
      return ready && !closed && !frozen;
    },
    get writable() {
      return ready && writeScope && !closed && !frozen;
    },
    requestSaveTarget: (requestId) => {
      const existing = targets.get(requestId);
      if (existing) return existing.promise;
      if (
        !requestIdentity(requestId) ||
        progressRequests.has(requestId) ||
        targets.size >= 1000 ||
        closed ||
        frozen ||
        !ready ||
        !writeScope ||
        !provider?.isAuthenticated
      )
        return Promise.reject(new Error('SAVE_TARGET_UNAVAILABLE'));
      const target = tracker.captureTarget();
      let resolve!: TargetRequest['resolve'];
      let reject!: TargetRequest['reject'];
      const promise = new Promise<CommittedProof>((accept, decline) => {
        resolve = accept;
        reject = decline;
      });
      const request: TargetRequest = {
        target,
        promise,
        resolve,
        reject,
        timeout: setTimeout(() => recover('SAVE_TARGET_TIMEOUT'), 60000),
        settled: false,
      };
      targets.set(requestId, request);
      try {
        // Same provider/socket order: drain updates, then capture the server target.
        provider.flushPendingUpdates();
        provider.sendStateless(
          JSON.stringify({ type: 'save_target', requestId })
        );
      } catch {
        recover('SAVE_TARGET_SEND_FAILED');
      }
      return promise;
    },
    destroy: cleanup,
  };
}
