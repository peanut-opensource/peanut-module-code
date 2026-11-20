export interface Plan {
  id: number;
  code: string;
  name: string;
  status: 'active' | 'inactive';
  revision: number;
  module_keys: string[];
  permission_keys: string[];
}

export interface TenantLicense {
  id: number;
  tenant_id: number;
  plan_id: number;
  plan_code: string;
  plan_name: string;
  status: 'active' | 'suspended';
  effective_at: string;
  expires_at: string | null;
  revision: number;
  effective: boolean;
  state: 'active' | 'suspended' | 'upcoming' | 'expired';
}

export interface LicenseInput {
  tenant_id: number;
  plan_id: number;
  status: 'active' | 'suspended';
  effective_at: string;
  expires_at: string | null;
  expected_revision: number;
  change_reason: string;
}

export interface PlanModule {
  module_key: string;
  status: string;
  tenant: { enableable: boolean; required: boolean; requires: string[] };
}

export function readPlanModules(value: unknown) {
  const page = record(value);
  if (!Array.isArray(page.lists)) throw new Error('套餐模块目录格式异常');
  const lists = page.lists.map((value): PlanModule => {
    const row = record(value);
    const tenant = record(row.tenant);
    if (
      typeof tenant.enableable !== 'boolean' ||
      typeof tenant.required !== 'boolean'
    )
      throw new Error('套餐模块租户属性异常');
    return {
      module_key: text(row.module_key),
      status: text(row.status),
      tenant: {
        enableable: tenant.enableable,
        required: tenant.required,
        requires: strings(tenant.requires),
      },
    };
  });
  return { lists, count: number(page.count) };
}

function record(value: unknown): Record<string, unknown> {
  if (!value || typeof value !== 'object' || Array.isArray(value))
    throw new Error('套餐或许可证响应格式异常');
  return value as Record<string, unknown>;
}
function text(value: unknown): string {
  if (typeof value !== 'string') throw new Error('套餐或许可证字段格式异常');
  return value;
}
function number(value: unknown): number {
  if (typeof value !== 'number' || !Number.isSafeInteger(value))
    throw new Error('套餐或许可证编号格式异常');
  return value;
}
function strings(value: unknown): string[] {
  if (!Array.isArray(value) || !value.every((item) => typeof item === 'string'))
    throw new Error('套餐权益列表格式异常');
  return value;
}
export function readPlan(value: unknown): Plan {
  const row = record(value);
  if (row.status !== 'active' && row.status !== 'inactive')
    throw new Error('套餐状态异常');
  return {
    id: number(row.id),
    code: text(row.code),
    name: text(row.name),
    status: row.status,
    revision: number(row.revision),
    module_keys: strings(row.module_keys),
    permission_keys: strings(row.permission_keys),
  };
}
export function readPlanPage(value: unknown) {
  const page = record(value);
  if (!Array.isArray(page.lists)) throw new Error('套餐分页格式异常');
  return { lists: page.lists.map(readPlan), count: number(page.count) };
}
export function readTenantLicense(value: unknown) {
  const data = record(value);
  const tenantId = number(data.tenant_id);
  if (data.license === null) return { tenant_id: tenantId, license: null };
  const row = record(data.license);
  if (row.status !== 'active' && row.status !== 'suspended')
    throw new Error('许可证状态异常');
  if (
    row.state !== 'active' &&
    row.state !== 'suspended' &&
    row.state !== 'upcoming' &&
    row.state !== 'expired'
  )
    throw new Error('许可证有效状态异常');
  if (typeof row.effective !== 'boolean') throw new Error('许可证生效字段异常');
  const license: TenantLicense = {
    id: number(row.id),
    tenant_id: number(row.tenant_id),
    plan_id: number(row.plan_id),
    plan_code: text(row.plan_code),
    plan_name: text(row.plan_name),
    status: row.status,
    effective_at: text(row.effective_at),
    expires_at: row.expires_at === null ? null : text(row.expires_at),
    revision: number(row.revision),
    effective: row.effective,
    state: row.state,
  };
  return { tenant_id: tenantId, license };
}
