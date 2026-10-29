import { inject, reactive } from 'vue';
import type { InjectionKey } from 'vue';
import {
  parseAttempt,
  parseDelivery,
  parseItem,
  parseList,
  parseMachine,
  parsePage,
  parseProvisionedMachine,
  parseProvisionedWebhook,
  parseSession,
  parseWebhook,
} from './contracts';
import type {
  IntegrationSecurityTransport,
  MachineIdentity,
  Page,
  ProvisionedMachineIdentity,
  ProvisionedWebhookEndpoint,
  SessionDevice,
  TransportResult,
  WebhookAttemptRecord,
  WebhookDeliveryRecord,
  WebhookEndpoint,
} from './contracts';

export const INTEGRATION_SECURITY_MODULE_KEY = 'peanut.integration' as const;
export const INTEGRATION_SECURITY_ROUTE_PATH =
  '/system/integration-security' as const;
export const INTEGRATION_SECURITY_ROUTE_PERMISSION =
  'peanut.integration.access' as const;
export const INTEGRATION_SECURITY_READ_PERMISSIONS = [
  'peanut.integration.machine.read',
  'peanut.integration.webhook.read',
  'peanut.integration.delivery.read',
  'peanut.integration.session.read',
] as const;

export type RuntimeErrorCode =
  | 'INTEGRATION_PERMISSION_DENIED'
  | 'INTEGRATION_INPUT_INVALID'
  | 'INTEGRATION_REVISION_CONFLICT'
  | 'INTEGRATION_REQUEST_FAILED'
  | 'INTEGRATION_NETWORK_FAILED'
  | 'INTEGRATION_RESPONSE_INVALID'
  | 'INTEGRATION_MUTATION_FAILED'
  | 'MACHINE_IDENTITY_NOT_FOUND'
  | 'MACHINE_TOKEN_INVALID'
  | 'MACHINE_TOKEN_EXPIRED'
  | 'MACHINE_SCOPE_DENIED'
  | 'WEBHOOK_ENDPOINT_NOT_FOUND'
  | 'WEBHOOK_DESTINATION_DENIED'
  | 'WEBHOOK_SECRET_INVALID'
  | 'SESSION_DEVICE_NOT_FOUND';
export interface RuntimeError {
  readonly code: RuntimeErrorCode;
  readonly message: string;
  readonly requestId: string | null;
  readonly status: number | null;
}
export interface SurfaceState<T> {
  items: T[];
  loading: boolean;
  error: RuntimeError | null;
}
export interface IntegrationSecurityState {
  machines: SurfaceState<MachineIdentity>;
  webhooks: SurfaceState<WebhookEndpoint>;
  deliveries: SurfaceState<WebhookDeliveryRecord> & {
    page: number;
    pageSize: number;
    total: number;
  };
  attempts: SurfaceState<WebhookAttemptRecord> & { deliveryKey: string | null };
  sessions: SurfaceState<SessionDevice>;
  mutating: boolean;
  disclosure: {
    kind: 'machine-token' | 'webhook-secret';
    value: string;
  } | null;
}
export interface IntegrationSecurityPermissions {
  readonly canReadMachines: () => boolean;
  readonly canManageMachines: () => boolean;
  readonly canReadWebhooks: () => boolean;
  readonly canManageWebhooks: () => boolean;
  readonly canReadDeliveries: () => boolean;
  readonly canReadSessions: () => boolean;
  readonly canRevokeSession: () => boolean;
}

export interface IntegrationSecurityRuntime {
  readonly state: IntegrationSecurityState;
  load: () => Promise<void>;
  loadMachines: () => Promise<void>;
  loadWebhooks: () => Promise<void>;
  loadDeliveries: (page?: number) => Promise<void>;
  loadAttempts: (deliveryKey: string) => Promise<void>;
  loadSessions: () => Promise<void>;
  createMachine: (input: {
    name: string;
    scopes: string[];
    expires_at: string | null;
  }) => Promise<void>;
  rotateMachine: (identity: MachineIdentity) => Promise<void>;
  revokeMachine: (identity: MachineIdentity) => Promise<void>;
  createWebhook: (input: {
    name: string;
    url: string;
    events: string[];
  }) => Promise<void>;
  rotateWebhook: (endpoint: WebhookEndpoint) => Promise<void>;
  disableWebhook: (endpoint: WebhookEndpoint) => Promise<void>;
  revokeSession: (session: SessionDevice) => Promise<void>;
  clearDisclosure: () => void;
  dispose: () => void;
  readonly can: IntegrationSecurityPermissions;
}

const requestIdPattern = /^[A-Za-z0-9][A-Za-z0-9._:-]{7,127}$/;
const messages: Readonly<Record<RuntimeErrorCode, string>> = {
  INTEGRATION_PERMISSION_DENIED:
    'You do not have permission to perform this action.',
  INTEGRATION_INPUT_INVALID: 'The submitted integration settings are invalid.',
  INTEGRATION_REQUEST_FAILED: 'The integration security request failed.',
  INTEGRATION_NETWORK_FAILED:
    'The integration security service could not be reached.',
  INTEGRATION_RESPONSE_INVALID:
    'The integration security service returned an invalid response.',
  INTEGRATION_MUTATION_FAILED:
    'The requested integration security change could not be completed.',
  MACHINE_IDENTITY_NOT_FOUND: 'The machine identity is unavailable.',
  MACHINE_TOKEN_INVALID: 'The machine credential is invalid.',
  MACHINE_TOKEN_EXPIRED: 'The machine credential has expired.',
  MACHINE_SCOPE_DENIED: 'One or more machine scopes cannot be granted.',
  INTEGRATION_REVISION_CONFLICT: 'This record changed. Refresh and try again.',
  WEBHOOK_ENDPOINT_NOT_FOUND: 'The webhook endpoint is unavailable.',
  WEBHOOK_DESTINATION_DENIED: 'The webhook destination is not allowed.',
  WEBHOOK_SECRET_INVALID: 'The webhook credential is invalid.',
  SESSION_DEVICE_NOT_FOUND: 'The session is unavailable.',
};
const runtimeErrorCodes = new Set<RuntimeErrorCode>(
  Object.keys(messages) as RuntimeErrorCode[]
);
const failure = (result: TransportResult): RuntimeError => {
  const body =
    typeof result.body === 'object' &&
    result.body !== null &&
    !Array.isArray(result.body)
      ? (result.body as Record<string, unknown>)
      : {};
  const data =
    typeof body.data === 'object' &&
    body.data !== null &&
    !Array.isArray(body.data)
      ? (body.data as Record<string, unknown>)
      : {};
  const errorCode = body.error_code ?? data.error_code ?? body.code;
  const code: RuntimeErrorCode =
    typeof errorCode === 'string' &&
    runtimeErrorCodes.has(errorCode as RuntimeErrorCode)
      ? (errorCode as RuntimeErrorCode)
      : 'INTEGRATION_REQUEST_FAILED';
  const candidate = body.request_id ?? result.headers.get('X-Request-Id');
  return {
    code,
    message: messages[code],
    requestId:
      typeof candidate === 'string' && requestIdPattern.test(candidate)
        ? candidate
        : null,
    status: result.status,
  };
};
const localFailure = (code: RuntimeErrorCode): RuntimeError => ({
  code,
  message: messages[code],
  requestId: null,
  status: null,
});

type SurfaceKey =
  | 'machines'
  | 'webhooks'
  | 'deliveries'
  | 'attempts'
  | 'sessions';
type RequestKey =
  | SurfaceKey
  | 'machine-mutation'
  | 'webhook-mutation'
  | 'session-mutation';

/**
 * Coordinates permission-gated requests, validates response bodies, and ignores
 * results from superseded tenant or runtime generations.
 */
export const createIntegrationSecurityRuntime = (options: {
  readonly transport: IntegrationSecurityTransport;
  readonly permissions: IntegrationSecurityPermissions;
  readonly onCurrentSessionRevoked?: () => Promise<void>;
}): IntegrationSecurityRuntime => {
  const state = reactive<IntegrationSecurityState>({
    machines: { items: [], loading: false, error: null },
    webhooks: { items: [], loading: false, error: null },
    deliveries: {
      items: [],
      loading: false,
      error: null,
      page: 1,
      pageSize: 20,
      total: 0,
    },
    attempts: { items: [], loading: false, error: null, deliveryKey: null },
    sessions: { items: [], loading: false, error: null },
    mutating: false,
    disclosure: null,
  });
  const controllers = new Map<RequestKey, AbortController>();
  const epochs: Record<SurfaceKey, number> = {
    machines: 0,
    webhooks: 0,
    deliveries: 0,
    attempts: 0,
    sessions: 0,
  };
  let generation = 0;
  const cancel = (key: RequestKey): void => {
    controllers.get(key)?.abort();
    controllers.delete(key);
  };
  const run = async <T>(
    key: RequestKey,
    operation: (signal: AbortSignal) => Promise<T>
  ): Promise<T> => {
    cancel(key);
    const controller = new AbortController();
    controllers.set(key, controller);
    try {
      return await operation(controller.signal);
    } finally {
      if (controllers.get(key) === controller) controllers.delete(key);
    }
  };
  const loadSurface = async <T>(
    key: SurfaceKey,
    surface: SurfaceState<T>,
    allowed: () => boolean,
    request: (signal: AbortSignal) => Promise<TransportResult>,
    parser: (body: unknown) => T[]
  ): Promise<void> => {
    const currentGeneration = generation;
    epochs[key] += 1;
    const currentEpoch = epochs[key];
    cancel(key);
    surface.loading = true;
    surface.error = null;
    if (!allowed()) {
      surface.items = [];
      surface.loading = false;
      surface.error = localFailure('INTEGRATION_PERMISSION_DENIED');
      return;
    }
    let result: TransportResult;
    try {
      result = await run(key, request);
    } catch {
      if (currentGeneration === generation && currentEpoch === epochs[key]) {
        surface.error = localFailure('INTEGRATION_NETWORK_FAILED');
        surface.loading = false;
      }
      return;
    }
    if (currentGeneration !== generation || currentEpoch !== epochs[key])
      return;
    if (result.status !== 200) {
      surface.error = failure(result);
      surface.loading = false;
      return;
    }
    try {
      surface.items = parser(result.body);
    } catch {
      surface.error = localFailure('INTEGRATION_RESPONSE_INVALID');
    } finally {
      if (currentGeneration === generation && currentEpoch === epochs[key])
        surface.loading = false;
    }
  };
  const loadMachines = () =>
    loadSurface(
      'machines',
      state.machines,
      options.permissions.canReadMachines,
      options.transport.machines,
      (body) => parseList(body, parseMachine)
    );
  const loadWebhooks = () =>
    loadSurface(
      'webhooks',
      state.webhooks,
      options.permissions.canReadWebhooks,
      options.transport.webhooks,
      (body) => parseList(body, parseWebhook)
    );
  const loadSessions = () =>
    loadSurface(
      'sessions',
      state.sessions,
      options.permissions.canReadSessions,
      options.transport.sessions,
      (body) => parseList(body, parseSession)
    );
  const loadDeliveries = async (
    page = state.deliveries.page
  ): Promise<void> => {
    const key: SurfaceKey = 'deliveries';
    const currentGeneration = generation;
    epochs[key] += 1;
    const currentEpoch = epochs[key];
    const surface = state.deliveries;
    cancel(key);
    surface.loading = true;
    surface.error = null;
    if (!options.permissions.canReadDeliveries()) {
      surface.items = [];
      surface.total = 0;
      surface.loading = false;
      surface.error = localFailure('INTEGRATION_PERMISSION_DENIED');
      return;
    }
    let result: TransportResult;
    try {
      result = await run(key, (signal) =>
        options.transport.deliveries(page, surface.pageSize, signal)
      );
    } catch {
      if (currentGeneration === generation && currentEpoch === epochs[key]) {
        surface.error = localFailure('INTEGRATION_NETWORK_FAILED');
        surface.loading = false;
      }
      return;
    }
    if (currentGeneration !== generation || currentEpoch !== epochs[key])
      return;
    if (result.status !== 200) {
      surface.error = failure(result);
      surface.loading = false;
      return;
    }
    try {
      const parsed: Page<WebhookDeliveryRecord> = parsePage(
        result.body,
        parseDelivery
      );
      surface.items = parsed.items;
      surface.page = parsed.page;
      surface.pageSize = parsed.pageSize;
      surface.total = parsed.total;
    } catch {
      surface.error = localFailure('INTEGRATION_RESPONSE_INVALID');
    } finally {
      if (currentGeneration === generation && currentEpoch === epochs[key])
        surface.loading = false;
    }
  };
  const loadAttempts = async (deliveryKey: string): Promise<void> => {
    const surface = state.attempts;
    surface.deliveryKey = deliveryKey;
    await loadSurface(
      'attempts',
      surface,
      options.permissions.canReadDeliveries,
      (signal) =>
        options.transport.deliveryAttempts(deliveryKey, 1, 100, signal),
      (body) => parsePage(body, parseAttempt).items
    );
  };
  const mutate = async <T>(
    key: RequestKey,
    allowed: () => boolean,
    request: (signal: AbortSignal) => Promise<TransportResult>,
    parser: (body: unknown) => T,
    after: (value: T) => Promise<void>,
    surface: SurfaceState<unknown>
  ): Promise<void> => {
    if (!allowed()) {
      surface.error = localFailure('INTEGRATION_PERMISSION_DENIED');
      return;
    }
    if (state.mutating) return;
    const current = generation;
    state.mutating = true;
    surface.error = null;
    state.disclosure = null;
    let result: TransportResult;
    try {
      result = await run(key, request);
    } catch {
      if (current === generation) {
        surface.error = localFailure('INTEGRATION_NETWORK_FAILED');
        state.mutating = false;
      }
      return;
    }
    if (current !== generation) return;
    if (result.status < 200 || result.status >= 300) {
      surface.error = failure(result);
      state.mutating = false;
      return;
    }
    let value: T;
    try {
      value = parser(result.body);
    } catch {
      surface.error = localFailure('INTEGRATION_RESPONSE_INVALID');
      state.mutating = false;
      return;
    }
    try {
      await after(value);
    } catch {
      if (current === generation)
        surface.error = localFailure('INTEGRATION_MUTATION_FAILED');
    } finally {
      if (current === generation) state.mutating = false;
    }
  };
  return {
    state,
    can: options.permissions,
    load: async () => {
      await Promise.allSettled([
        loadMachines(),
        loadWebhooks(),
        loadDeliveries(),
        loadSessions(),
      ]);
    },
    loadMachines,
    loadWebhooks,
    loadDeliveries,
    loadAttempts,
    loadSessions,
    createMachine: (input) =>
      mutate(
        'machine-mutation',
        options.permissions.canManageMachines,
        (signal) => options.transport.createMachine(input, signal),
        (body) =>
          parseItem(body, (value) => {
            try {
              return parseProvisionedMachine(value);
            } catch {
              return parseMachine(value);
            }
          }),
        async (value: ProvisionedMachineIdentity | MachineIdentity) => {
          if ('token' in value)
            state.disclosure = { kind: 'machine-token', value: value.token };
          await loadMachines();
        },
        state.machines
      ),
    rotateMachine: (identity) =>
      mutate(
        'machine-mutation',
        options.permissions.canManageMachines,
        (signal) =>
          options.transport.rotateMachine(
            identity.identityKey,
            identity.revision,
            signal
          ),
        (body) =>
          parseItem(body, (value) => {
            try {
              return parseProvisionedMachine(value);
            } catch {
              return parseMachine(value);
            }
          }),
        async (value: ProvisionedMachineIdentity | MachineIdentity) => {
          if ('token' in value)
            state.disclosure = { kind: 'machine-token', value: value.token };
          await loadMachines();
        },
        state.machines
      ),
    revokeMachine: (identity) =>
      mutate(
        'machine-mutation',
        options.permissions.canManageMachines,
        (signal) =>
          options.transport.revokeMachine(
            identity.identityKey,
            identity.revision,
            signal
          ),
        (body) => parseItem(body, parseMachine),
        async () => loadMachines(),
        state.machines
      ),
    createWebhook: (input) =>
      mutate(
        'webhook-mutation',
        options.permissions.canManageWebhooks,
        (signal) => options.transport.createWebhook(input, signal),
        (body) =>
          parseItem(body, (value) => {
            try {
              return parseProvisionedWebhook(value);
            } catch {
              return parseWebhook(value);
            }
          }),
        async (value: ProvisionedWebhookEndpoint | WebhookEndpoint) => {
          if ('signingSecret' in value)
            state.disclosure = {
              kind: 'webhook-secret',
              value: value.signingSecret,
            };
          await loadWebhooks();
        },
        state.webhooks
      ),
    rotateWebhook: (endpoint) =>
      mutate(
        'webhook-mutation',
        options.permissions.canManageWebhooks,
        (signal) =>
          options.transport.rotateWebhook(
            endpoint.endpointKey,
            endpoint.revision,
            signal
          ),
        (body) =>
          parseItem(body, (value) => {
            try {
              return parseProvisionedWebhook(value);
            } catch {
              return parseWebhook(value);
            }
          }),
        async (value: ProvisionedWebhookEndpoint | WebhookEndpoint) => {
          if ('signingSecret' in value)
            state.disclosure = {
              kind: 'webhook-secret',
              value: value.signingSecret,
            };
          await loadWebhooks();
        },
        state.webhooks
      ),
    disableWebhook: (endpoint) =>
      mutate(
        'webhook-mutation',
        options.permissions.canManageWebhooks,
        (signal) =>
          options.transport.disableWebhook(
            endpoint.endpointKey,
            endpoint.revision,
            signal
          ),
        (body) => parseItem(body, parseWebhook),
        async () => loadWebhooks(),
        state.webhooks
      ),
    revokeSession: (session) =>
      mutate(
        'session-mutation',
        options.permissions.canRevokeSession,
        (signal) => options.transport.revokeSession(session.sessionKey, signal),
        (body) => {
          const value = parseItem(body, parseSession);
          if (
            value.sessionKey !== session.sessionKey ||
            value.status !== 'revoked'
          )
            throw new Error('INTEGRATION_RESPONSE_INVALID');
          return value;
        },
        async (value) => {
          if (value.current) {
            state.sessions.items = state.sessions.items.filter(
              (item) => item.sessionKey !== value.sessionKey
            );
            await options.onCurrentSessionRevoked?.();
            return;
          }
          await loadSessions();
        },
        state.sessions
      ),
    clearDisclosure: () => {
      state.disclosure = null;
    },
    dispose() {
      generation += 1;
      (Object.keys(epochs) as SurfaceKey[]).forEach((key) => {
        epochs[key] += 1;
      });
      controllers.forEach((controller) => controller.abort());
      controllers.clear();
      state.machines.items = [];
      state.webhooks.items = [];
      state.deliveries.items = [];
      state.attempts.items = [];
      state.sessions.items = [];
      state.machines.loading = false;
      state.webhooks.loading = false;
      state.deliveries.loading = false;
      state.attempts.loading = false;
      state.sessions.loading = false;
      state.machines.error = null;
      state.webhooks.error = null;
      state.deliveries.error = null;
      state.attempts.error = null;
      state.sessions.error = null;
      state.attempts.deliveryKey = null;
      state.deliveries.total = 0;
      state.deliveries.page = 1;
      state.mutating = false;
      state.disclosure = null;
    },
  };
};
export const integrationSecurityRuntimeKey: InjectionKey<IntegrationSecurityRuntime> =
  Symbol('peanut.integration.runtime');
export const useIntegrationSecurityRuntime = (): IntegrationSecurityRuntime => {
  const runtime = inject(integrationSecurityRuntimeKey);
  if (runtime === undefined)
    throw new Error('INTEGRATION_SECURITY_RUNTIME_MISSING');
  return runtime;
};
