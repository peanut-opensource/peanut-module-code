import axios from 'axios';

const PLATFORM_TOKEN_KEY = 'peanut-platform-token';

interface Envelope<T> {
  code: number;
  msg: string;
  data: T;
}

export interface ModuleRuntimeRow {
  module_key: string;
  name: string;
  version: string;
  manifest_digest: string;
  package_key: string;
  package_version: string;
  package_modules: string[];
  status: string;
  dependencies: ModuleDependencyState[];
  optional_dependencies: ModuleDependencyState[];
  pending_actions: string[];
  dependents: string[];
  tenant_enabled_count: number;
  blockers: string[];
  lifecycle_protected: boolean;
}

export interface ModuleDependencyState {
  module_key: string;
  version: string;
  satisfied: boolean;
  unavailable_reason: string | null;
}

export interface ModuleOperationTask {
  task_key: string;
  status: string;
  current_step: string;
  operation: 'install' | 'update' | 'retire' | 'purge';
  package_key: string;
  last_error_code: string | null;
  result: Record<string, unknown> | null;
  recovery_pointer: Record<string, unknown> | null;
}

export interface UninstallPlanEntry {
  scope: string;
  table: string;
  action: string;
  count: number;
  identifiers: string[];
}

export interface UninstallPreview {
  operation: 'preview';
  plan_digest: string;
  confirm_plan: Record<string, unknown>;
  affected_modules: Array<{ module_key: string }>;
  preserved: UninstallPlanEntry[];
  removed: UninstallPlanEntry[];
  blockers: Array<{
    code: string;
    kind?:
      | 'product_policy'
      | 'business_dependency'
      | 'tenant_enablement'
      | 'lifecycle'
      | 'data_integrity';
    identifiers: string[];
  }>;
}

const client = axios.create({
  baseURL: import.meta.env.VITE_API_BASE_URL || undefined,
  withCredentials: true,
  timeout: 15_000,
});

client.interceptors.request.use((config) => {
  const token = localStorage.getItem(PLATFORM_TOKEN_KEY);
  if (token) {
    config.headers = config.headers || {};
    config.headers.Authorization = `Bearer ${token}`;
  }
  return config;
});

async function unwrap<T>(request: Promise<{ data: Envelope<T> }>): Promise<T> {
  let response: { data: Envelope<T> };
  try {
    response = await request;
  } catch (cause) {
    if (axios.isAxiosError(cause)) {
      const envelope = cause.response?.data as Envelope<{
        error_code?: string;
      }> | null;
      if (envelope?.code === 40100) localStorage.removeItem(PLATFORM_TOKEN_KEY);
      throw new Error(
        envelope?.data?.error_code ||
          envelope?.msg ||
          `Platform request failed (${cause.response?.status || 'network'})`
      );
    }
    throw cause;
  }
  if (response.data.code !== 20000) {
    if (response.data.code === 40100)
      localStorage.removeItem(PLATFORM_TOKEN_KEY);
    const details = response.data.data as { error_code?: string } | null;
    throw new Error(
      details?.error_code || response.data.msg || 'Platform request failed'
    );
  }
  return response.data.data;
}

const MODULE_ERROR_MESSAGES: Record<string, string> = {
  MODULE_RUNTIME_MUTATION_DISABLED: '当前环境未开放实例模块治理',
  MODULE_PACKAGE_REQUEST_INVALID: '模块包、SHA-256 或签名参数无效',
  OPS_MODULE_LOCAL_WORKER_UNAVAILABLE: '当前应用尚未配置开发模块任务执行器',
  OPS_MODULE_RESOURCE_NOT_REGISTERED: '模块任务执行器或目标未在当前应用登记',
  OPS_MODULE_RESOURCE_BOUNDARY_INVALID: '模块任务执行器与当前应用登记不匹配',
  OPS_MODULE_RESOURCE_REGISTRY_PATH_INVALID: '当前应用的资源登记文件不可用',
  OPS_MODULE_REQUEST_DIRECTORY_INVALID: '模块任务的已登记收件目录不可用',
  MODULE_REGISTRY_UNAVAILABLE: '模块注册表当前不可用',
  PLUGIN_LOCK_INVALID: '生产模块锁无效，请先修复发布工程输入',
  PLUGIN_ARTIFACT_MISMATCH: '模块包内容与已登记摘要不一致',
  MODULE_UNINSTALL_PLAN_CHANGED: '卸载计划已变化，请重新预览后确认',
  MODULE_UNINSTALL_BLOCKED: '当前状态不允许卸载该模块',
  MODULE_DISABLE_REQUIRED:
    '请先停用目标模块，排空在途请求、任务和回调，完成必要的人工数据处理后再预览卸载',
  WORKFLOW_LIFECYCLE_ACTIVE: '工作流实例或业务副作用尚未结束，暂不能卸载',
  MODULE_DEPENDENT_INSTALLED: '仍有已安装模块依赖该模块',
  PLUGIN_TENANT_MODULE_ACTIVE: '请先关闭所有租户中的模块开通状态',
  MODULE_OWNED_TABLE_EXTERNAL_REFERENCE:
    '模块业务表仍被 Bundle 外部数据引用，不能执行 Purge',
  MODULE_OWNED_TABLE_FK_CYCLE: '模块业务表存在无法安全拆除的外键环',
  MODULE_LIFECYCLE_BUSY: '该模块包正在执行其他治理操作，请稍后重试',
  MODULE_STATE_INVALID: '模块当前状态不允许执行该操作',
  MODULE_CHANGE_REASON_INVALID: '变更原因需为 3 至 500 个字符',
  MODULE_LIFECYCLE_PROTECTED: '该模块属于实例核心能力，不允许停用、退役或清除',
  PLUGIN_INSTALL_RECOVERY_IDENTITY_MISMATCH:
    '安装恢复包身份不一致，必须重试原包或使用更高版本修复包',
  MODULE_MIGRATION_REPAIR_REQUIRED:
    '数据库迁移处于不确定状态，需要追加幂等修复 migration 后前滚',
  MODULE_MIGRATION_REPAIR_INVALID: '修复 migration 的前驱声明无效',
  MODULE_CREATE_KEY_INVALID: 'Module key 格式无效',
  MODULE_CREATE_VENDOR_INVALID: 'Module vendor 格式无效',
  MODULE_CREATE_VENDOR_MISMATCH: 'Module vendor 必须由 Module key 的首段派生',
  MODULE_CREATE_TARGET_EXISTS: '该 Module 的前端或后端目录已经存在',
  MODULE_CREATE_TEMPLATE_INVALID: '统一 Module 脚手架模板不可用',
  MODULE_CREATE_FAILED: 'Module 脚手架生成失败',
};

export function moduleErrorMessage(cause: unknown): string {
  const code = cause instanceof Error ? cause.message : '';
  const message = MODULE_ERROR_MESSAGES[code];
  if (message) return `${message}（${code}）`;
  return code || '模块治理请求失败';
}

export function hasPlatformSession(): boolean {
  return !!localStorage.getItem(PLATFORM_TOKEN_KEY);
}

export async function loginPlatform(
  email: string,
  password: string
): Promise<void> {
  const session = await unwrap<{ access_token: string }>(
    client.post('/platformapi/session/login', { email, password })
  );
  localStorage.setItem(PLATFORM_TOKEN_KEY, session.access_token);
}

export function listModules(params: {
  page: number;
  page_size: number;
  module_key?: string;
}) {
  return unwrap<{
    lists: ModuleRuntimeRow[];
    count: number;
    pageNo: number;
    pageSize: number;
  }>(client.get('/platformapi/instance-tools/modules', { params }));
}

export function installPackage(
  form: FormData,
  idempotencyKey: string,
  operation: 'install' | 'update' = 'install'
) {
  return unwrap<ModuleOperationTask>(
    client.post(`/platformapi/instance-tools/modules/${operation}`, form, {
      headers: { 'Idempotency-Key': idempotencyKey },
    })
  );
}

export function moduleOperationTask(taskKey: string) {
  return unwrap<ModuleOperationTask>(
    client.get(
      `/platformapi/instance-tools/modules/tasks/${encodeURIComponent(taskKey)}`
    )
  );
}

export function createModule(
  moduleKey: string,
  targetClient: 'none' | 'admin-web' = 'none'
) {
  return unwrap<{
    operation: 'created';
    module_key: string;
    backend_path: string;
    frontend_path: string | null;
  }>(
    client.post('/platformapi/instance-tools/modules/create', {
      module_key: moduleKey,
      client: targetClient,
    })
  );
}

export function previewUninstall(moduleKey: string, purge: boolean) {
  return unwrap<UninstallPreview>(
    client.post('/platformapi/instance-tools/modules/uninstall', {
      module_key: moduleKey,
      purge,
      preview: true,
    })
  );
}

export function executeUninstall(
  moduleKey: string,
  purge: boolean,
  preview: UninstallPreview,
  changeReason: string,
  idempotencyKey: string
) {
  return unwrap<ModuleOperationTask>(
    client.post(
      '/platformapi/instance-tools/modules/uninstall',
      {
        module_key: moduleKey,
        purge,
        preview: false,
        confirm_package_key: preview.confirm_plan.package_key,
        confirm_plan_digest: preview.plan_digest,
        confirm_plan: preview.confirm_plan,
        change_reason: changeReason,
      },
      { headers: { 'Idempotency-Key': idempotencyKey } }
    )
  );
}

export function disableModule(moduleKey: string, changeReason: string) {
  return unwrap<Record<string, unknown>>(
    client.post('/platformapi/instance-tools/modules/disable', {
      module_key: moduleKey,
      change_reason: changeReason,
    })
  );
}

export function syncModules(moduleKey?: string) {
  return unwrap<Record<string, unknown>>(
    client.post('/platformapi/instance-tools/modules/sync', {
      module_key: moduleKey || '',
    })
  );
}
