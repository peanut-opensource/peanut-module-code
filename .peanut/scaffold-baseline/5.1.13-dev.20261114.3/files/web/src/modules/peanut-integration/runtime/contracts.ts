export type MachineStatus = 'active' | 'rotated' | 'revoked';
export type WebhookStatus = 'active' | 'disabled';
export type DeliveryStatus =
  | 'pending'
  | 'delivering'
  | 'retryable'
  | 'delivered'
  | 'permanent_failed';

export interface MachineIdentity {
  readonly identityKey: string;
  readonly name: string;
  readonly scopes: readonly string[];
  readonly status: MachineStatus;
  readonly tokenPrefix: string;
  readonly tokenLastFour: string;
  readonly expiresAt: string | null;
  readonly lastUsedAt: string | null;
  readonly revision: number;
  readonly createdAt: string;
}
export interface ProvisionedMachineIdentity {
  readonly identity: MachineIdentity;
  readonly token: string;
}
export interface WebhookEndpoint {
  readonly endpointKey: string;
  readonly name: string;
  readonly url: string;
  readonly events: readonly string[];
  readonly status: WebhookStatus;
  readonly revision: number;
  readonly createdAt: string;
}
export interface ProvisionedWebhookEndpoint {
  readonly endpoint: WebhookEndpoint;
  readonly signingSecret: string;
}
export interface WebhookDeliveryRecord {
  readonly deliveryKey: string;
  readonly endpointKey: string;
  readonly eventType: string;
  readonly status: DeliveryStatus;
  readonly attemptCount: number;
  readonly lastStatusCode: number | null;
  readonly lastErrorCode: string | null;
  readonly createdAt: string;
  readonly updatedAt: string;
  readonly deliveredAt: string | null;
}
export interface WebhookAttemptRecord {
  readonly attemptNumber: number;
  readonly outcome: 'retryable' | 'delivered' | 'permanent_failed';
  readonly responseStatus: number | null;
  readonly errorCode: string | null;
  readonly durationMs: number;
  readonly attemptedAt: string;
}
export interface SessionDevice {
  readonly sessionKey: string;
  readonly clientKey: string;
  readonly status: 'active' | 'revoked' | 'expired';
  readonly current: boolean;
  readonly maskedIp: string | null;
  readonly userAgentFingerprint: string | null;
  readonly issuedAt: string;
  readonly lastSeenAt: string;
  readonly absoluteExpiresAt: string;
  readonly revokedAt: string | null;
}
export interface Page<T> {
  readonly items: T[];
  readonly page: number;
  readonly pageSize: number;
  readonly total: number;
}
export interface TransportResult {
  readonly body: unknown;
  readonly headers: Headers;
  readonly status: number;
}
export interface IntegrationSecurityTransport {
  machines: (signal: AbortSignal) => Promise<TransportResult>;
  createMachine: (
    input: { name: string; scopes: string[]; expires_at: string | null },
    signal: AbortSignal
  ) => Promise<TransportResult>;
  rotateMachine: (
    identityKey: string,
    revision: number,
    signal: AbortSignal
  ) => Promise<TransportResult>;
  revokeMachine: (
    identityKey: string,
    revision: number,
    signal: AbortSignal
  ) => Promise<TransportResult>;
  webhooks: (signal: AbortSignal) => Promise<TransportResult>;
  createWebhook: (
    input: { name: string; url: string; events: string[] },
    signal: AbortSignal
  ) => Promise<TransportResult>;
  rotateWebhook: (
    endpointKey: string,
    revision: number,
    signal: AbortSignal
  ) => Promise<TransportResult>;
  disableWebhook: (
    endpointKey: string,
    revision: number,
    signal: AbortSignal
  ) => Promise<TransportResult>;
  deliveries: (
    page: number,
    pageSize: number,
    signal: AbortSignal
  ) => Promise<TransportResult>;
  deliveryAttempts: (
    deliveryKey: string,
    page: number,
    pageSize: number,
    signal: AbortSignal
  ) => Promise<TransportResult>;
  sessions: (signal: AbortSignal) => Promise<TransportResult>;
  revokeSession: (
    sessionKey: string,
    signal: AbortSignal
  ) => Promise<TransportResult>;
}

const record = (value: unknown): Record<string, unknown> => {
  if (typeof value !== 'object' || value === null || Array.isArray(value))
    throw new Error('INTEGRATION_RESPONSE_INVALID');
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
    throw new Error('INTEGRATION_RESPONSE_INVALID');
};
const instant = (value: unknown): value is string =>
  typeof value === 'string' &&
  /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/.test(value) &&
  Number.isFinite(Date.parse(value));
const qualified = /^[a-z][a-z0-9]*(?:[.-][a-z0-9]+)+$/;
const requestId = (value: unknown): value is string =>
  typeof value === 'string' &&
  /^[A-Za-z0-9][A-Za-z0-9._:-]{7,127}$/.test(value);
const statusCode = (value: unknown): value is number =>
  typeof value === 'number' &&
  Number.isSafeInteger(value) &&
  value >= 100 &&
  value <= 599;
const safeCode = (value: unknown): value is string =>
  typeof value === 'string' && /^[A-Z][A-Z0-9_]{2,63}$/.test(value);

export const parseMachine = (value: unknown): MachineIdentity => {
  const item = record(value);
  const { scopes } = item;
  exact(item, [
    'identity_key',
    'name',
    'scopes',
    'status',
    'token_prefix',
    'token_last_four',
    'expires_at',
    'last_used_at',
    'revision',
    'created_at',
  ]);
  if (
    typeof item.identity_key !== 'string' ||
    !/^machine_[0-9a-f]{32}$/.test(item.identity_key) ||
    typeof item.name !== 'string' ||
    item.name === '' ||
    [...item.name].length > 120 ||
    !Array.isArray(scopes) ||
    scopes.length < 1 ||
    scopes.length > 32 ||
    scopes.some(
      (scope) => typeof scope !== 'string' || !qualified.test(scope)
    ) ||
    new Set(scopes).size !== scopes.length ||
    [...scopes].sort().some((scope, index) => scope !== scopes[index]) ||
    !['active', 'rotated', 'revoked'].includes(String(item.status)) ||
    typeof item.token_prefix !== 'string' ||
    !item.token_prefix.startsWith('pa_mi_') ||
    typeof item.token_last_four !== 'string' ||
    !/^[A-Za-z0-9_-]{4}$/.test(item.token_last_four) ||
    (item.expires_at !== null && !instant(item.expires_at)) ||
    (item.last_used_at !== null && !instant(item.last_used_at)) ||
    typeof item.revision !== 'number' ||
    !Number.isSafeInteger(item.revision) ||
    item.revision < 1 ||
    !instant(item.created_at)
  )
    throw new Error('INTEGRATION_RESPONSE_INVALID');
  return {
    identityKey: item.identity_key,
    name: item.name,
    scopes: scopes as string[],
    status: item.status as MachineStatus,
    tokenPrefix: item.token_prefix,
    tokenLastFour: item.token_last_four,
    expiresAt: item.expires_at as string | null,
    lastUsedAt: item.last_used_at as string | null,
    revision: item.revision,
    createdAt: item.created_at,
  };
};
export const parseProvisionedMachine = (
  value: unknown
): ProvisionedMachineIdentity => {
  const item = record(value);
  exact(item, ['identity', 'token']);
  if (
    typeof item.token !== 'string' ||
    !/^pa_mi_[A-Za-z0-9_-]{43}$/.test(item.token)
  )
    throw new Error('INTEGRATION_RESPONSE_INVALID');
  return { identity: parseMachine(item.identity), token: item.token };
};
export const parseWebhook = (value: unknown): WebhookEndpoint => {
  const item = record(value);
  exact(item, [
    'endpoint_key',
    'name',
    'url',
    'events',
    'status',
    'revision',
    'created_at',
  ]);
  if (
    typeof item.endpoint_key !== 'string' ||
    !/^webhook_[0-9a-f]{32}$/.test(item.endpoint_key) ||
    typeof item.name !== 'string' ||
    item.name === '' ||
    [...item.name].length > 120 ||
    typeof item.url !== 'string' ||
    !item.url.startsWith('https://') ||
    item.url.length > 2048 ||
    !Array.isArray(item.events) ||
    item.events.length < 1 ||
    item.events.length > 32 ||
    item.events.some(
      (event) => typeof event !== 'string' || !qualified.test(event)
    ) ||
    new Set(item.events).size !== item.events.length ||
    !['active', 'disabled'].includes(String(item.status)) ||
    typeof item.revision !== 'number' ||
    !Number.isSafeInteger(item.revision) ||
    item.revision < 1 ||
    !instant(item.created_at)
  )
    throw new Error('INTEGRATION_RESPONSE_INVALID');
  return {
    endpointKey: item.endpoint_key,
    name: item.name,
    url: item.url,
    events: item.events as string[],
    status: item.status as WebhookStatus,
    revision: item.revision,
    createdAt: item.created_at,
  };
};
export const parseProvisionedWebhook = (
  value: unknown
): ProvisionedWebhookEndpoint => {
  const item = record(value);
  exact(item, ['endpoint', 'signing_secret']);
  if (
    typeof item.signing_secret !== 'string' ||
    !/^whsec_[A-Za-z0-9_-]{43}$/.test(item.signing_secret)
  )
    throw new Error('INTEGRATION_RESPONSE_INVALID');
  return {
    endpoint: parseWebhook(item.endpoint),
    signingSecret: item.signing_secret,
  };
};
export const parseDelivery = (value: unknown): WebhookDeliveryRecord => {
  const item = record(value);
  exact(item, [
    'delivery_key',
    'endpoint_key',
    'event_type',
    'status',
    'attempt_count',
    'last_status_code',
    'last_error_code',
    'created_at',
    'updated_at',
    'delivered_at',
  ]);
  if (
    typeof item.delivery_key !== 'string' ||
    !/^delivery_[0-9a-f]{32}$/.test(item.delivery_key) ||
    typeof item.endpoint_key !== 'string' ||
    !/^webhook_[0-9a-f]{32}$/.test(item.endpoint_key) ||
    typeof item.event_type !== 'string' ||
    !qualified.test(item.event_type) ||
    ![
      'pending',
      'delivering',
      'retryable',
      'delivered',
      'permanent_failed',
    ].includes(String(item.status)) ||
    typeof item.attempt_count !== 'number' ||
    !Number.isSafeInteger(item.attempt_count) ||
    item.attempt_count < 0 ||
    item.attempt_count > 8 ||
    (item.last_status_code !== null && !statusCode(item.last_status_code)) ||
    (item.last_error_code !== null && !safeCode(item.last_error_code)) ||
    !instant(item.created_at) ||
    !instant(item.updated_at) ||
    (item.delivered_at !== null && !instant(item.delivered_at))
  )
    throw new Error('INTEGRATION_RESPONSE_INVALID');
  return {
    deliveryKey: item.delivery_key,
    endpointKey: item.endpoint_key,
    eventType: item.event_type,
    status: item.status as DeliveryStatus,
    attemptCount: item.attempt_count,
    lastStatusCode: item.last_status_code as number | null,
    lastErrorCode: item.last_error_code as string | null,
    createdAt: item.created_at,
    updatedAt: item.updated_at,
    deliveredAt: item.delivered_at as string | null,
  };
};
export const parseAttempt = (value: unknown): WebhookAttemptRecord => {
  const item = record(value);
  exact(item, [
    'attempt_number',
    'outcome',
    'response_status',
    'error_code',
    'duration_ms',
    'attempted_at',
  ]);
  if (
    typeof item.attempt_number !== 'number' ||
    !Number.isSafeInteger(item.attempt_number) ||
    item.attempt_number < 1 ||
    item.attempt_number > 8 ||
    !['retryable', 'delivered', 'permanent_failed'].includes(
      String(item.outcome)
    ) ||
    (item.response_status !== null && !statusCode(item.response_status)) ||
    (item.error_code !== null && !safeCode(item.error_code)) ||
    typeof item.duration_ms !== 'number' ||
    !Number.isSafeInteger(item.duration_ms) ||
    item.duration_ms < 0 ||
    item.duration_ms > 30000 ||
    !instant(item.attempted_at)
  )
    throw new Error('INTEGRATION_RESPONSE_INVALID');
  return {
    attemptNumber: item.attempt_number,
    outcome: item.outcome as WebhookAttemptRecord['outcome'],
    responseStatus: item.response_status as number | null,
    errorCode: item.error_code as string | null,
    durationMs: item.duration_ms,
    attemptedAt: item.attempted_at,
  };
};
export const parseSession = (value: unknown): SessionDevice => {
  const item = record(value);
  exact(item, [
    'session_key',
    'client_key',
    'status',
    'current',
    'masked_ip',
    'user_agent_fingerprint',
    'issued_at',
    'last_seen_at',
    'absolute_expires_at',
    'revoked_at',
  ]);
  if (
    typeof item.session_key !== 'string' ||
    !/^[0-9A-HJKMNP-TV-Z]{26}$/.test(item.session_key) ||
    item.client_key !== 'admin-web' ||
    !['active', 'revoked', 'expired'].includes(String(item.status)) ||
    typeof item.current !== 'boolean' ||
    (item.masked_ip !== null &&
      (typeof item.masked_ip !== 'string' || item.masked_ip.length > 45)) ||
    (item.user_agent_fingerprint !== null &&
      (typeof item.user_agent_fingerprint !== 'string' ||
        !/^[0-9a-f]{12}$/.test(item.user_agent_fingerprint))) ||
    !instant(item.issued_at) ||
    !instant(item.last_seen_at) ||
    !instant(item.absolute_expires_at) ||
    (item.revoked_at !== null && !instant(item.revoked_at))
  )
    throw new Error('INTEGRATION_RESPONSE_INVALID');
  return {
    sessionKey: item.session_key,
    clientKey: item.client_key,
    status: item.status as SessionDevice['status'],
    current: item.current,
    maskedIp: item.masked_ip as string | null,
    userAgentFingerprint: item.user_agent_fingerprint as string | null,
    issuedAt: item.issued_at,
    lastSeenAt: item.last_seen_at,
    absoluteExpiresAt: item.absolute_expires_at,
    revokedAt: item.revoked_at as string | null,
  };
};
export const parseList = <T>(
  value: unknown,
  parser: (item: unknown) => T
): T[] => {
  const body = record(value);
  exact(body, ['data', 'meta']);
  const data = record(body.data);
  const meta = record(body.meta);
  exact(data, ['items']);
  exact(meta, ['request_id']);
  if (!Array.isArray(data.items) || !requestId(meta.request_id))
    throw new Error('INTEGRATION_RESPONSE_INVALID');
  return data.items.map(parser);
};
export const parseItem = <T>(
  value: unknown,
  parser: (item: unknown) => T
): T => {
  const body = record(value);
  exact(body, ['data', 'meta']);
  const meta = record(body.meta);
  exact(meta, ['request_id']);
  if (!requestId(meta.request_id))
    throw new Error('INTEGRATION_RESPONSE_INVALID');
  return parser(body.data);
};
export const parsePage = <T>(
  value: unknown,
  parser: (item: unknown) => T
): Page<T> => {
  const body = record(value);
  exact(body, ['data', 'meta']);
  const data = record(body.data);
  const meta = record(body.meta);
  exact(data, ['items']);
  exact(meta, ['request_id', 'page', 'page_size', 'total']);
  if (
    !Array.isArray(data.items) ||
    !requestId(meta.request_id) ||
    typeof meta.page !== 'number' ||
    !Number.isSafeInteger(meta.page) ||
    meta.page < 1 ||
    typeof meta.page_size !== 'number' ||
    !Number.isSafeInteger(meta.page_size) ||
    meta.page_size < 1 ||
    meta.page_size > 100 ||
    typeof meta.total !== 'number' ||
    !Number.isSafeInteger(meta.total) ||
    meta.total < 0
  )
    throw new Error('INTEGRATION_RESPONSE_INVALID');
  return {
    items: data.items.map(parser),
    page: meta.page,
    pageSize: meta.page_size,
    total: meta.total,
  };
};
const json = (value: unknown): string => JSON.stringify(value);
export const createIntegrationSecurityFetchTransport = (options: {
  readonly baseUrl: string;
  readonly authorization?: () => string | null;
  readonly fetch?: (request: Request) => Promise<Response>;
}): IntegrationSecurityTransport => {
  const fetcher = options.fetch ?? fetch;
  const request = async (
    path: string,
    init: RequestInit
  ): Promise<TransportResult> => {
    const token = options.authorization?.() ?? null;
    const response = await fetcher(
      new Request(new URL(path, options.baseUrl), {
        credentials: 'same-origin',
        ...init,
        headers: {
          Accept: 'application/json',
          ...(token ? { Authorization: `Bearer ${token}` } : {}),
          ...init.headers,
        },
      })
    );
    return {
      body: await response.json(),
      headers: response.headers,
      status: response.status,
    };
  };
  const write = (
    path: string,
    method: string,
    body: unknown,
    signal: AbortSignal
  ) =>
    request(path, {
      method,
      body: json(body),
      headers: { 'Content-Type': 'application/json' },
      signal,
    });
  return {
    machines: (signal) =>
      request('/adminapi/api/v1/integration-security/machine-identities', {
        method: 'GET',
        signal,
      }),
    createMachine: (input, signal) =>
      write(
        '/adminapi/api/v1/integration-security/machine-identities',
        'POST',
        input,
        signal
      ),
    rotateMachine: (key, revision, signal) =>
      write(
        `/adminapi/api/v1/integration-security/machine-identities/${encodeURIComponent(
          key
        )}/rotate`,
        'POST',
        { revision },
        signal
      ),
    revokeMachine: (key, revision, signal) =>
      write(
        `/adminapi/api/v1/integration-security/machine-identities/${encodeURIComponent(
          key
        )}`,
        'DELETE',
        { revision },
        signal
      ),
    webhooks: (signal) =>
      request('/adminapi/api/v1/integration-security/webhooks', {
        method: 'GET',
        signal,
      }),
    createWebhook: (input, signal) =>
      write(
        '/adminapi/api/v1/integration-security/webhooks',
        'POST',
        input,
        signal
      ),
    rotateWebhook: (key, revision, signal) =>
      write(
        `/adminapi/api/v1/integration-security/webhooks/${encodeURIComponent(
          key
        )}/rotate-secret`,
        'POST',
        { revision },
        signal
      ),
    disableWebhook: (key, revision, signal) =>
      write(
        `/adminapi/api/v1/integration-security/webhooks/${encodeURIComponent(
          key
        )}`,
        'DELETE',
        { revision },
        signal
      ),
    deliveries: (page, pageSize, signal) =>
      request(
        `/adminapi/api/v1/integration-security/deliveries?page=${page}&page_size=${pageSize}`,
        { method: 'GET', signal }
      ),
    deliveryAttempts: (key, page, pageSize, signal) =>
      request(
        `/adminapi/api/v1/integration-security/deliveries/${encodeURIComponent(
          key
        )}/attempts?page=${page}&page_size=${pageSize}`,
        { method: 'GET', signal }
      ),
    sessions: (signal) =>
      request('/adminapi/api/v1/integration-security/sessions', {
        method: 'GET',
        signal,
      }),
    revokeSession: (key, signal) =>
      write(
        `/adminapi/api/v1/integration-security/sessions/${encodeURIComponent(
          key
        )}/revoke`,
        'POST',
        {},
        signal
      ),
  };
};
