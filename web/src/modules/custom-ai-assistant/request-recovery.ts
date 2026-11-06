/** Module-owned original requests. No token or plaintext credential is persisted. */
export type MutationEndpoint =
  | 'saveConfiguration'
  | 'saveCredential'
  | 'configurationState'
  | 'createTask'
  | 'manualCandidate'
  | 'taskAction'
  | 'collectCandidate'
  | 'decide'
  | 'candidateLifecycle';
export interface RequestScope {
  session: string;
  tenantId: number;
  memberId: number;
}
export type RequestPhase = 'prepared' | 'unknown' | 'preparing' | 'rejected';
export interface RejectionEvidence {
  httpStatus: number;
  apiCode: number;
  errorCode: string;
}
export interface PendingRequest {
  schema: 1;
  id: string;
  endpoint: MutationEndpoint;
  kind: string;
  payload: Record<string, unknown>;
  scope: RequestScope;
  createdAt: number;
  digest: string;
  secretFingerprint?: string;
  recoveryFileKey?: string;
  phase: RequestPhase;
  attempts: number;
  hadUncertain: boolean;
  rejection?: RejectionEvidence;
}
export interface RequestReceipt {
  request_id: string;
  endpoint: MutationEndpoint;
  kind: string;
  object_id: number | null;
  actor: { tenant_id: number; member_id: number };
  state: 'applied' | 'preparing' | 'unconfirmed';
  anchor_present: boolean;
  evidence: string;
  absence_proves_not_executed: false;
  provider_success: false;
  input_hash?: string;
  result: unknown;
}
export interface RequestLocks {
  run<T>(key: string, work: () => Promise<T>): Promise<T>;
}
export interface StringStorage {
  getItem(key: string): string | null;
  setItem(key: string, value: string): void;
  removeItem(key: string): void;
  readonly length: number;
  key(index: number): string | null;
}
const endpoints: MutationEndpoint[] = [
  'saveConfiguration',
  'saveCredential',
  'configurationState',
  'createTask',
  'manualCandidate',
  'taskAction',
  'collectCandidate',
  'decide',
  'candidateLifecycle',
];
const phases: RequestPhase[] = ['prepared', 'unknown', 'preparing', 'rejected'];
const secretKey =
  /^(secret|authorization|token|access_token|refresh_token|password|api[_-]?key)$/i;
function canonical(value: unknown): unknown {
  if (value === null || typeof value === 'string' || typeof value === 'boolean')
    return value;
  if (typeof value === 'number' && Number.isFinite(value)) return value;
  if (Array.isArray(value)) return value.map(canonical);
  if (typeof value === 'object' && value !== null) {
    const out: Record<string, unknown> = Object.create(null);
    for (const key of Object.keys(value).sort()) {
      if (secretKey.test(key)) throw new Error('AI_PENDING_SECRET_FORBIDDEN');
      out[key] = canonical((value as Record<string, unknown>)[key]);
    }
    return out;
  }
  throw new Error('AI_PENDING_PAYLOAD_INVALID');
}
export async function fingerprint(value: unknown): Promise<string> {
  const bytes = new TextEncoder().encode(JSON.stringify(canonical(value)));
  const digest = await crypto.subtle.digest('SHA-256', bytes);
  return Array.from(new Uint8Array(digest), (n) =>
    n.toString(16).padStart(2, '0')
  ).join('');
}
function sameScope(a: RequestScope, b: RequestScope) {
  return a.tenantId === b.tenantId && a.memberId === b.memberId;
}
function identity(record: PendingRequest) {
  return {
    schema: record.schema,
    id: record.id,
    endpoint: record.endpoint,
    kind: record.kind,
    payload: record.payload,
    scope: record.scope,
    secretFingerprint: record.secretFingerprint || null,
    createdAt: record.createdAt,
  };
}
export function descriptor(record: PendingRequest): Record<string, unknown> {
  return {
    endpoint: record.endpoint,
    kind: record.kind,
    request_id: record.id,
    ...(typeof record.payload.id === 'number' ? { id: record.payload.id } : {}),
    ...(typeof record.payload.action === 'string'
      ? { action: record.payload.action }
      : {}),
  };
}
export function conflictKey(
  endpoint: MutationEndpoint,
  kind: string,
  payload: Record<string, unknown>
): string {
  return typeof payload.id === 'number'
    ? kind + ':' + payload.id
    : endpoint + ':create:' + kind;
}
export function isBusinessResult(value: unknown, id?: number): boolean {
  if (!value || typeof value !== 'object' || Array.isArray(value)) return false;
  const v = value as Record<string, unknown>;
  return (
    typeof v.id === 'number' &&
    Number.isSafeInteger(v.id) &&
    v.id > 0 &&
    (id === undefined || v.id === id) &&
    typeof v.version === 'number' &&
    Number.isSafeInteger(v.version) &&
    v.version > 0 &&
    typeof v.title === 'string' &&
    typeof v.status === 'string' &&
    typeof v.created_by === 'number' &&
    !!v.details &&
    typeof v.details === 'object' &&
    !Array.isArray(v.details)
  );
}
/** Mirrors AiInput integer/text normalization and AiAccess JSON flags for this exact event input. */
export async function lifecycleInputFingerprint(
  record: PendingRequest
): Promise<string | undefined> {
  if (record.endpoint !== 'candidateLifecycle') return undefined;
  const p = record.payload;
  const positive = (v: unknown): number => {
    const value =
      typeof v === 'string' && /^[1-9][0-9]*$/.test(v) ? Number(v) : v;
    if (typeof value !== 'number' || !Number.isSafeInteger(value) || value < 1)
      throw new Error('原采纳操作整数输入无法核实，原UUID继续保留。');
    return value;
  };
  if (
    typeof p.action !== 'string' ||
    !['withdraw', 'rework', 'supersede'].includes(p.action) ||
    typeof p.reason !== 'string'
  )
    throw new Error('原采纳操作输入不完整，原UUID继续保留。');
  const reason = p.reason.replace(/^[ \t\n\r\0\x0B]+|[ \t\n\r\0\x0B]+$/g, '');
  if (!reason || Array.from(p.reason).length > 4000)
    throw new Error('原采纳依据无法核实，原UUID继续保留。');
  const replacementId =
    p.replacement_task_id == null ? null : positive(p.replacement_task_id);
  const replacementVersion =
    p.replacement_task_version == null
      ? null
      : positive(p.replacement_task_version);
  if (
    p.action === 'supersede'
      ? replacementId === null || replacementVersion === null
      : replacementId !== null || replacementVersion !== null
  )
    throw new Error('原替代对象和版本不完整，原UUID继续保留。');
  // PHP JSON_UNESCAPED_UNICODE still escapes these two line separators.
  const json = JSON.stringify([
    positive(p.version),
    p.action,
    reason,
    replacementId,
    replacementVersion,
  ])
    .replace(/\u2028/g, '\\u2028')
    .replace(/\u2029/g, '\\u2029');
  const digest = await crypto.subtle.digest(
    'SHA-256',
    new TextEncoder().encode(json)
  );
  return Array.from(new Uint8Array(digest), (n) =>
    n.toString(16).padStart(2, '0')
  ).join('');
}
export function receiptMatches(
  record: PendingRequest,
  value: unknown,
  expectedInputHash?: string
): value is RequestReceipt {
  if (!value || typeof value !== 'object') return false;
  const v = value as Partial<RequestReceipt>;
  return (
    v.request_id === record.id &&
    v.endpoint === record.endpoint &&
    v.kind === record.kind &&
    v.actor?.tenant_id === record.scope.tenantId &&
    v.actor?.member_id === record.scope.memberId &&
    ['applied', 'preparing', 'unconfirmed'].includes(v.state || '') &&
    typeof v.anchor_present === 'boolean' &&
    v.absence_proves_not_executed === false &&
    v.provider_success === false &&
    v.object_id ===
      (typeof record.payload.id === 'number' ? record.payload.id : null) &&
    (v.state !== 'applied' ||
      isBusinessResult(
        v.result,
        typeof record.payload.id === 'number' ? record.payload.id : undefined
      )) &&
    (record.endpoint !== 'candidateLifecycle' ||
      v.state !== 'applied' ||
      (typeof expectedInputHash === 'string' &&
        v.input_hash === expectedInputHash)) &&
    (v.state !== 'preparing' || v.anchor_present === true)
  );
}
/** Observes the same native axios adapter/transform chain; auth and refresh stay with the host. */
export class ResponseEvidence {
  private last: RejectionEvidence | null = null;
  sent() {
    this.last = null;
  }
  start() {
    this.last = null;
  }
  capture(body: unknown, status: unknown) {
    this.last = null;
    if (
      typeof status !== 'number' ||
      status < 400 ||
      status >= 500 ||
      status === 401 ||
      !body ||
      typeof body !== 'object' ||
      Array.isArray(body)
    )
      return;
    const b = body as Record<string, unknown>,
      data = b.data;
    if (
      !Number.isInteger(b.code) ||
      b.code !== status * 100 ||
      typeof b.msg !== 'string' ||
      !data ||
      typeof data !== 'object' ||
      Array.isArray(data)
    )
      return;
    const code = (data as Record<string, unknown>).error_code;
    if (
      typeof code !== 'string' ||
      !/^([A-Z][A-Z0-9]*_)+[A-Z0-9]+$/.test(code) ||
      /(UNKNOWN|BUSY|IN_PROGRESS|OUTCOME_UNCONFIRMED)/.test(code)
    )
      return;
    this.last = {
      httpStatus: status,
      apiCode: b.code as number,
      errorCode: code,
    };
  }
  rejection() {
    return this.last ? { ...this.last } : null;
  }
}
export class NativeCommandFailure extends Error {
  constructor(message: string, readonly evidence: RejectionEvidence | null) {
    super(message);
    this.name = 'NativeCommandFailure';
  }
}
export class RequestLane {
  private sequence = 0;
  private controller: AbortController | null = null;
  constructor(private currentGeneration: () => number) {}
  start() {
    this.controller?.abort();
    this.controller = new AbortController();
    return {
      sequence: ++this.sequence,
      signal: this.controller.signal,
      generation: this.currentGeneration(),
    };
  }
  current(ticket: {
    sequence: number;
    signal: AbortSignal;
    generation: number;
  }) {
    return (
      ticket.sequence === this.sequence &&
      !ticket.signal.aborted &&
      ticket.generation === this.currentGeneration()
    );
  }
  cancel() {
    this.sequence++;
    this.controller?.abort();
    this.controller = null;
  }
}
export class OriginalRequestStore {
  readonly key: string;
  private raw: string | null = null;
  private imports: Record<string, string> = Object.create(null);
  constructor(
    private storage: StringStorage,
    readonly scope: RequestScope,
    private locks: RequestLocks
  ) {
    if (
      !scope.session ||
      scope.session.length > 256 ||
      !Number.isSafeInteger(scope.tenantId) ||
      scope.tenantId < 1 ||
      !Number.isSafeInteger(scope.memberId) ||
      scope.memberId < 1
    )
      throw new Error('AI_PENDING_SESSION_REQUIRED');
    this.key =
      'custom.ai-assistant.original.v2:' +
      scope.tenantId +
      ':' +
      scope.memberId;
  }
  private legacyKey(key: string): boolean {
    const prefix = 'custom.ai-assistant.original.v1:',
      suffix = ':' + this.scope.tenantId + ':' + this.scope.memberId;
    return (
      key.startsWith(prefix) &&
      key.endsWith(suffix) &&
      key.length > prefix.length + suffix.length
    );
  }
  private async decodeRecords(
    value: unknown,
    legacyKey?: string
  ): Promise<PendingRequest[]> {
    if (!Array.isArray(value) || value.length > 300)
      throw new Error('AI_PENDING_STORAGE_INVALID');
    const out: PendingRequest[] = [];
    for (const item of value) {
      if (!item || typeof item !== 'object')
        throw new Error('AI_PENDING_STORAGE_INVALID');
      const r = item as PendingRequest;
      if (
        r.schema !== 1 ||
        !r.scope ||
        !sameScope(r.scope, this.scope) ||
        typeof r.scope.session !== 'string' ||
        !r.scope.session ||
        r.scope.session.length > 256 ||
        !endpoints.includes(r.endpoint) ||
        !phases.includes(r.phase) ||
        !/^[-A-Za-z0-9:_]{1,100}$/.test(r.id) ||
        r.payload?.request_id !== r.id ||
        !Number.isSafeInteger(r.createdAt) ||
        r.createdAt < 1 ||
        r.attempts < 0 ||
        !Number.isSafeInteger(r.attempts)
      )
        throw new Error('AI_PENDING_STORAGE_INVALID');
      if (
        legacyKey !== undefined &&
        legacyKey !==
          'custom.ai-assistant.original.v1:' +
            encodeURIComponent(r.scope.session) +
            ':' +
            r.scope.tenantId +
            ':' +
            r.scope.memberId
      )
        throw new Error('AI_PENDING_LEGACY_OWNER_INVALID');
      canonical(r.payload);
      if ((await fingerprint(identity(r))) !== r.digest)
        throw new Error('AI_PENDING_ORIGINAL_CHANGED');
      if (
        r.endpoint === 'saveCredential' &&
        (!r.secretFingerprint || !/^[a-f0-9]{64}$/.test(r.secretFingerprint))
      )
        throw new Error('AI_PENDING_SECRET_PROOF_REQUIRED');
      if (
        r.recoveryFileKey &&
        (!/^file_[a-f0-9]{32}$/.test(r.recoveryFileKey) ||
          r.endpoint !== 'collectCandidate')
      )
        throw new Error('AI_PENDING_RECOVERY_INVALID');
      if (typeof r.hadUncertain !== 'boolean') r.hadUncertain = true;
      out.push(JSON.parse(JSON.stringify(r)) as PendingRequest);
    }
    return out;
  }
  private async merge(
    records: PendingRequest[],
    incoming: PendingRequest
  ): Promise<void> {
    const same = records.find(
      (r) => r.id === incoming.id && r.digest === incoming.digest
    );
    if (!same) {
      records.push(incoming);
      return;
    }
    if (
      (await fingerprint(identity(same))) !==
      (await fingerprint(identity(incoming)))
    )
      throw new Error('AI_PENDING_REQUEST_CONFLICT');
    if (
      same.recoveryFileKey &&
      incoming.recoveryFileKey &&
      same.recoveryFileKey !== incoming.recoveryFileKey
    ) {
      records.push(incoming);
      return;
    }
    // Equal immutable originals can only acquire conservative state, never lose an earlier uncertainty.
    const prepared =
      same.phase === 'prepared' &&
      incoming.phase === 'prepared' &&
      same.attempts === 0 &&
      incoming.attempts === 0 &&
      !same.hadUncertain &&
      !incoming.hadUncertain;
    const rejectionEqual =
      (await fingerprint(same.rejection || null)) ===
      (await fingerprint(incoming.rejection || null));
    const rejected =
      same.phase === 'rejected' &&
      incoming.phase === 'rejected' &&
      same.attempts === 1 &&
      incoming.attempts === 1 &&
      !same.hadUncertain &&
      !incoming.hadUncertain &&
      rejectionEqual;
    same.attempts = Math.max(same.attempts, incoming.attempts);
    same.hadUncertain = prepared || rejected ? false : true;
    same.phase = prepared
      ? 'prepared'
      : rejected
      ? 'rejected'
      : same.phase === 'preparing' || incoming.phase === 'preparing'
      ? 'preparing'
      : 'unknown';
    same.recoveryFileKey ||= incoming.recoveryFileKey;
    if (!rejectionEqual) delete same.rejection;
  }
  private async read(): Promise<PendingRequest[]> {
    this.raw = this.storage.getItem(this.key);
    if (this.raw !== null && this.raw.length > 4000000)
      throw new Error('AI_PENDING_STORAGE_LIMIT');
    const envelope =
      this.raw === null
        ? { schema: 2, records: [], imports: {} }
        : JSON.parse(this.raw);
    if (
      !envelope ||
      envelope.schema !== 2 ||
      !envelope.imports ||
      typeof envelope.imports !== 'object' ||
      Array.isArray(envelope.imports)
    )
      throw new Error('AI_PENDING_STORAGE_INVALID');
    const records = await this.decodeRecords(envelope.records);
    this.imports = Object.create(null);
    for (const [key, value] of Object.entries(envelope.imports)) {
      if (
        !this.legacyKey(key) ||
        typeof value !== 'string' ||
        !/^[a-f0-9]{64}$/.test(value)
      )
        throw new Error('AI_PENDING_LEGACY_INDEX_INVALID');
      this.imports[key] = value;
    }
    const keys: string[] = [];
    for (let index = 0; index < this.storage.length; index++) {
      const key = this.storage.key(index);
      if (key && this.legacyKey(key)) keys.push(key);
    }
    let changed = false;
    for (const key of keys.sort()) {
      const raw = this.storage.getItem(key);
      if (raw === null) continue;
      if (raw.length > 4000000) throw new Error('AI_PENDING_STORAGE_LIMIT');
      const digest = await fingerprint(raw);
      if (this.imports[key] === digest) continue;
      const legacy = await this.decodeRecords(JSON.parse(raw), key);
      for (const record of legacy) await this.merge(records, record);
      if (this.storage.getItem(key) !== raw)
        throw new Error('AI_PENDING_LEGACY_CHANGED');
      this.imports[key] = digest;
      changed = true;
    }
    if (changed) this.write(records);
    return records;
  }
  private async matching(
    records: PendingRequest[],
    expected: PendingRequest
  ): Promise<PendingRequest> {
    const matches = records.filter((r) => r.id === expected.id);
    if (matches.length !== 1)
      throw new Error(
        matches.length
          ? '同UUID存在多个未确认原请求版本；全部保留供核对，不猜选或清除'
          : 'AI_PENDING_REQUEST_MISSING'
      );
    const current = matches[0];
    if (
      current.digest !== expected.digest ||
      (await fingerprint(identity(current))) !==
        (await fingerprint(identity(expected))) ||
      !sameScope(current.scope, this.scope)
    )
      throw new Error('AI_PENDING_ORIGINAL_CHANGED');
    return current;
  }
  async assertOriginal(record: PendingRequest) {
    return this.locks.run(this.key, async () =>
      this.matching(await this.read(), record)
    );
  }
  async list() {
    return this.locks.run(this.key, () => this.read());
  }
  async prepare(
    endpoint: MutationEndpoint,
    kind: string,
    payload: Record<string, unknown>
  ) {
    return this.locks.run(this.key, () =>
      this.prepareUnlocked(endpoint, kind, payload)
    );
  }
  async phase(
    record: PendingRequest,
    phase: RequestPhase,
    rejection?: RejectionEvidence,
    uncertain = false,
    firstRejected = false
  ) {
    return this.locks.run(this.key, () =>
      this.phaseUnlocked(record, phase, rejection, uncertain, firstRejected)
    );
  }
  async recoveryFile(record: PendingRequest, key: string) {
    return this.locks.run(this.key, () =>
      this.recoveryFileUnlocked(record, key)
    );
  }
  async payload(record: PendingRequest, secret?: string) {
    return this.locks.run(this.key, () => this.payloadUnlocked(record, secret));
  }
  async forget(record: PendingRequest) {
    return this.locks.run(this.key, () => this.forgetUnlocked(record));
  }
  private write(records: PendingRequest[]) {
    if (records.length > 300) throw new Error('AI_PENDING_STORAGE_LIMIT');
    const raw = JSON.stringify({ schema: 2, records, imports: this.imports });
    if (raw.length > 4000000) throw new Error('AI_PENDING_STORAGE_LIMIT');
    if (this.storage.getItem(this.key) !== this.raw)
      throw new Error('AI_PENDING_STORAGE_CHANGED');
    this.storage.setItem(this.key, raw);
    if (this.storage.getItem(this.key) !== raw)
      throw new Error('AI_PENDING_STORAGE_UNCONFIRMED');
    this.raw = raw;
  }
  private async prepareUnlocked(
    endpoint: MutationEndpoint,
    kind: string,
    payload: Record<string, unknown>
  ): Promise<PendingRequest> {
    if (!endpoints.includes(endpoint))
      throw new Error('AI_PENDING_ENDPOINT_INVALID');
    const safe = JSON.parse(JSON.stringify(payload)) as Record<string, unknown>,
      id = safe.request_id;
    if (typeof id !== 'string' || !/^[-A-Za-z0-9:_]{1,100}$/.test(id))
      throw new Error('AI_PENDING_REQUEST_INVALID');
    let proof: string | undefined;
    if (endpoint === 'saveCredential') {
      const secret = safe.secret;
      if (typeof secret !== 'string' || secret.length < 8)
        throw new Error('请重新输入原凭据');
      proof = await fingerprint([id, 'credential-equality', secret]);
      delete safe.secret;
    }
    canonical(safe);
    const records = await this.read(),
      matches = records.filter((r) => r.id === id),
      existing = matches[0];
    if (matches.length > 1)
      throw new Error('同UUID存在多个未确认原请求版本；全部保留，不能覆盖');
    if (existing) {
      if (
        existing.endpoint !== endpoint ||
        existing.kind !== kind ||
        (await fingerprint(existing.payload)) !== (await fingerprint(safe)) ||
        existing.secretFingerprint !== proof
      )
        throw new Error('AI_PENDING_REQUEST_CONFLICT');
      return existing;
    }
    if (records.length >= 30) throw new Error('待恢复请求已满，请先核对原请求');
    if (
      records.some(
        (r) =>
          conflictKey(r.endpoint, r.kind, r.payload) ===
          conflictKey(endpoint, kind, safe)
      )
    )
      throw new Error('该对象已有原请求待恢复；不能另换UUID');
    const r: PendingRequest = {
      schema: 1,
      id,
      endpoint,
      kind,
      payload: safe,
      scope: { ...this.scope },
      createdAt: Date.now(),
      digest: '',
      ...(proof ? { secretFingerprint: proof } : {}),
      phase: 'prepared',
      attempts: 0,
      hadUncertain: false,
    };
    r.digest = await fingerprint(identity(r));
    this.write([...records, r]);
    return r;
  }
  private async phaseUnlocked(
    record: PendingRequest,
    phase: RequestPhase,
    rejection?: RejectionEvidence,
    uncertain = false,
    firstRejected = false
  ): Promise<PendingRequest> {
    const records = await this.read(),
      r = await this.matching(records, record);
    if (
      uncertain ||
      phase === 'preparing' ||
      (phase === 'unknown' && !rejection)
    )
      r.hadUncertain = true;
    if (firstRejected && rejection && r.attempts === 1) r.hadUncertain = false;
    r.phase = phase;
    if (phase === 'unknown' && !rejection && !uncertain) r.attempts++;
    if (rejection) r.rejection = rejection;
    this.write(records);
    return r;
  }
  private async recoveryFileUnlocked(record: PendingRequest, key: string) {
    if (!/^file_[a-f0-9]{32}$/.test(key))
      throw new Error('请选择真实同字节File');
    const records = await this.read(),
      r = await this.matching(records, record);
    if (r.endpoint !== 'collectCandidate')
      throw new Error('AI_PENDING_RECOVERY_INVALID');
    r.recoveryFileKey = key;
    this.write(records);
    return r;
  }
  private async payloadUnlocked(
    record: PendingRequest,
    secret?: string
  ): Promise<Record<string, unknown>> {
    const current = await this.matching(await this.read(), record);
    if (current.endpoint === 'saveCredential') {
      if (
        typeof secret !== 'string' ||
        (await fingerprint([current.id, 'credential-equality', secret])) !==
          current.secretFingerprint
      )
        throw new Error('须重新输入与原请求完全相同的凭据；不会改为新密钥');
      return { ...current.payload, secret };
    }
    return {
      ...current.payload,
      ...(current.recoveryFileKey
        ? { recovered_file_key: current.recoveryFileKey }
        : {}),
    };
  }
  private async forgetUnlocked(record: PendingRequest) {
    const records = await this.read();
    const current = await this.matching(records, record);
    this.write(records.filter((r) => r !== current));
  }
}
