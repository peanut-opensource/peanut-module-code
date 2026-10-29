import * as Y from 'yjs';

export interface CommittedProof {
  type: 'committed';
  documentId: number;
  roomEpoch: number;
  stateSeq: number;
  captureId: string;
  binarySha256: string;
  snapshotBase64: string;
  requestId?: string;
}

export interface SaveTarget {
  snapshot: Y.Snapshot;
  update: Uint8Array;
  localUpdates: Uint8Array[];
}

export function coversSnapshot(proof: Y.Snapshot, target: Y.Snapshot): boolean {
  return (
    [...target.sv].every(
      ([client, clock]) => (proof.sv.get(client) ?? 0) >= clock
    ) && Y.equalDeleteSets(proof.ds, Y.mergeDeleteSets([proof.ds, target.ds]))
  );
}

/** Retain exact local updates, including their delete sets, until committed coverage. */
export class LocalEditTracker {
  private bootstrap = true;

  private writable = false;

  private failed = false;

  private pending: Uint8Array[] = [];

  private pendingBytes = 0;

  private committed: Y.Snapshot | undefined;

  private readonly listener: (
    bytes: Uint8Array,
    origin: unknown,
    doc: Y.Doc,
    transaction: Y.Transaction
  ) => void;

  constructor(
    readonly document: Y.Doc,
    readonly generation: string,
    private readonly onFailure: () => void = () => {
      // Failure notification is optional; local capture still freezes below.
    }
  ) {
    this.listener = (bytes, _origin, _doc, transaction) => {
      if (!transaction.local) return;
      if (
        this.bootstrap ||
        !this.writable ||
        this.failed ||
        this.pending.length >= 1000 ||
        this.pendingBytes + bytes.length > 16 * 1024 * 1024
      ) {
        this.freeze();
        this.onFailure();
        return;
      }
      this.pending.push(bytes.slice());
      this.pendingBytes += bytes.length;
    };
    document.on('update', this.listener);
  }

  enableEdits(writable = true): void {
    if (this.failed) throw new Error('RECOVERY_REQUIRED');
    this.bootstrap = false;
    this.writable = writable;
  }

  acknowledge(snapshot: Y.Snapshot): void {
    if (this.failed) throw new Error('RECOVERY_REQUIRED');
    this.committed = snapshot;
    this.pending = this.pending.filter(
      (bytes) => !Y.snapshotContainsUpdate(snapshot, bytes)
    );
    this.pendingBytes = this.pending.reduce(
      (total, bytes) => total + bytes.length,
      0
    );
  }

  captureTarget(): SaveTarget {
    if (this.failed || this.bootstrap || !this.writable)
      throw new Error('SAVE_TARGET_UNAVAILABLE');
    return {
      snapshot: Y.snapshot(this.document),
      update: Y.encodeStateAsUpdate(this.document),
      localUpdates: this.pending.map((bytes) => bytes.slice()),
    };
  }

  coversTarget(snapshot: Y.Snapshot, target: SaveTarget): boolean {
    return (
      !this.failed &&
      coversSnapshot(snapshot, target.snapshot) &&
      Y.snapshotContainsUpdate(snapshot, target.update) &&
      target.localUpdates.every((bytes) =>
        Y.snapshotContainsUpdate(snapshot, bytes)
      )
    );
  }

  get saved(): boolean {
    return (
      !this.failed &&
      !this.bootstrap &&
      this.pending.length === 0 &&
      !!this.committed &&
      Y.snapshotContainsUpdate(
        this.committed,
        Y.encodeStateAsUpdate(this.document)
      )
    );
  }

  get pendingCount(): number {
    return this.pending.length;
  }

  freeze(): void {
    this.failed = true;
    this.writable = false;
  }

  destroy(): void {
    this.document.off('update', this.listener);
    this.freeze();
  }
}
