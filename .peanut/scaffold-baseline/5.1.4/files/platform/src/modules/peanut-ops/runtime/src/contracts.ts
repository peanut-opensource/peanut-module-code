export type HealthStatus = 'healthy' | 'degraded' | 'unhealthy';
export type UpgradeState =
  | 'configuration_required'
  | 'blocked'
  | 'ready'
  | 'running'
  | 'succeeded'
  | 'failed';
export type OpsTaskStatus =
  | 'queued'
  | 'running'
  | 'succeeded'
  | 'dead'
  | 'cancelled';
export type MaintenanceState = 'scheduled' | 'active' | 'closed';
export const LOG_SEVERITIES = ['info', 'warning', 'error', 'critical'] as const;
export type LogSeverity = (typeof LOG_SEVERITIES)[number];

export interface HealthCheck {
  readonly key: string;
  readonly status: 'up' | 'down';
  readonly critical: boolean;
  readonly latencyMs: number;
}
export interface OpsStatus {
  readonly health: {
    readonly status: HealthStatus;
    readonly checks: readonly HealthCheck[];
  };
  readonly version: {
    readonly commit: string | null;
    readonly tree: string | null;
    readonly releaseKey: string | null;
    readonly builtAt: string;
  };
  readonly migrations: {
    readonly applied: number;
    readonly target: number;
    readonly pending: number;
    readonly inventoryDigest: string;
    readonly drift: boolean;
  };
  readonly upgrade: {
    readonly state: UpgradeState;
    readonly code: string;
    readonly sourceCommit: string | null;
    readonly targetCommit: string | null;
    readonly repositoryClean: boolean | null;
    readonly backupVerified: boolean;
    readonly sourceEvidenceMatches: boolean;
  };
}
export interface OpsTask {
  readonly taskKey: string;
  readonly taskType: 'ops.backup.create' | 'ops.restore.verify';
  readonly status: OpsTaskStatus;
  readonly attemptCount: number;
  readonly maxAttempts: number;
  readonly revision: number;
  readonly lastErrorCode: string | null;
  readonly availableAt: string;
  readonly createdAt: string;
  readonly updatedAt: string;
  readonly completedAt: string | null;
}
export interface MaintenanceWindow {
  readonly maintenanceKey: string;
  readonly state: MaintenanceState;
  readonly reasonKey: string;
  readonly startsAt: string;
  readonly endsAt: string;
  readonly revision: number;
}
export interface RuntimeLogEntry {
  readonly eventKey: string;
  readonly severity: LogSeverity;
  readonly componentKey: string;
  readonly message: string;
  readonly occurredAt: string;
  readonly requestId: string | null;
  readonly occurrences: number;
}
export interface RuntimeLogPage {
  readonly items: readonly RuntimeLogEntry[];
  readonly nextCursor: string | null;
}
export interface OpsTransportResult {
  readonly body: unknown;
  readonly headers: Headers;
  readonly status: number;
}
export interface MaintenanceScheduleInput {
  readonly reasonKey: string;
  readonly startsAt: string;
  readonly endsAt: string;
}
export interface OpsConsoleTransport {
  overview: (signal: AbortSignal) => Promise<OpsTransportResult>;
  submitBackup: (
    providerKey: string,
    idempotencyKey: string,
    signal: AbortSignal
  ) => Promise<OpsTransportResult>;
  submitRestore: (
    providerKey: string,
    backupReferenceKey: string,
    targetKey: string,
    idempotencyKey: string,
    signal: AbortSignal
  ) => Promise<OpsTransportResult>;
  task: (taskKey: string, signal: AbortSignal) => Promise<OpsTransportResult>;
  maintenance: (signal: AbortSignal) => Promise<OpsTransportResult>;
  scheduleMaintenance: (
    input: MaintenanceScheduleInput,
    expectedRevision: number,
    idempotencyKey: string,
    signal: AbortSignal
  ) => Promise<OpsTransportResult>;
  closeMaintenance: (
    maintenanceKey: string,
    expectedRevision: number,
    idempotencyKey: string,
    signal: AbortSignal
  ) => Promise<OpsTransportResult>;
  logs: (
    sourceKey: string,
    severity: LogSeverity,
    cursor: string | null,
    pageSize: number,
    signal: AbortSignal
  ) => Promise<OpsTransportResult>;
}

const invalid = (): never => {
  throw new Error('OPS_RESPONSE_INVALID');
};
const record = (value: unknown): Record<string, unknown> => {
  if (typeof value !== 'object' || value === null || Array.isArray(value))
    return invalid();
  return value as Record<string, unknown>;
};
const exact = (
  value: Record<string, unknown>,
  keys: readonly string[]
): void => {
  const actual = Object.keys(value).sort();
  const expected = [...keys].sort();
  if (
    actual.length !== expected.length ||
    actual.some((key, index) => key !== expected[index])
  )
    invalid();
};
const integer = (value: unknown, minimum = 0): value is number =>
  typeof value === 'number' && Number.isSafeInteger(value) && value >= minimum;
const instant = (value: unknown): value is string =>
  typeof value === 'string' &&
  /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/.test(value) &&
  Number.isFinite(Date.parse(value));
const qualifiedKey = (value: unknown, maximum = 128): value is string =>
  typeof value === 'string' &&
  value.length <= maximum &&
  /^[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*$/.test(value);
const stableCode = (value: unknown): value is string =>
  typeof value === 'string' &&
  /^[A-Z][A-Z0-9]*(?:_[A-Z0-9]+)*$/.test(value) &&
  value.length <= 128;
const commit = (value: unknown): value is string =>
  typeof value === 'string' && /^[0-9a-f]{40}$/.test(value);
const opaque = (value: unknown, prefix: string): value is string =>
  typeof value === 'string' &&
  new RegExp(`^${prefix}[0-9a-f]{32}$`).test(value);
const safePublicText = (value: unknown): value is string =>
  typeof value === 'string' &&
  value.length >= 1 &&
  value.length <= 240 &&
  !/[\u0000-\u001f\u007f]/.test(value) &&
  !/(?:password|passwd|secret|token|credential|authorization)\s*[:=]|(?:mysql|postgres(?:ql)?|redis):\/\/|\b(?:select|insert|update|delete|drop|alter)\s+|(?:^|\s)(?:\/[A-Za-z0-9._-]+){2,}|(?:^|\s)[A-Za-z]:\\|\bstack\s+trace\b|https?:\/\/[^\s/@]+:[^\s/@]+@/i.test(
    value
  );
const envelope = (value: unknown): { data: unknown; requestId: string } => {
  const body = record(value);
  exact(body, ['data', 'meta']);
  const meta = record(body.meta);
  exact(meta, ['request_id']);
  if (
    typeof meta.request_id !== 'string' ||
    meta.request_id.length < 1 ||
    meta.request_id.length > 128
  )
    return invalid();
  return { data: body.data, requestId: meta.request_id };
};

export const parseOpsStatus = (value: unknown): OpsStatus => {
  const data = record(envelope(value).data);
  exact(data, ['health', 'version', 'migrations', 'upgrade']);
  const health = record(data.health);
  exact(health, ['status', 'checks']);
  const version = record(data.version);
  exact(version, ['commit', 'tree', 'release_key', 'built_at']);
  const migrations = record(data.migrations);
  exact(migrations, [
    'applied',
    'target',
    'pending',
    'inventory_digest',
    'drift',
  ]);
  const upgrade = record(data.upgrade);
  exact(upgrade, [
    'state',
    'code',
    'source_commit',
    'target_commit',
    'repository_clean',
    'backup_verified',
    'source_evidence_matches',
  ]);
  if (
    !['healthy', 'degraded', 'unhealthy'].includes(String(health.status)) ||
    !Array.isArray(health.checks) ||
    health.checks.length > 32 ||
    (version.commit !== null && !commit(version.commit)) ||
    (version.tree !== null && !commit(version.tree)) ||
    (version.release_key !== null && !qualifiedKey(version.release_key)) ||
    !instant(version.built_at) ||
    !integer(migrations.applied) ||
    !integer(migrations.target) ||
    !integer(migrations.pending) ||
    migrations.applied + migrations.pending !== migrations.target ||
    typeof migrations.inventory_digest !== 'string' ||
    !/^[0-9a-f]{64}$/.test(migrations.inventory_digest) ||
    typeof migrations.drift !== 'boolean' ||
    ![
      'configuration_required',
      'blocked',
      'ready',
      'running',
      'succeeded',
      'failed',
    ].includes(String(upgrade.state)) ||
    !stableCode(upgrade.code) ||
    (upgrade.source_commit !== null && !commit(upgrade.source_commit)) ||
    (upgrade.target_commit !== null && !commit(upgrade.target_commit)) ||
    (upgrade.repository_clean !== null &&
      typeof upgrade.repository_clean !== 'boolean') ||
    typeof upgrade.backup_verified !== 'boolean' ||
    typeof upgrade.source_evidence_matches !== 'boolean'
  )
    return invalid();
  const checks = health.checks.map((item) => {
    const check = record(item);
    exact(check, ['key', 'status', 'critical', 'latency_ms']);
    if (
      !qualifiedKey(check.key, 64) ||
      !['up', 'down'].includes(String(check.status)) ||
      typeof check.critical !== 'boolean' ||
      typeof check.latency_ms !== 'number' ||
      !Number.isFinite(check.latency_ms) ||
      check.latency_ms < 0 ||
      check.latency_ms > 60000
    )
      return invalid();
    return {
      key: check.key,
      status: check.status as 'up' | 'down',
      critical: check.critical,
      latencyMs: check.latency_ms,
    };
  });
  const criticalCheckDown = checks.some(
    (check) => check.critical && check.status === 'down'
  );
  if (
    (health.status === 'healthy' &&
      (criticalCheckDown || migrations.drift || migrations.pending > 0)) ||
    (['ready', 'running', 'succeeded'].includes(String(upgrade.state)) &&
      (version.commit === null ||
        version.tree === null ||
        upgrade.repository_clean === null)) ||
    (upgrade.state === 'succeeded' &&
      (!upgrade.repository_clean ||
        !upgrade.backup_verified ||
        !upgrade.source_evidence_matches))
  )
    return invalid();
  return {
    health: { status: health.status as HealthStatus, checks },
    version: {
      commit: version.commit,
      tree: version.tree,
      releaseKey: version.release_key as string | null,
      builtAt: version.built_at,
    },
    migrations: {
      applied: migrations.applied,
      target: migrations.target,
      pending: migrations.pending,
      inventoryDigest: migrations.inventory_digest,
      drift: migrations.drift,
    },
    upgrade: {
      state: upgrade.state as UpgradeState,
      code: upgrade.code,
      sourceCommit: upgrade.source_commit as string | null,
      targetCommit: upgrade.target_commit as string | null,
      repositoryClean: upgrade.repository_clean,
      backupVerified: upgrade.backup_verified,
      sourceEvidenceMatches: upgrade.source_evidence_matches,
    },
  };
};

export const parseOpsTask = (value: unknown): OpsTask => {
  const item = record(envelope(value).data);
  exact(item, [
    'task_key',
    'task_type',
    'status',
    'attempt_count',
    'max_attempts',
    'revision',
    'last_error_code',
    'available_at',
    'created_at',
    'updated_at',
    'completed_at',
  ]);
  const terminal = ['succeeded', 'dead', 'cancelled'].includes(
    String(item.status)
  );
  if (
    !opaque(item.task_key, 'job_') ||
    !['ops.backup.create', 'ops.restore.verify'].includes(
      String(item.task_type)
    ) ||
    !['queued', 'running', 'succeeded', 'dead', 'cancelled'].includes(
      String(item.status)
    ) ||
    !integer(item.attempt_count) ||
    !integer(item.max_attempts, 1) ||
    item.max_attempts > 10 ||
    item.attempt_count > item.max_attempts ||
    !integer(item.revision, 1) ||
    (item.last_error_code !== null && !stableCode(item.last_error_code)) ||
    !instant(item.available_at) ||
    !instant(item.created_at) ||
    !instant(item.updated_at) ||
    (item.completed_at !== null && !instant(item.completed_at)) ||
    terminal !== (item.completed_at !== null)
  )
    return invalid();
  return {
    taskKey: item.task_key,
    taskType: item.task_type as OpsTask['taskType'],
    status: item.status as OpsTaskStatus,
    attemptCount: item.attempt_count,
    maxAttempts: item.max_attempts,
    revision: item.revision,
    lastErrorCode: item.last_error_code as string | null,
    availableAt: item.available_at,
    createdAt: item.created_at,
    updatedAt: item.updated_at,
    completedAt: item.completed_at as string | null,
  };
};

const parseMaintenanceData = (value: unknown): MaintenanceWindow | null => {
  if (value === null) return null;
  const item = record(value);
  exact(item, [
    'maintenance_key',
    'state',
    'reason_key',
    'starts_at',
    'ends_at',
    'revision',
  ]);
  if (
    !opaque(item.maintenance_key, 'maintenance_') ||
    !['scheduled', 'active', 'closed'].includes(String(item.state)) ||
    !qualifiedKey(item.reason_key, 64) ||
    !instant(item.starts_at) ||
    !instant(item.ends_at) ||
    Date.parse(item.ends_at) <= Date.parse(item.starts_at) ||
    Date.parse(item.ends_at) - Date.parse(item.starts_at) > 86_400_000 ||
    !integer(item.revision, 1)
  )
    return invalid();
  return {
    maintenanceKey: item.maintenance_key,
    state: item.state as MaintenanceState,
    reasonKey: item.reason_key,
    startsAt: item.starts_at,
    endsAt: item.ends_at,
    revision: item.revision,
  };
};
export const parseMaintenance = (value: unknown): MaintenanceWindow | null =>
  parseMaintenanceData(envelope(value).data);

export const parseRuntimeLogs = (value: unknown): RuntimeLogPage => {
  const data = record(envelope(value).data);
  exact(data, ['items', 'next_cursor']);
  if (
    !Array.isArray(data.items) ||
    data.items.length > 100 ||
    (data.next_cursor !== null &&
      (typeof data.next_cursor !== 'string' ||
        !/^cursor_[A-Za-z0-9_-]{8,200}$/.test(data.next_cursor)))
  )
    return invalid();
  const items = data.items.map((value) => {
    const item = record(value);
    exact(item, [
      'event_key',
      'severity',
      'component_key',
      'message',
      'occurred_at',
      'request_id',
      'occurrences',
    ]);
    if (
      !qualifiedKey(item.event_key) ||
      !LOG_SEVERITIES.includes(item.severity as LogSeverity) ||
      !qualifiedKey(item.component_key) ||
      !safePublicText(item.message) ||
      !instant(item.occurred_at) ||
      (item.request_id !== null &&
        (typeof item.request_id !== 'string' ||
          !/^[A-Za-z0-9._-]{1,128}$/.test(item.request_id))) ||
      !integer(item.occurrences, 1) ||
      item.occurrences > 1_000_000
    )
      return invalid();
    return {
      eventKey: item.event_key,
      severity: item.severity as LogSeverity,
      componentKey: item.component_key,
      message: item.message,
      occurredAt: item.occurred_at,
      requestId: item.request_id as string | null,
      occurrences: item.occurrences,
    };
  });
  return { items, nextCursor: data.next_cursor as string | null };
};

export const createOpsConsoleFetchTransport = (options: {
  readonly baseUrl: string;
  readonly fetch?: (request: Request) => Promise<Response>;
}): OpsConsoleTransport => {
  const fetcher = options.fetch ?? fetch;
  const request = async (
    path: string,
    init: RequestInit
  ): Promise<OpsTransportResult> => {
    const headers = new Headers(init.headers);
    headers.set('Accept', 'application/json');
    if (init.body !== undefined)
      headers.set('Content-Type', 'application/json');
    const response = await fetcher(
      new Request(new URL(path, options.baseUrl), {
        credentials: 'include',
        ...init,
        headers,
      })
    );
    return {
      body: response.status === 204 ? null : await response.json(),
      headers: response.headers,
      status: response.status,
    };
  };
  const writeHeaders = (
    idempotencyKey: string,
    revision?: number
  ): HeadersInit => ({
    'Idempotency-Key': idempotencyKey,
    ...(revision === undefined ? {} : { 'If-Match': `"rev-${revision}"` }),
  });
  return {
    overview: (signal) =>
      request('/api/platform/v1/ops/status', { method: 'GET', signal }),
    submitBackup: (providerKey, idempotencyKey, signal) =>
      request('/api/platform/v1/ops/tasks/backup', {
        method: 'POST',
        headers: writeHeaders(idempotencyKey),
        body: JSON.stringify({ provider_key: providerKey }),
        signal,
      }),
    submitRestore: (
      providerKey,
      backupReferenceKey,
      targetKey,
      idempotencyKey,
      signal
    ) =>
      request('/api/platform/v1/ops/tasks/restore', {
        method: 'POST',
        headers: writeHeaders(idempotencyKey),
        body: JSON.stringify({
          provider_key: providerKey,
          backup_reference_key: backupReferenceKey,
          target_key: targetKey,
        }),
        signal,
      }),
    task: (taskKey, signal) =>
      request(`/api/platform/v1/ops/tasks/${encodeURIComponent(taskKey)}`, {
        method: 'GET',
        signal,
      }),
    maintenance: (signal) =>
      request('/api/platform/v1/ops/maintenance', { method: 'GET', signal }),
    scheduleMaintenance: (input, expectedRevision, idempotencyKey, signal) =>
      request('/api/platform/v1/ops/maintenance', {
        method: 'PUT',
        headers: writeHeaders(idempotencyKey, expectedRevision),
        body: JSON.stringify({
          reason_key: input.reasonKey,
          starts_at: input.startsAt,
          ends_at: input.endsAt,
        }),
        signal,
      }),
    closeMaintenance: (
      maintenanceKey,
      expectedRevision,
      idempotencyKey,
      signal
    ) =>
      request(
        `/api/platform/v1/ops/maintenance/${encodeURIComponent(
          maintenanceKey
        )}/close`,
        {
          method: 'POST',
          headers: writeHeaders(idempotencyKey, expectedRevision),
          body: '{}',
          signal,
        }
      ),
    logs: (sourceKey, severity, cursor, pageSize, signal) => {
      const query = new URLSearchParams({
        source: sourceKey,
        severity,
        page_size: String(pageSize),
      });
      if (cursor !== null) query.set('cursor', cursor);
      return request(`/api/platform/v1/ops/logs?${query}`, {
        method: 'GET',
        signal,
      });
    },
  };
};
