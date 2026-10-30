import axios from 'axios';
import {
  readPlan,
  readPlanPage,
  readPlanModules,
  readTenantLicense,
  type Plan,
  type LicenseInput,
} from './tenant-license';
import {
  readPermissionApi,
  readPermissionBundle,
  readPermissionMenuBinding,
  readPermissionPage,
  type PermissionAudience,
} from './permission-bundles';
import type { AxiosRequestConfig, AxiosResponse } from 'axios';
import type {
  MaintenanceScheduleInput,
  OpsConsoleTransport,
  OpsTransportResult,
} from '../modules/peanut-ops/contribution';

interface Envelope<T> {
  code: number;
  msg: string;
  data: T;
}

export interface Page<T> {
  lists: T[];
  count: number;
}

export interface RuntimeModuleStatus {
  module_key: string;
  version: string;
  status: string;
  tenant_enabled_count: number;
  dependencies: unknown[];
  optional_dependencies: unknown[];
  pending_actions: string[];
}

export interface ModuleOperationTask {
  task_key: string;
  status: string;
  current_step: string;
  operation: string;
  package_key: string;
  last_error_code: string | null;
  result: Record<string, unknown> | null;
  recovery_pointer: Record<string, unknown> | null;
}

export interface Session {
  access_token: string;
  expires_in: number;
}

export interface SessionInfo {
  audience: 'platform';
  account_id: string;
  platform_operator_id: string;
  permissions: string[];
  navigation: string[];
}

export interface Tenant {
  id: number;
  code: string;
  display_name: string;
  status: 'provisioning' | 'active' | 'suspended' | 'closed';
  revision: number;
}

export interface Invitation {
  id: number;
  tenant_id: number;
  email: string;
  display_name: string;
  status: 'pending' | 'accepted' | 'revoked' | 'expired';
  delivery_status: 'pending_delivery' | 'sent' | 'failed';
  generation: number;
  expires_at: string;
  accept_token?: string;
}

export interface EntryBinding {
  id: number;
  tenant_id: number;
  tenant_code: string;
  tenant_name: string;
  host: string;
  client_key: 'admin-web' | 'member-api';
  status: 'active' | 'disabled';
}

export interface ModuleState {
  id: number | null;
  tenant_id: number;
  module_key: string;
  status: 'not_enabled' | 'enabled' | 'disabled';
  source: string;
  config_revision: number;
  effective_at: string | null;
  expires_at: string | null;
  disabled_reason: string | null;
  installed_version: string;
  installation_status: 'active';
}

export interface Operator {
  id: number;
  account_id: number;
  display_name: string;
  email: string;
  status: 'active' | 'suspended' | 'closed';
  security_revision: number;
  role_keys: string[];
}

export interface PlatformRole {
  id: number;
  key: string;
  name: string;
  description: string | null;
  is_builtin: boolean | number;
  status: 'active' | 'archived';
  revision: number;
  permission_count: number;
  permission_keys: string[];
}

export interface Permission {
  id: number;
  key: string;
  name: string;
  description: string | null;
  risk_level: string;
}

export interface TenantOwner {
  member_id: number;
  tenant_id: number;
  display_name: string;
  email: string | null;
  member_status: string;
  role_key: string;
}

export interface AuditEvent {
  id: number;
  event_type: string;
  action: string;
  outcome: string;
  operator_id: number | null;
  target_type: string | null;
  target_id: string | null;
  request_id: string;
  occurred_at: string;
}

export interface InvitationInspection {
  tenant_name: string;
  display_name: string;
  email_hint: string;
  status: string;
  expires_at: string;
  requires_password: boolean;
}

export interface StorageAccount {
  id: number;
  account_key: string;
  driver: 'local' | 'qiniu' | 'aliyun' | 'qcloud';
  name: string;
  credentials: Record<string, string>;
  status: string;
}
export interface StorageSpace {
  id: number;
  space_key: string;
  account_id: number;
  account_key: string;
  driver: string;
  name: string;
  access_type: 'public' | 'private';
  bucket?: string;
  region?: string;
  endpoint?: string;
  access_domain?: string;
  local_path?: string;
  status: string;
}
export interface StorageRoute {
  route_key: string;
  access_type: 'public' | 'private';
  space_id: number;
  space_key: string;
  space_name: string;
  driver: string;
}
export interface StorageSnapshot {
  accounts: StorageAccount[];
  spaces: StorageSpace[];
  routes: StorageRoute[];
  purposes: string[];
}
export interface DiagnosticDownload {
  bytes: ArrayBuffer;
  filename: string;
  sha256: string;
}

export interface OpsBackupProvider {
  key: 'peanut.paired-db-files';
}

export interface OpsBackupTask {
  task_key: string;
  task_type: 'ops.backup.create' | 'ops.restore.verify';
  status: 'queued' | 'running' | 'succeeded' | 'dead' | 'cancelled';
  attempt_count: number;
  max_attempts: number;
  revision: number;
  last_error_code: string | null;
  available_at: string;
  created_at: string;
  updated_at: string;
  completed_at: string | null;
}

export interface OpsLatestVerifiedBackup {
  backup_reference_key: string;
  task_key: string;
  provider_key: 'peanut.paired-db-files';
  manifest_sha256: string;
  source_commit: string;
  source_tree: string;
  source_release_key: string | null;
  consistency_started_at: string;
  consistency_completed_at: string;
  verified_at: string;
  age_seconds: number;
  source_matches_runtime: boolean | null;
}

export interface OpsLatestRestoreVerified {
  backup_reference_key: string;
  target_key: 'isolated-new-target';
  verified_at: string;
  verification_sha256: string;
  table_count: number;
  migration_count: number;
  tenant_count: number;
  account_count: number;
  tenant_member_count: number;
  file_count: number;
}

export interface OpsBackupCenterSnapshot {
  provider: OpsBackupProvider;
  latest_verified: OpsLatestVerifiedBackup | null;
  latest_restore_verified: OpsLatestRestoreVerified | null;
  backup_contract_status?: 'missing' | 'current' | 'unsupported_schema';
  restore_contract_status?: 'missing' | 'current' | 'unsupported_schema';
  new_paired_backup_required?: boolean;
  tasks: OpsBackupTask[];
  source_identity_known: boolean;
  page: number;
  page_size: number;
  total: number;
}

export interface ProviderQualificationItem {
  provider_key: string;
  category: 'payment' | 'notification' | 'oauth' | 'storage';
  scope: { type: 'tenant' | 'instance'; key: string };
  configured: boolean;
  connected: boolean;
  callback_verified: boolean;
  credential_rotated_at: string | null;
  observed_at: string | null;
  expires_at: string | null;
  qualified: boolean;
  status_code: string;
  recent_failure: { code: string; observed_at: string } | null;
  evidence_digest: string;
}

export interface ProviderQualificationSnapshot {
  schema_version: 1;
  generated_at: string;
  providers: ProviderQualificationItem[];
  page: number;
  page_size: number;
  total: number;
}

export interface DeveloperCatalogEvidence {
  status: string;
  code: string;
  reason: string;
  revision?: string | null;
}

export interface DeveloperModuleCatalog {
  key: string;
  name: string;
  description: string;
  version: string | null;
  source: {
    manifest: string;
    manifest_sha256: string;
    backend_root: string;
    frontend_entry: string | null;
    frontend_clients: Array<{
      client_key: string;
      entry: string;
      root: string;
    }>;
  };
  evidence: Record<string, DeveloperCatalogEvidence>;
  dependencies: Array<Record<string, unknown>>;
  dependants: Array<Record<string, unknown>>;
  routes: Array<Record<string, unknown>>;
  generated_api: Array<Record<string, unknown>>;
  permissions: Array<Record<string, unknown>>;
  public_services: Array<Record<string, unknown>>;
  provider: Record<string, unknown>;
  middleware: Array<Record<string, unknown>>;
  lifecycle: Record<string, unknown>;
  events: unknown[];
  tasks: { commands: string[]; public_contracts: string[] };
  migrations: {
    declared_path: string | null;
    files: Array<Record<string, unknown>>;
  };
  documentation: { status: string; files: string[] };
  package_preview: Record<string, unknown> & {
    status: string;
    file_count?: number;
    total_bytes?: number;
    inventory_sha256?: string;
    files?: Array<{ path: string; size: number; sha256: string }>;
  };
}

export interface DeveloperCenterCatalogSnapshot {
  schema_version: 1;
  generated_at: string;
  read_only: true;
  sources: Record<string, string>;
  status: Record<string, DeveloperCatalogEvidence>;
  summary: {
    modules: number;
    discovered: number;
    registered: number;
    routes: number;
    generated_api_operations: number;
  };
  modules: DeveloperModuleCatalog[];
}

export type OpsUpgradeReadinessState =
  | 'configuration_required'
  | 'blocked'
  | 'ready';

export interface OpsUpgradeReadinessCheck {
  key: string;
  status: OpsUpgradeReadinessState;
  code: string;
}

export interface OpsUpgradeReadinessSnapshot {
  schema_version: 1;
  state: OpsUpgradeReadinessState;
  code: string;
  preflight: { state: OpsUpgradeReadinessState; code: string };
  checks: OpsUpgradeReadinessCheck[];
  source: {
    runtime: {
      commit: string | null;
      tree: string | null;
      release_key: string | null;
      repository_clean: boolean | null;
    };
    application: null | {
      application_version: string;
      template_version: string;
      template_source_commit: string;
      template_source_tree: string;
      template_inventory_sha256: string;
      application_manifest_sha256: string;
    };
  };
  target: null | {
    release_key: string;
    commit: string;
    tree: string;
    descriptor_sha256: string;
    scaffold: {
      from_version: string;
      to_version: string;
      source_commit: string;
      source_tree: string;
      inventory_sha256: string;
      manifest_sha256: string;
    };
  };
  migrations: {
    current: {
      applied: number;
      target: number;
      pending: number;
      inventory_sha256: string;
      drift: boolean;
    };
    release: null | {
      from_count: number;
      to_count: number;
      pending_count: number | null;
    };
    blockers: string[];
  };
  modules: {
    status: OpsUpgradeReadinessState;
    installed_count: number;
    compatible_count: number;
    target_count: number;
    lock_sha256: string | null;
    target_kernel_version: string | null;
    blockers: string[];
  };
  scaffold: {
    status: OpsUpgradeReadinessState;
    code: string;
    candidate: string | null;
    automatic: number;
    preserved: number;
    conflicts: number;
    app_owned_count: number;
    conflict_reasons: Record<string, number>;
  };
  backup: {
    latest_verified: OpsLatestVerifiedBackup | null;
    latest_restore_verified: OpsLatestRestoreVerified | null;
  };
  maintenance: null | {
    maintenance_key: string;
    state: string;
    reason_key: string;
    starts_at: string;
    ends_at: string;
    revision: number;
  };
  recovery_pointer: null | {
    provider_key: string;
    backup_reference_key: string;
    manifest_sha256: string;
    restore_target_key: string;
    restore_verification_sha256: string;
    restore_verified_at: string;
  };
}

export type OpsUpgradeStepKey =
  | 'preflight'
  | 'backup'
  | 'restore_verification'
  | 'maintenance'
  | 'deployment'
  | 'smoke'
  | 'recovery_pointer';

export interface OpsUpgradeStep {
  step_key: OpsUpgradeStepKey;
  step_order: number;
  status: 'pending' | 'running' | 'succeeded' | 'failed';
  input_sha256: string;
  output_sha256: string | null;
  last_error_code: string | null;
  started_at: string | null;
  completed_at: string | null;
}

export interface OpsUpgradeTask {
  task_key: string;
  task_type: 'ops.upgrade.execute';
  status: 'queued' | 'running' | 'succeeded' | 'dead' | 'cancelled';
  attempt_count: number;
  max_attempts: number;
  revision: number;
  last_error_code: string | null;
  current_step: OpsUpgradeStepKey | 'completed';
  source: {
    commit: string;
    tree: string;
    release_key: string | null;
    application_manifest_sha256: string;
  };
  target: {
    release_key: string;
    commit: string;
    tree: string;
    descriptor_sha256: string;
  };
  backup_reference_key: string | null;
  restore_evidence_sha256: string | null;
  maintenance_key: string | null;
  recovery_pointer: Record<string, string> | null;
  recovery_pointer_sha256: string | null;
  steps: OpsUpgradeStep[];
  created_at: string;
  updated_at: string;
  completed_at: string | null;
}

export interface OpsUpgradeCenterSnapshot {
  tasks: OpsUpgradeTask[];
}

const tokenKey = 'peanut-platform-token';
const sessionKey = 'peanut-platform-session';
export interface PlatformSessionSnapshot {
  generation: string;
  token: string | null;
}
export function getPlatformSessionSnapshot(): PlatformSessionSnapshot {
  const token = localStorage.getItem(tokenKey);
  return { generation: localStorage.getItem(sessionKey) || token || '', token };
}
export function isPlatformSessionCurrent(
  snapshot: PlatformSessionSnapshot
): boolean {
  const current = getPlatformSessionSnapshot();
  return (
    snapshot.generation === current.generation &&
    snapshot.token === current.token
  );
}
function isPlatformIdentityCurrent(snapshot: PlatformSessionSnapshot): boolean {
  return snapshot.generation === getPlatformSessionSnapshot().generation;
}
function advancePlatformSession(): void {
  localStorage.setItem(
    sessionKey,
    Array.from(crypto.getRandomValues(new Uint32Array(4)), (value) =>
      value.toString(16)
    ).join('-')
  );
}
type PlatformSessionListener = (authenticated: boolean) => void;
const platformSessionListeners = new Set<PlatformSessionListener>();

/** Persists the bearer token and notifies UI owners only when authentication presence changes. */
function setPlatformSessionToken(
  token: string | null,
  transition = true
): void {
  const wasAuthenticated = hasPlatformSession();
  if (transition) advancePlatformSession();
  if (token === null) localStorage.removeItem(tokenKey);
  else localStorage.setItem(tokenKey, token);
  const authenticated = hasPlatformSession();
  if (transition || authenticated !== wasAuthenticated) {
    platformSessionListeners.forEach((listener) => listener(authenticated));
  }
}

/** Subscribes to same-tab session transitions emitted by the platform transport. */
export function onPlatformSessionChange(
  listener: PlatformSessionListener
): () => void {
  platformSessionListeners.add(listener);
  return () => platformSessionListeners.delete(listener);
}

if (typeof window !== 'undefined') {
  window.addEventListener('storage', (event) => {
    if (event.key === sessionKey || event.key === null) {
      window.location.reload();
    }
  });
}

const client = axios.create({
  baseURL: import.meta.env.VITE_API_BASE_URL || undefined,
  withCredentials: true,
  timeout: 15_000,
});

type SessionRequestConfig = AxiosRequestConfig & {
  platformRefreshRetried?: boolean;
  platformSession?: PlatformSessionSnapshot;
};
const platformRefreshRequests = new Map<string, Promise<string>>();
let sessionCookieQueue: Promise<void> = Promise.resolve();
function queueSessionCookieRequest<T>(
  operation: () => Promise<T>,
  snapshot: PlatformSessionSnapshot
): Promise<T> {
  const pending = sessionCookieQueue.then(() => {
    if (!isPlatformSessionCurrent(snapshot))
      throw new Error('The platform session has changed.');
    return operation();
  });
  sessionCookieQueue = pending.then(
    () => undefined,
    () => undefined
  );
  return pending;
}

client.interceptors.request.use((config) => {
  const request = config as SessionRequestConfig;
  const snapshot = request.platformSession || getPlatformSessionSnapshot();
  if (!isPlatformIdentityCurrent(snapshot))
    throw new Error('The platform session has changed.');
  const token = localStorage.getItem(tokenKey);
  request.platformSession = { ...snapshot, token };
  if (token) {
    config.headers = config.headers || {};
    config.headers.Authorization = `Bearer ${token}`;
  }
  return config;
});

const handleResponse = async (response: AxiosResponse<Envelope<unknown>>) => {
  const envelope = response.data as Envelope<unknown>;
  const config = response.config as SessionRequestConfig;
  const snapshot = config.platformSession || getPlatformSessionSnapshot();
  if (!isPlatformSessionCurrent(snapshot))
    throw new Error('The platform session has changed.');
  if (
    envelope?.code === 40100 &&
    !config.platformRefreshRetried &&
    config.url !== '/platformapi/session/login' &&
    config.url !== '/platformapi/session/refresh' &&
    config.url !== '/platformapi/session/logout'
  ) {
    config.platformRefreshRetried = true;
    try {
      let refreshedToken = localStorage.getItem(tokenKey);
      if (refreshedToken === snapshot.token) {
        const refreshKey = JSON.stringify([
          snapshot.generation,
          snapshot.token,
        ]);
        let refreshRequest = platformRefreshRequests.get(refreshKey);
        if (!refreshRequest) {
          refreshRequest = queueSessionCookieRequest(
            () =>
              client
                .post<Envelope<Session>>('/platformapi/session/refresh', {}, {
                  platformSession: snapshot,
                } as SessionRequestConfig)
                .then((result) => {
                  if (result.data.code !== 20000)
                    throw new Error(result.data.msg);
                  if (!isPlatformSessionCurrent(snapshot))
                    throw new Error('The platform session has changed.');
                  const currentToken = localStorage.getItem(tokenKey);
                  if (currentToken === snapshot.token) {
                    setPlatformSessionToken(
                      result.data.data.access_token,
                      false
                    );
                    return result.data.data.access_token;
                  }
                  if (currentToken) return currentToken;
                  throw new Error('The platform session has changed.');
                }),
            snapshot
          ).finally(() => {
            platformRefreshRequests.delete(refreshKey);
          });
          platformRefreshRequests.set(refreshKey, refreshRequest);
        }
        refreshedToken = await refreshRequest;
      }
      if (!isPlatformIdentityCurrent(snapshot) || !refreshedToken)
        throw new Error('The platform session has changed.');
      config.platformSession = {
        generation: snapshot.generation,
        token: refreshedToken,
      };
      config.headers = config.headers || {};
      config.headers.Authorization = `Bearer ${refreshedToken}`;
      return client.request(config);
    } catch {
      if (!isPlatformSessionCurrent(snapshot))
        throw new Error('The platform session has changed.');
    }
  }
  if (
    envelope?.code === 40100 &&
    snapshot.token === localStorage.getItem(tokenKey) &&
    config.url !== '/platformapi/session/login' &&
    config.url !== '/platformapi/session/refresh' &&
    config.url !== '/platformapi/session/logout'
  ) {
    setPlatformSessionToken(null);
    config.platformSession = getPlatformSessionSnapshot();
  }
  return response;
};

client.interceptors.response.use(handleResponse, (error) => {
  const response = axios.isAxiosError(error)
    ? (error.response as AxiosResponse<Envelope<unknown>> | undefined)
    : undefined;
  return response?.data && typeof response.data.code === 'number'
    ? handleResponse(response)
    : Promise.reject(error);
});

async function unwrap<T>(
  request: Promise<AxiosResponse<Envelope<T>>>
): Promise<T> {
  const result = await request;
  const snapshot = (result.config as SessionRequestConfig).platformSession;
  if (snapshot && !isPlatformSessionCurrent(snapshot))
    throw new Error('The platform session has changed.');
  if (result.data.code !== 20000) {
    const details = result.data.data as { error_code?: string } | null;
    const errorCode = details?.error_code || '';
    const localizedErrors: Record<string, string> = {
      OWNER_INVITATION_DELIVERY_UNAVAILABLE:
        '尚未配置租户所有者邀请邮件服务，当前不能创建或发送邀请。',
      PLATFORM_AUTHENTICATION_INVALID: '平台登录凭据无效，请重新登录。',
      PLATFORM_SESSION_INVALID: '平台会话已失效，请重新登录。',
      TENANT_CODE_CONFLICT: '租户编码已被使用。',
      TENANT_OWNER_INVITATION_PENDING: '该租户已有待接受的所有者邀请。',
      TENANT_ENTRY_BINDING_CONFLICT: '该域名和客户端已绑定其他租户。',
      SOURCE_IDENTITY_UNKNOWN: '当前源码身份未知，此操作需要可验证发行身份。',
      OPS_STATUS_UNAVAILABLE: '该运维区域暂时无法读取，请稍后重试。',
      OPS_PROVIDER_UNAVAILABLE: '所选运维服务当前不可用。',
    };
    const fallback = result.data.msg || '平台接口拒绝请求';
    const localizedMessages: Record<string, string> = {
      'Operations request was rejected.':
        '该操作当前无法执行，请检查本区域的配置与资格说明。',
      'Platform authentication credential is invalid.':
        '平台登录凭据无效，请重新登录。',
      'Owner invitation delivery is not configured.':
        '尚未配置租户所有者邀请邮件服务，当前不能创建或发送邀请。',
    };
    throw new Error(
      localizedErrors[errorCode] || localizedMessages[fallback] || fallback
    );
  }
  return result.data.data;
}

export function hasPlatformSession(): boolean {
  return !!localStorage.getItem(tokenKey);
}

export const api = {
  planModules: async (page = 1) =>
    readPlanModules(
      await unwrap<unknown>(
        client.get('/platformapi/instance-tools/modules', {
          params: { page, page_size: 100 },
        })
      )
    ),
  plans: async (page = 1) =>
    readPlanPage(
      await unwrap<unknown>(
        client.get('/platformapi/plans', { params: { page, page_size: 100 } })
      )
    ),
  createPlan: async (data: {
    code: string;
    name: string;
    module_keys: string[];
    permission_keys: string[];
    change_reason: string;
  }) =>
    readPlan(await unwrap<unknown>(client.post('/platformapi/plans', data))),
  updatePlan: async (data: {
    id: number;
    expected_revision: number;
    name: string;
    status: Plan['status'];
    module_keys: string[];
    permission_keys: string[];
    change_reason: string;
  }) => readPlan(await unwrap<unknown>(client.put('/platformapi/plans', data))),
  tenantLicense: async (tenantId: number) =>
    readTenantLicense(
      await unwrap<unknown>(
        client.get('/platformapi/tenants/license', {
          params: { tenant_id: tenantId },
        })
      )
    ),
  saveTenantLicense: async (data: LicenseInput) =>
    readTenantLicense(
      await unwrap<unknown>(client.put('/platformapi/tenants/license', data))
    ),
  permissionMenus: async () =>
    readPermissionPage(
      await unwrap<unknown>(client.get('/platformapi/permission/menus')),
      readPermissionMenuBinding
    ),
  replacePermissionMenuBinding: async (data: {
    menu_key: string;
    permission_key: string | null;
    action_key: string | null;
    expected_revision: string;
  }) =>
    readPermissionMenuBinding(
      await unwrap<unknown>(
        client.put('/platformapi/permission/menus/binding', data)
      )
    ),
  createPermissionBundle: async (data: {
    key: string;
    name: string;
    audience: PermissionAudience;
  }) =>
    readPermissionBundle(
      await unwrap<unknown>(
        client.post('/platformapi/permission/bundles', data)
      )
    ),
  updatePermissionBundle: async (data: {
    permission_key: string;
    name: string;
    status: 'active' | 'retired';
    expected_revision: string;
  }) =>
    readPermissionBundle(
      await unwrap<unknown>(client.put('/platformapi/permission/bundles', data))
    ),
  permissionBundles: async (audience: PermissionAudience, page: number) =>
    readPermissionPage(
      await unwrap<unknown>(
        client.get('/platformapi/permission/bundles', {
          params: { audience, page_no: page, page_size: 100 },
        })
      ),
      readPermissionBundle
    ),
  permissionApis: async (audience: PermissionAudience, page: number) =>
    readPermissionPage(
      await unwrap<unknown>(
        client.get('/platformapi/permission/apis', {
          params: { audience, page_no: page, page_size: 100 },
        })
      ),
      readPermissionApi
    ),
  permissionBindings: async (permissionKey: string) =>
    readPermissionBundle(
      await unwrap<unknown>(
        client.get('/platformapi/permission/apis/bindings', {
          params: { permission_key: permissionKey },
        })
      )
    ),
  replacePermissionBindings: async (
    permissionKey: string,
    revision: string,
    apiIds: string[]
  ) =>
    readPermissionBundle(
      await unwrap<unknown>(
        client.put('/platformapi/permission/apis/bindings', {
          permission_key: permissionKey,
          expected_revision: revision,
          api_ids: apiIds,
        })
      )
    ),
  async login(email: string, password: string) {
    advancePlatformSession();
    const snapshot = getPlatformSessionSnapshot();
    const session = await unwrap<Session>(
      queueSessionCookieRequest(
        () =>
          client.post('/platformapi/session/login', { email, password }, {
            platformSession: snapshot,
          } as SessionRequestConfig),
        snapshot
      )
    );
    if (!isPlatformSessionCurrent(snapshot))
      throw new Error('The platform session has changed.');
    setPlatformSessionToken(session.access_token);
    return session;
  },
  async logout() {
    advancePlatformSession();
    const snapshot = getPlatformSessionSnapshot();
    let cleared: PlatformSessionSnapshot | null = null;
    try {
      await unwrap(
        queueSessionCookieRequest(
          () =>
            client.post('/platformapi/session/logout', {}, {
              platformSession: snapshot,
            } as SessionRequestConfig),
          snapshot
        )
      );
    } finally {
      if (
        isPlatformSessionCurrent(snapshot) &&
        snapshot.token === localStorage.getItem(tokenKey)
      ) {
        setPlatformSessionToken(null);
        cleared = getPlatformSessionSnapshot();
      }
    }
    return cleared;
  },
  sessionInfo: () =>
    unwrap<SessionInfo>(client.get('/platformapi/session/info')),
  tenants: (page = 1, pageSize = 100) =>
    unwrap<Page<Tenant>>(
      client.get('/platformapi/tenants', {
        params: { page, page_size: pageSize },
      })
    ),
  tenantDetail: (id: number) =>
    unwrap<Record<string, unknown>>(
      client.get('/platformapi/tenants/detail', { params: { id } })
    ),
  tenantOwner: (tenantId: number) =>
    unwrap<TenantOwner>(
      client.get('/platformapi/tenants/owner', {
        params: { tenant_id: tenantId },
      })
    ),
  provision: (payload: Record<string, string | number>) =>
    unwrap<Invitation>(client.post('/platformapi/tenants/provision', payload)),
  transition: (
    action: 'activate' | 'suspend' | 'close',
    tenant: Tenant,
    changeReason: string
  ) =>
    unwrap(
      client.post(`/platformapi/tenants/${action}`, {
        tenant_id: tenant.id,
        expected_revision: tenant.revision,
        change_reason: changeReason,
      })
    ),
  invitations: (tenantId: number) =>
    unwrap<Page<Invitation>>(
      client.get('/platformapi/tenants/invitations', {
        params: { tenant_id: tenantId, page: 1, page_size: 100 },
      })
    ),
  inviteOwner: (payload: Record<string, string | number>) =>
    unwrap<Invitation>(
      client.post('/platformapi/tenants/invitations', payload)
    ),
  resendInvitation: (invitationId: number) =>
    unwrap<Invitation>(
      client.post('/platformapi/tenants/invitations/resend', {
        invitation_id: invitationId,
        expires_in_hours: 72,
      })
    ),
  revokeInvitation: (invitationId: number) =>
    unwrap(
      client.post('/platformapi/tenants/invitations/revoke', {
        invitation_id: invitationId,
      })
    ),
  inspectInvitation: (token: string) =>
    unwrap<InvitationInspection>(
      client.get('/adminapi/tenant/owner-invitations/inspect', {
        params: { token },
      })
    ),
  acceptInvitation: (token: string, newAccountPassword?: string) =>
    unwrap<Record<string, unknown>>(
      client.post('/adminapi/tenant/owner-invitations/accept', {
        token,
        new_account_password: newAccountPassword || '',
      })
    ),
  entryBindings: (tenantId?: number) =>
    unwrap<EntryBinding[]>(
      client.get('/platformapi/tenant-entry-bindings', {
        params: tenantId ? { tenant_id: tenantId } : {},
      })
    ),
  enableEntryBinding: (payload: Record<string, string | number>) =>
    unwrap<EntryBinding>(
      client.post('/platformapi/tenant-entry-bindings/enable', payload)
    ),
  disableEntryBinding: (bindingId: number, changeReason: string) =>
    unwrap(
      client.post('/platformapi/tenant-entry-bindings/disable', {
        binding_id: bindingId,
        change_reason: changeReason,
      })
    ),
  moduleStates: (tenantId: number) =>
    unwrap<Page<ModuleState>>(
      client.get('/platformapi/tenants/modules', {
        params: { tenant_id: tenantId, page: 1, page_size: 100 },
      })
    ),
  operators: () =>
    unwrap<Page<Operator>>(
      client.get('/platformapi/operators', {
        params: { page: 1, page_size: 100 },
      })
    ),
  createOperator: (payload: Record<string, string>) =>
    unwrap(client.post('/platformapi/operators/create', payload)),
  updateOperator: (
    operator: Operator,
    displayName: string,
    changeReason: string
  ) =>
    unwrap(
      client.post('/platformapi/operators/update', {
        operator_id: operator.id,
        expected_revision: operator.security_revision,
        display_name: displayName,
        change_reason: changeReason,
      })
    ),
  transitionOperator: (
    action: 'activate' | 'suspend' | 'close',
    operator: Operator,
    changeReason: string
  ) =>
    unwrap(
      client.post(`/platformapi/operators/${action}`, {
        operator_id: operator.id,
        expected_revision: operator.security_revision,
        change_reason: changeReason,
      })
    ),
  replaceOperatorRoles: (
    operator: Operator,
    roleIds: number[],
    changeReason: string
  ) =>
    unwrap(
      client.post('/platformapi/operators/roles/replace', {
        operator_id: operator.id,
        role_ids: roleIds,
        expected_revision: operator.security_revision,
        change_reason: changeReason,
      })
    ),
  roles: () =>
    unwrap<Page<PlatformRole>>(
      client.get('/platformapi/roles', {
        params: { page: 1, page_size: 100 },
      })
    ),
  permissions: () =>
    unwrap<Page<Permission>>(
      client.get('/platformapi/permissions', {
        params: { page: 1, page_size: 100 },
      })
    ),
  createRole: (payload: Record<string, string>) =>
    unwrap(client.post('/platformapi/roles/create', payload)),
  updateRole: (
    role: PlatformRole,
    name: string,
    description: string,
    changeReason: string
  ) =>
    unwrap(
      client.post('/platformapi/roles/update', {
        role_id: role.id,
        expected_revision: role.revision,
        name,
        description,
        change_reason: changeReason,
      })
    ),
  archiveRole: (role: PlatformRole, changeReason: string) =>
    unwrap(
      client.post('/platformapi/roles/archive', {
        role_id: role.id,
        expected_revision: role.revision,
        change_reason: changeReason,
      })
    ),
  replaceRolePermissions: (
    role: PlatformRole,
    permissionKeys: string[],
    changeReason: string
  ) =>
    unwrap(
      client.post('/platformapi/roles/permissions/replace', {
        role_id: role.id,
        permission_keys: permissionKeys,
        expected_revision: role.revision,
        change_reason: changeReason,
      })
    ),
  storageSnapshot: () =>
    unwrap<StorageSnapshot>(client.get('/platformapi/infrastructure/storage')),
  createStorageAccount: (payload: Record<string, unknown>) =>
    unwrap(client.post('/platformapi/infrastructure/storage/account', payload)),
  updateStorageAccount: (payload: Record<string, unknown>) =>
    unwrap(
      client.post('/platformapi/infrastructure/storage/account/update', payload)
    ),
  createStorageSpace: (payload: Record<string, unknown>) =>
    unwrap(client.post('/platformapi/infrastructure/storage/space', payload)),
  updateStorageSpace: (payload: Record<string, unknown>) =>
    unwrap(
      client.post('/platformapi/infrastructure/storage/space/update', payload)
    ),
  setStorageRoute: (payload: Record<string, unknown>) =>
    unwrap(client.post('/platformapi/infrastructure/storage/route', payload)),
  audit: (page = 1, pageSize = 20) =>
    unwrap<Page<AuditEvent>>(
      client.get('/platformapi/audit', {
        params: { page, page_size: pageSize },
      })
    ),
  backupCenter: (page = 1) =>
    unwrap<OpsBackupCenterSnapshot>(
      client.get('/platformapi/v1/ops/backups', {
        params: { page, page_size: 20 },
      })
    ),
  upgradeReadiness: () =>
    unwrap<OpsUpgradeReadinessSnapshot>(
      client.get('/platformapi/v1/ops/upgrade-readiness')
    ),
  upgradeCenter: () =>
    unwrap<OpsUpgradeCenterSnapshot>(
      client.get('/platformapi/v1/ops/upgrades')
    ),
  submitUpgrade: () =>
    unwrap<OpsUpgradeTask>(
      client.post(
        '/platformapi/v1/ops/tasks/upgrade',
        {},
        {
          headers: {
            'Idempotency-Key': `platform-upgrade-${Array.from(
              crypto.getRandomValues(new Uint32Array(4)),
              (value) => value.toString(16)
            ).join('-')}`,
          },
        }
      )
    ),
  providerQualifications: (page = 1) =>
    unwrap<ProviderQualificationSnapshot>(
      client.get('/platformapi/v1/ops/providers', {
        params: { page, page_size: 20 },
      })
    ),
  developerCatalog: (moduleKey = '') =>
    unwrap<DeveloperCenterCatalogSnapshot>(
      client.get('/platformapi/developer-center/catalog', {
        params: moduleKey ? { module_key: moduleKey } : {},
      })
    ),
  submitModulePackage: (
    operation: 'install' | 'update',
    form: FormData,
    idempotencyKey: string
  ) =>
    unwrap<ModuleOperationTask>(
      client.post(`/platformapi/instance-tools/modules/${operation}`, form, {
        headers: { 'Idempotency-Key': idempotencyKey },
      })
    ),
  moduleOperationTask: (taskKey: string) =>
    unwrap<ModuleOperationTask>(
      client.get(
        `/platformapi/instance-tools/modules/tasks/${encodeURIComponent(
          taskKey
        )}`
      )
    ),
  async runtimeModules(
    moduleKey = '',
    page = 1
  ): Promise<Page<RuntimeModuleStatus>> {
    const result = await unwrap<unknown>(
      client.get('/platformapi/instance-tools/modules', {
        params: { module_key: moduleKey, page, page_size: 20 },
      })
    );
    if (
      !result ||
      typeof result !== 'object' ||
      !('lists' in result) ||
      !Array.isArray(result.lists) ||
      !('count' in result) ||
      typeof result.count !== 'number'
    ) {
      throw new Error('Invalid Module installation response');
    }
    const lists = result.lists.map((item: unknown): RuntimeModuleStatus => {
      if (
        !item ||
        typeof item !== 'object' ||
        !('module_key' in item) ||
        typeof item.module_key !== 'string' ||
        !('version' in item) ||
        typeof item.version !== 'string' ||
        !('status' in item) ||
        typeof item.status !== 'string' ||
        !('tenant_enabled_count' in item) ||
        typeof item.tenant_enabled_count !== 'number' ||
        !('dependencies' in item) ||
        !Array.isArray(item.dependencies) ||
        !('optional_dependencies' in item) ||
        !Array.isArray(item.optional_dependencies) ||
        !('pending_actions' in item) ||
        !Array.isArray(item.pending_actions) ||
        !item.pending_actions.every(
          (value: unknown): value is string => typeof value === 'string'
        )
      ) {
        throw new Error('Invalid Module installation row');
      }
      return {
        module_key: item.module_key,
        version: item.version,
        status: item.status,
        tenant_enabled_count: item.tenant_enabled_count,
        dependencies: item.dependencies,
        optional_dependencies: item.optional_dependencies,
        pending_actions: item.pending_actions,
      };
    });
    return { lists, count: result.count };
  },
  async downloadDiagnostics(
    windowMinutes: 60 | 360 | 1440 = 60
  ): Promise<DiagnosticDownload> {
    const result = await client.get<ArrayBuffer>(
      '/platformapi/v1/ops/diagnostics',
      {
        params: { window_minutes: windowMinutes },
        responseType: 'arraybuffer',
      }
    );
    const sha256 = String(result.headers['x-diagnostic-sha256'] || '');
    if (!/^[0-9a-f]{64}$/.test(sha256)) {
      let message = '诊断包生成失败';
      try {
        const envelope = JSON.parse(
          new TextDecoder().decode(result.data)
        ) as Envelope<unknown>;
        message = envelope.msg || message;
      } catch {
        // A response without the checksum header is never accepted as an artifact.
      }
      throw new Error(message);
    }
    if (!window.isSecureContext || !window.crypto?.subtle) {
      throw new Error(
        '当前页面不支持安全下载，请通过 HTTPS 平台入口下载诊断包。'
      );
    }
    const actual = Array.from(
      new Uint8Array(await window.crypto.subtle.digest('SHA-256', result.data)),
      (byte) => byte.toString(16).padStart(2, '0')
    ).join('');
    if (actual !== sha256) throw new Error('诊断包完整性校验失败');
    const disposition = String(result.headers['content-disposition'] || '');
    const candidate =
      disposition.match(/filename="([A-Za-z0-9._-]+)"/)?.[1] || '';
    const filename =
      /^peanut-admin-diagnostics-[0-9]{8}-[0-9]{6}-[0-9a-f]{12}\.json$/.test(
        candidate
      )
        ? candidate
        : `peanut-admin-diagnostics-${sha256.slice(0, 12)}.json`;
    return { bytes: result.data, filename, sha256 };
  },
};

function opsStatus(code: number): number {
  if (code === 20000) return 200;
  if (code === 40100) return 401;
  if (code === 40300) return 403;
  if (code >= 40000 && code < 60000) return Math.floor(code / 100);
  return 500;
}

function opsRequestId(headers: Record<string, unknown>): string {
  const value = headers['x-request-id'];
  return typeof value === 'string' && value.length > 0
    ? value
    : `platform-${Array.from(
        crypto.getRandomValues(new Uint32Array(4)),
        (value) => value.toString(16)
      ).join('-')}`;
}

async function opsRead(
  path: string,
  signal: AbortSignal
): Promise<OpsTransportResult> {
  const result = await client.get<Envelope<unknown>>(path, { signal });
  const requestId = opsRequestId(result.headers as Record<string, unknown>);
  if (result.data.code === 20000) {
    return {
      status: 200,
      headers: new Headers({ 'X-Request-Id': requestId }),
      body: { data: result.data.data, meta: { request_id: requestId } },
    };
  }

  const details = result.data.data as { error_code?: string } | null;
  return {
    status: opsStatus(result.data.code),
    headers: new Headers({ 'X-Request-Id': requestId }),
    body: {
      code: details?.error_code || 'OPS_INTERNAL_ERROR',
      request_id: requestId,
    },
  };
}

async function opsSubmitBackup(
  providerKey: string,
  idempotencyKey: string,
  signal: AbortSignal
): Promise<OpsTransportResult> {
  const result = await client.post<Envelope<unknown>>(
    '/platformapi/v1/ops/tasks/backup',
    { provider_key: providerKey },
    { headers: { 'Idempotency-Key': idempotencyKey }, signal }
  );
  const requestId = opsRequestId(result.headers as Record<string, unknown>);
  if (result.data.code === 20000) {
    return {
      status: result.status,
      headers: new Headers({ 'X-Request-Id': requestId }),
      body: { data: result.data.data, meta: { request_id: requestId } },
    };
  }

  const details = result.data.data as { error_code?: string } | null;
  return {
    status: opsStatus(result.data.code),
    headers: new Headers({ 'X-Request-Id': requestId }),
    body: {
      code: details?.error_code || 'OPS_INTERNAL_ERROR',
      request_id: requestId,
    },
  };
}

async function opsSubmitRestore(
  providerKey: string,
  backupReferenceKey: string,
  targetKey: string,
  idempotencyKey: string,
  signal: AbortSignal
): Promise<OpsTransportResult> {
  const result = await client.post<Envelope<unknown>>(
    '/platformapi/v1/ops/tasks/restore',
    {
      provider_key: providerKey,
      backup_reference_key: backupReferenceKey,
      target_key: targetKey,
    },
    { headers: { 'Idempotency-Key': idempotencyKey }, signal }
  );
  const requestId = opsRequestId(result.headers as Record<string, unknown>);
  if (result.data.code === 20000) {
    return {
      status: result.status,
      headers: new Headers({ 'X-Request-Id': requestId }),
      body: { data: result.data.data, meta: { request_id: requestId } },
    };
  }

  const details = result.data.data as { error_code?: string } | null;
  return {
    status: opsStatus(result.data.code),
    headers: new Headers({ 'X-Request-Id': requestId }),
    body: {
      code: details?.error_code || 'OPS_INTERNAL_ERROR',
      request_id: requestId,
    },
  };
}

async function opsScheduleMaintenance(
  input: MaintenanceScheduleInput,
  expectedRevision: number,
  idempotencyKey: string,
  signal: AbortSignal
): Promise<OpsTransportResult> {
  const result = await client.put<Envelope<unknown>>(
    '/platformapi/v1/ops/maintenance',
    {
      reason_key: input.reasonKey,
      starts_at: input.startsAt,
      ends_at: input.endsAt,
    },
    {
      headers: {
        'If-Match': `"rev-${expectedRevision}"`,
        'Idempotency-Key': idempotencyKey,
      },
      signal,
    }
  );
  return opsMutationResult(result);
}

async function opsCloseMaintenance(
  maintenanceKey: string,
  expectedRevision: number,
  idempotencyKey: string,
  signal: AbortSignal
): Promise<OpsTransportResult> {
  const result = await client.post<Envelope<unknown>>(
    `/platformapi/v1/ops/maintenance/${encodeURIComponent(
      maintenanceKey
    )}/close`,
    {},
    {
      headers: {
        'If-Match': `"rev-${expectedRevision}"`,
        'Idempotency-Key': idempotencyKey,
      },
      signal,
    }
  );
  return opsMutationResult(result);
}

function opsMutationResult(result: {
  status: number;
  data: Envelope<unknown>;
  headers: Record<string, unknown>;
}): OpsTransportResult {
  const requestId = opsRequestId(result.headers);
  if (result.data.code === 20000) {
    return {
      status: result.status,
      headers: new Headers({ 'X-Request-Id': requestId }),
      body: { data: result.data.data, meta: { request_id: requestId } },
    };
  }

  const details = result.data.data as { error_code?: string } | null;
  return {
    status: opsStatus(result.data.code),
    headers: new Headers({ 'X-Request-Id': requestId }),
    body: {
      code: details?.error_code || 'OPS_INTERNAL_ERROR',
      request_id: requestId,
    },
  };
}

/** Platform Ops transport: maintenance state and its control writes share one Host. */
export function createPlatformOpsTransport(): OpsConsoleTransport {
  return {
    overview: (signal) => opsRead('/platformapi/v1/ops/status', signal),
    maintenance: (signal) => opsRead('/platformapi/v1/ops/maintenance', signal),
    submitBackup: (providerKey, idempotencyKey, signal) =>
      opsSubmitBackup(providerKey, idempotencyKey, signal),
    submitRestore: (
      providerKey,
      backupReferenceKey,
      targetKey,
      idempotencyKey,
      signal
    ) =>
      opsSubmitRestore(
        providerKey,
        backupReferenceKey,
        targetKey,
        idempotencyKey,
        signal
      ),
    task: (taskKey, signal) =>
      opsRead(
        `/platformapi/v1/ops/tasks/${encodeURIComponent(taskKey)}`,
        signal
      ),
    scheduleMaintenance: opsScheduleMaintenance,
    closeMaintenance: opsCloseMaintenance,
    logs: (sourceKey, severity, cursor, pageSize, signal) => {
      const query = new URLSearchParams({
        source: sourceKey,
        severity,
        page_size: String(pageSize),
      });
      if (cursor !== null) query.set('cursor', cursor);
      return opsRead(`/platformapi/v1/ops/logs?${query}`, signal);
    },
  };
}
