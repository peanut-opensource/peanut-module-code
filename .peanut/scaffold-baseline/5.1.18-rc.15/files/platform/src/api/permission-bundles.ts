export type PermissionAudience = 'tenant' | 'platform';

export interface PermissionBundle {
  key: string;
  name: string;
  module_key: string;
  audience: PermissionAudience;
  status: string;
  revision: string;
  api_ids: string[];
  configured: boolean;
  source: 'system' | 'module' | 'custom';
}

export interface PermissionApi {
  id: string;
  method: string;
  path: string;
  audience: PermissionAudience;
  module_key: string;
  access_mode: string;
  status: string;
  default_permission_keys: string[];
  prerequisite_permission_keys: string[];
}

export interface PermissionMenuBinding {
  menu_key: string;
  parent_key: string | null;
  type: 'C' | 'A';
  name: string;
  page_path: string;
  action_key: string;
  permission_key: string;
  default_permission_key: string | null;
  revision: string;
}

function record(value: unknown): Record<string, unknown> {
  if (typeof value !== 'object' || value === null || Array.isArray(value))
    throw new Error('权限目录响应格式异常');
  return value as Record<string, unknown>;
}

function string(value: unknown): string {
  if (typeof value !== 'string') throw new Error('权限目录字段格式异常');
  return value;
}

function strings(value: unknown): string[] {
  if (!Array.isArray(value) || !value.every((item) => typeof item === 'string'))
    throw new Error('权限目录列表格式异常');
  return value;
}

function audience(value: unknown): PermissionAudience {
  if (value !== 'tenant' && value !== 'platform')
    throw new Error('权限目录安全域异常');
  return value;
}

export function readPermissionBundle(value: unknown): PermissionBundle {
  const row = record(value);
  if (typeof row.configured !== 'boolean') throw new Error('权限绑定状态异常');
  if (!['system', 'module', 'custom'].includes(string(row.source)))
    throw new Error('权限包来源异常');
  return {
    key: string(row.key),
    name: string(row.name),
    module_key: string(row.module_key),
    audience: audience(row.audience),
    status: string(row.status),
    revision: string(row.revision),
    api_ids: strings(row.api_ids),
    configured: row.configured,
    source: row.source as PermissionBundle['source'],
  };
}

export function readPermissionApi(value: unknown): PermissionApi {
  const row = record(value);
  return {
    id: string(row.id),
    method: string(row.method),
    path: string(row.path),
    audience: audience(row.audience),
    module_key: string(row.module_key),
    access_mode: string(row.access_mode),
    status: string(row.status),
    default_permission_keys: strings(row.default_permission_keys),
    prerequisite_permission_keys: strings(row.prerequisite_permission_keys),
  };
}

export function readPermissionMenuBinding(
  value: unknown
): PermissionMenuBinding {
  const row = record(value);
  if (row.type !== 'C' && row.type !== 'A') throw new Error('菜单引用类型异常');
  return {
    menu_key: string(row.menu_key),
    parent_key: row.parent_key === null ? null : string(row.parent_key),
    type: row.type,
    name: string(row.name),
    page_path: string(row.page_path),
    action_key: string(row.action_key),
    permission_key: string(row.permission_key),
    default_permission_key:
      row.default_permission_key === null
        ? null
        : string(row.default_permission_key),
    revision: string(row.revision),
  };
}

export function readPermissionPage<T>(
  value: unknown,
  read: (item: unknown) => T
) {
  const page = record(value);
  if (
    !Array.isArray(page.items) ||
    typeof page.total !== 'number' ||
    !Number.isSafeInteger(page.total) ||
    page.total < 0
  )
    throw new Error('权限目录分页格式异常');
  return { items: page.items.map(read), total: page.total };
}
