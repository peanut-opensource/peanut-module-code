import type { PermissionApi } from './permission-bundles';
import {
  platformRoleAuthorizationPreview,
  platformRoleAuthorizationCatalogAudit,
  platformShellPreviewDefinitions,
  platformStorageCatalogOwner,
  platformStorageNavigation,
  platformStoragePages,
  platformViewHash,
} from '../core/platform-shell-definitions';

export const roleAuthorizationPreviewPath =
  platformRoleAuthorizationPreview.endpoints[0][1];
export const roleAuthorizationCatalogAuditPath =
  platformRoleAuthorizationCatalogAudit.endpoints[0][1];

export function allowsRoleAuthorizationPreview(
  canApi: (method: string, path: string) => boolean
): boolean {
  return platformRoleAuthorizationPreview.endpoints.every(
    ([method, path]) => canApi(method, path)
  );
}

export function allowsRoleAuthorizationCatalogAudit(
  canApi: (method: string, path: string) => boolean
): boolean {
  return platformRoleAuthorizationCatalogAudit.endpoints.every(
    ([method, path]) => canApi(method, path)
  );
}

interface CandidateNode {
  key: string;
  parent_key: string | null;
  type: 'group' | 'page' | 'action' | 'operation';
  api_ids: string[];
}

interface CandidateTreeNode extends CandidateNode {
  label?: string;
  source?: string;
  disabled: boolean;
  children: CandidateTreeNode[];
}

export interface RoleAuthorizationCandidate {
  operator_id: string;
  nodes: CandidateNode[];
  role_assignments: { role_id: string; node_keys: string[] }[];
}

interface ApiChanges {
  added: string[];
  removed: string[];
}

export interface RoleAuthorizationCatalogCandidate {
  role_ids: string[];
  role_assignments: { role_id: string; expected_revision: string; node_keys: string[] }[];
}

export interface RoleAuthorizationCatalogAudit {
  catalog_total: number;
  scope_covers_catalog: boolean;
  equivalent: boolean;
  roles: {
    role_id: string;
    role_key: string | null;
    status: string | null;
    member_count: number | null;
    expected_revision: number | null;
    observed_revision: number | null;
    relation_equal: boolean;
    capability_equal: boolean;
    relation: ApiChanges;
    capability: ApiChanges;
    owner_dynamic: boolean;
    non_http_permission_keys: string[];
    prerequisite_permission_keys: string[];
  }[];
  unresolved: { code: string; role_id?: string; node_key?: string; api_id?: string; permission_key?: string }[];
  atomic_snapshot?: boolean;
}

export interface RoleAuthorizationPreview extends ApiChanges {
  operator_id: string;
  capability_equal: boolean;
  relation_coverage_complete: boolean;
  owner_dynamic: boolean;
  non_http_permission_keys: string[];
  roles: (ApiChanges & {
    role_id: string;
    relation_equal: boolean;
    owner_dynamic: boolean;
  })[];
  unresolved: {
    code: string;
    role_id?: string;
    node_key?: string;
    api_id?: string;
    permission_key?: string;
  }[];
}

const nodeKey = /^platform\.[a-z0-9][a-z0-9_-]*(?:\.[a-z0-9][a-z0-9_-]*)*$/;
const apiId = /^[a-f0-9]{64}$/;
const positiveId = /^[1-9][0-9]*$/;

interface DirectoryOperator {
  display_name: string;
  role_keys: string[];
}

interface DirectoryRole {
  key: string;
  name: string;
  revision: number | string;
  status?: string;
}

export interface RoleAuthorizationPreviewDirectory {
  operators: (DirectoryOperator & { id: string })[];
  roles: (DirectoryRole & { id: string })[];
  apis: PermissionApi[];
}

interface PreviewPage<T> {
  lists: T[];
  count: number;
}

interface RoleAuthorizationDirectoryReader {
  operators(
    page: number, pageSize: number, signal: AbortSignal
  ): Promise<PreviewPage<DirectoryOperator & { id: number | string }>>;
  roles(
    page: number, pageSize: number, signal: AbortSignal
  ): Promise<PreviewPage<DirectoryRole & { id: number | string }>>;
  permissionApis(
    audience: 'platform', page: number, signal: AbortSignal
  ): Promise<{ items: PermissionApi[]; total: number }>;
}

function directoryId(value: number | string): string {
  if (typeof value === 'string' && positiveId.test(value)) return value;
  if (typeof value === 'number' && Number.isSafeInteger(value) && value > 0)
    return String(value);
  throw new Error('候选预览目录包含无效的操作员或角色标识。');
}

async function readPreviewPages<T>(
  read: (page: number) => Promise<PreviewPage<T>>,
  requireAccess: () => void
): Promise<T[]> {
  requireAccess();
  const first = await read(1);
  const rows = [...first.lists];
  for (let page = 2; page <= Math.ceil(first.count / 100); page += 1) {
    requireAccess();
    const next = await read(page);
    if (next.count !== first.count || !next.lists.length)
      throw new Error('候选预览目录在读取期间变化，请重新读取。');
    rows.push(...next.lists);
  }
  if (rows.length !== first.count)
    throw new Error('候选预览目录未完整读取。');
  return rows;
}

export async function readRoleAuthorizationPreviewDirectory(
  api: RoleAuthorizationDirectoryReader,
  canPreview: () => boolean,
  signal: AbortSignal
): Promise<RoleAuthorizationPreviewDirectory> {
  function requireAccess() {
    signal.throwIfAborted();
    if (!canPreview()) throw new Error('当前无法读取候选预览目录。');
  }
  const [operators, roles, apis] = await Promise.all([
    readPreviewPages((page) => api.operators(page, 100, signal), requireAccess),
    readPreviewPages((page) => api.roles(page, 100, signal), requireAccess),
    readPreviewPages(async (page) => {
      const result = await api.permissionApis('platform', page, signal);
      return { lists: result.items, count: result.total };
    }, requireAccess),
  ]);
  requireAccess();
  const normalized = {
    operators: operators.map((row) => ({ ...row, id: directoryId(row.id) })),
    roles: roles.map((row) => ({ ...row, id: directoryId(row.id), revision: directoryId(row.revision) })),
    apis,
  };
  for (const rows of [normalized.operators, normalized.roles, apis])
    if (new Set(rows.map((row) => row.id)).size !== rows.length)
      throw new Error('候选预览目录包含重复标识，请重新读取。');
  return normalized;
}

function record(value: unknown, error: string): Record<string, unknown> {
  if (!value || typeof value !== 'object' || Array.isArray(value))
    throw new Error(error);
  return value as Record<string, unknown>;
}

export function readCandidateNodes(source: string) {
  let draft: unknown;
  try {
    draft = JSON.parse(source);
  } catch {
    throw new Error('请输入有效的 JSON 节点数组。');
  }
  return validateCandidateNodes(draft);
}

export function buildPlatformShellCandidate(apis: readonly PermissionApi[]) {
  const storageParents = new Set<string>([
    platformStorageNavigation.key,
    ...Object.values(platformStoragePages).map((page) => page.key),
  ]);
  const storageDefinitions = platformShellPreviewDefinitions.filter(
    ({ key, parentKey }) =>
      key === platformStorageNavigation.key ||
      (parentKey !== null && storageParents.has(parentKey))
  );
  const storageKeys = new Set<string>(storageDefinitions.map(({ key }) => key));
  const storagePaths = new Set<string>(
    storageDefinitions.flatMap(({ endpoints }) => endpoints.map(([, path]) => path))
  );
  const hasFileCatalogEvidence = apis.some(
    (item) => item.module_key === platformStorageCatalogOwner || storagePaths.has(item.path)
  );
  const definitions = platformShellPreviewDefinitions.filter(
    ({ key }) => hasFileCatalogEvidence || !storageKeys.has(key)
  );

  function resolveApi(
    [method, path]: readonly [string, string],
    expectedOwner?: string
  ): string {
    const reference = `${method} ${path}`;
    const matches = apis.filter(
      (item) => item.method === method && item.path === path
    );
    const match = matches[0];
    if (!match || matches.length !== 1) {
      const problem = matches.length ? '存在多个目录条目' : 'API 未登记';
      throw new Error(`正式定义的 API ${reference}：${problem}。`);
    }
    if (match.audience !== 'platform')
      throw new Error(`正式定义的 API ${reference}：受众不是 Platform。`);
    if (match.status !== 'active')
      throw new Error(
        `正式定义的 API ${reference}：目录状态为 ${match.status}，需要有效条目。`
      );
    if (match.access_mode !== 'platform_permission')
      throw new Error(
        `正式定义的 API ${reference}：授权模式为 ${match.access_mode}，不能作为角色授权项。`
      );
    if (expectedOwner && match.module_key !== expectedOwner)
      throw new Error(
        `正式定义的 API ${reference}：目录来源为 ${match.module_key}，需要 ${expectedOwner}。`
      );
    return match.id;
  }
  const nodes: CandidateNode[] = definitions.map(
    (definition) => {
      const { key, parentKey, type, endpoints } = definition;
      const alternateReadPage =
        type === 'page' && 'readAlternatives' in definition;
      if (type !== 'group' && !alternateReadPage && endpoints.length < 1)
        throw new Error(`正式定义的节点 ${key} 未声明 API 关联。`);
      const expectedOwner =
        storageKeys.has(key)
          ? platformStorageCatalogOwner
          : undefined;
      return {
        key,
        parent_key: parentKey,
        type,
        api_ids: endpoints.map((endpoint) => resolveApi(endpoint, expectedOwner)),
      };
    }
  );
  const labels = new Map<string, string>(
    definitions.map(({ key, label }) => [key, label])
  );
  const presentation = new Map(
    definitions.map((definition) => {
      let source: string;
      const alternateReadPage =
        definition.type === 'page' && 'readAlternatives' in definition;
      switch (definition.type) {
        case 'group':
          source = 'Platform Shell 侧栏分组';
          break;
        case 'page':
          source = 'Platform Shell 页面 ' + platformViewHash(definition.view);
          if (alternateReadPage)
            source += ' · 入口择一，显式选择下方读取能力';
          if (definition.parentKey === platformStorageNavigation.key)
            source += ' · File（peanut.file）分项读取';
          break;
        case 'action':
          source =
            '所属页面 · ' +
            (labels.get(definition.parentKey) ?? definition.parentKey);
          break;
        case 'operation':
          source =
            'source' in definition
              ? definition.source
              : '所属页面 · ' +
                (labels.get(definition.parentKey) ?? definition.parentKey);
          break;
      }
      return [
        definition.key,
        {
          label: definition.label,
          source,
          disabled: definition.type === 'group' || alternateReadPage,
        },
      ];
    })
  );
  return {
    ...validateCandidateNodes(nodes, presentation),
    notice: hasFileCatalogEvidence
      ? ''
      : '当前 Platform API 目录未见 File 存储来源，存储管理未纳入本次候选。',
  };
}

function validateCandidateNodes(
  draft: unknown,
  presentation?: ReadonlyMap<
    string,
    { label: string; source: string; disabled: boolean }
  >
) {
  if (!Array.isArray(draft) || draft.length > 1000)
    throw new Error('候选须为最多 1,000 项的节点数组。');

  const nodes = new Map<string, CandidateNode>();
  let links = 0;
  for (const [index, item] of draft.entries()) {
    const location = `第 ${index + 1} 个节点`;
    const row = record(item, `${location}须为对象。`);
    const { key, parent_key, type, api_ids } = row;
    if (!Object.hasOwn(row, 'api_ids'))
      throw new Error(`${location}缺少 api_ids；明确空关联请填写 []。`);
    if (
      Object.keys(row).length !== 4 ||
      typeof key !== 'string' ||
      key.length > 160 ||
      !nodeKey.test(key) ||
      (parent_key !== null &&
        (typeof parent_key !== 'string' ||
          parent_key.length > 160 ||
          !nodeKey.test(parent_key))) ||
      (type !== 'group' &&
        type !== 'page' &&
        type !== 'action' &&
        type !== 'operation') ||
      !Array.isArray(api_ids) ||
      !api_ids.every((id): id is string => typeof id === 'string' && apiId.test(id))
    )
      throw new Error(`${location}的 key、parent_key、type 或 api_ids 无效。`);
    links += api_ids.length;
    if (links > 2000) throw new Error('候选的 API 关联总数不能超过 2,000。');
    if (type === 'group' && api_ids.length > 0)
      throw new Error(`分组 ${key} 只能提供空 API 关联。`);
    const node: CandidateNode = {
      key,
      parent_key,
      type,
      api_ids: [...new Set(api_ids)].sort(),
    };
    const prior = nodes.get(key);
    if (prior && JSON.stringify(prior) !== JSON.stringify(node))
      throw new Error(`节点 ${key} 的定义冲突。`);
    nodes.set(key, node);
  }

  const tree = new Map<string, CandidateTreeNode>(
    [...nodes].map(([key, node]) => [
      key,
      {
        ...node,
        disabled: node.type === 'group',
        ...presentation?.get(key),
        children: [],
      },
    ])
  );
  const roots: CandidateTreeNode[] = [];
  for (const node of tree.values()) {
    if (node.parent_key === null) {
      roots.push(node);
    } else {
      const parent = tree.get(node.parent_key);
      if (!parent || parent.type === 'action' || parent.type === 'operation')
        throw new Error(`节点 ${node.key} 的父节点不存在或不能包含子节点。`);
      parent.children.push(node);
    }
  }
  const pending = [...roots];
  let visited = 0;
  while (pending.length) {
    const node = pending.pop();
    if (node) {
      visited += 1;
      pending.push(...node.children);
    }
  }
  if (visited !== tree.size) throw new Error('候选节点的父子关系存在循环。');
  return { nodes: [...nodes.values()], tree: roots };
}

export function buildRoleAuthorizationCandidate(
  operatorId: string,
  nodes: CandidateNode[],
  roleIds: string[],
  selections: ReadonlyMap<string, string[]>
): RoleAuthorizationCandidate {
  const role_assignments = roleIds.flatMap((roleId) => {
    const selected = selections.get(roleId);
    return selected === undefined
      ? []
      : [{ role_id: roleId, node_keys: [...selected] }];
  });
  if (
    !positiveId.test(operatorId) ||
    roleIds.some((id) => !positiveId.test(id))
  )
    throw new Error('操作员或角色标识无效，请刷新后重试。');
  if (
    role_assignments.length > 200 ||
    role_assignments.reduce((count, row) => count + row.node_keys.length, 0) > 2000
  )
    throw new Error('最多可提供 200 个角色、合计 2,000 个节点选择。');
  return { operator_id: operatorId, nodes, role_assignments };
}

/** Build explicit, bounded native catalog-audit batches without introducing another authorization evaluator. */
export function buildRoleCatalogAuditBatches(
  roles: RoleAuthorizationPreviewDirectory['roles'],
  selections: ReadonlyMap<string, string[]>
): RoleAuthorizationCatalogCandidate[] {
  if (!roles.length) throw new Error('角色目录为空，不能审计。');
  const rolesById = new Set(roles.map((role) => role.id));
  if (rolesById.size !== roles.length || [...selections.keys()].some((id) => !rolesById.has(id)))
    throw new Error('角色目录和候选选择不一致，请重新读取。');
  const batches: RoleAuthorizationCatalogCandidate[] = [];
  let batch: RoleAuthorizationCatalogCandidate = { role_ids: [], role_assignments: [] };
  let selectedCount = 0;
  function append() {
    if (batch.role_ids.length) batches.push(batch);
    batch = { role_ids: [], role_assignments: [] };
    selectedCount = 0;
  }
  for (const role of roles) {
    if (!positiveId.test(role.id) || !positiveId.test(String(role.revision)))
      throw new Error('角色目录包含无效的标识或修订。');
    const selection = selections.get(role.id);
    if (selection && (selection.length > 1000 || !selection.every((key) => nodeKey.test(key))))
      throw new Error('角色候选超过原生选择限制或包含无效节点。');
    if (batch.role_ids.length === 200 || selectedCount + (selection?.length ?? 0) > 2000)
      append();
    batch.role_ids.push(role.id);
    if (selection !== undefined) {
      batch.role_assignments.push({ role_id: role.id, expected_revision: String(role.revision), node_keys: [...selection] });
      selectedCount += selection.length;
    }
  }
  append();
  return batches;
}

/** Every batch runs through Identity auditCatalog; the combined read is not a migration snapshot. */
export async function auditCompleteRoleCatalog(
  directory: RoleAuthorizationPreviewDirectory,
  selections: ReadonlyMap<string, string[]>,
  submit: (candidate: RoleAuthorizationCatalogCandidate, signal: AbortSignal) => Promise<RoleAuthorizationCatalogAudit>,
  signal: AbortSignal
): Promise<RoleAuthorizationCatalogAudit> {
  const batches = buildRoleCatalogAuditBatches(directory.roles, selections);
  const reports: RoleAuthorizationCatalogAudit['roles'] = [];
  const unresolved: RoleAuthorizationCatalogAudit['unresolved'] = [];
  const rolesById = new Map(directory.roles.map((role) => [role.id, role]));
  const seen = new Set<string>();
  for (const batch of batches) {
    signal.throwIfAborted();
    const result = await submit(batch, signal);
    signal.throwIfAborted();
    if (result.catalog_total !== directory.roles.length || result.roles.length !== batch.role_ids.length)
      throw new Error('审计期间角色目录发生变化，请重新读取。');
    const requested = new Set(batch.role_ids);
    for (const role of result.roles) {
      const source = rolesById.get(role.role_id);
      if (!source || !requested.has(role.role_id) || seen.has(role.role_id) ||
          source.key !== role.role_key || source.status !== role.status ||
          String(source.revision) !== String(role.observed_revision))
        throw new Error('审计期间角色内容或修订发生变化，请重新读取。');
      seen.add(role.role_id);
      reports.push(role);
    }
    unresolved.push(...result.unresolved);
  }
  if (seen.size !== directory.roles.length)
    throw new Error('角色目录没有完整审计，请重新读取。');
  return {
    catalog_total: directory.roles.length,
    scope_covers_catalog: true,
    equivalent: unresolved.length === 0 && reports.every((role) => role.relation_equal && role.capability_equal),
    roles: reports,
    unresolved,
    atomic_snapshot: batches.length === 1,
  };
}

const responseError = '候选预览响应格式异常。';
function flag(value: unknown): boolean {
  if (typeof value !== 'boolean') throw new Error(responseError);
  return value;
}
function string(value: unknown, pattern?: RegExp): string {
  if (typeof value !== 'string' || !value || (pattern && !pattern.test(value)))
    throw new Error(responseError);
  return value;
}
function list<T>(value: unknown, read: (item: unknown) => T): T[] {
  if (!Array.isArray(value)) throw new Error(responseError);
  return value.map(read);
}
function changes(row: Record<string, unknown>): ApiChanges {
  return {
    added: list(row.added, (id) => string(id, apiId)),
    removed: list(row.removed, (id) => string(id, apiId)),
  };
}

function optionalString(value: unknown): string | null {
  return value === null ? null : string(value);
}
function optionalCount(value: unknown): number | null {
  if (value === null) return null;
  if (typeof value !== 'number' || !Number.isSafeInteger(value) || value < 0)
    throw new Error(responseError);
  return value;
}
function requiredCount(value: unknown): number {
  const count = optionalCount(value);
  if (count === null) throw new Error(responseError);
  return count;
}
function unresolvedItem(value: unknown): RoleAuthorizationCatalogAudit['unresolved'][number] {
  const row = record(value, responseError);
  return {
    code: string(row.code),
    role_id: row.role_id === undefined ? undefined : string(row.role_id),
    node_key: row.node_key === undefined ? undefined : string(row.node_key),
    api_id: row.api_id === undefined ? undefined : string(row.api_id),
    permission_key: row.permission_key === undefined ? undefined : string(row.permission_key),
  };
}

/** Decode Identity's existing read-only catalog response, without interpreting its authorization rules. */
export function readRoleAuthorizationCatalogAudit(value: unknown): RoleAuthorizationCatalogAudit {
  const row = record(value, responseError);
  return {
    catalog_total: requiredCount(row.catalog_total),
    scope_covers_catalog: flag(row.scope_covers_catalog),
    equivalent: flag(row.equivalent),
    roles: list(row.roles, (value) => {
      const role = record(value, responseError);
      return {
        role_id: string(role.role_id, positiveId),
        role_key: optionalString(role.role_key),
        status: optionalString(role.status),
        member_count: optionalCount(role.member_count),
        expected_revision: optionalCount(role.expected_revision),
        observed_revision: optionalCount(role.observed_revision),
        relation_equal: flag(role.relation_equal),
        capability_equal: flag(role.capability_equal),
        relation: changes(record(role.relation, responseError)),
        capability: changes(record(role.capability, responseError)),
        owner_dynamic: flag(role.owner_dynamic),
        non_http_permission_keys: list(role.non_http_permission_keys, (key) => string(key)),
        prerequisite_permission_keys: list(role.prerequisite_permission_keys, (key) => string(key)),
      };
    }),
    unresolved: list(row.unresolved, unresolvedItem),
  };
}

export function readRoleAuthorizationPreview(
  value: unknown,
  expectedOperatorId: string
): RoleAuthorizationPreview {
  const row = record(value, responseError);
  const operator_id = string(row.operator_id, positiveId);
  if (operator_id !== expectedOperatorId) throw new Error(responseError);
  return {
    operator_id,
    capability_equal: flag(row.capability_equal),
    relation_coverage_complete: flag(row.relation_coverage_complete),
    owner_dynamic: flag(row.owner_dynamic),
    ...changes(row),
    non_http_permission_keys: list(row.non_http_permission_keys, (key) =>
      string(key)
    ),
    roles: list(row.roles, (value) => {
      const role = record(value, responseError);
      return {
        role_id: string(role.role_id, positiveId),
        relation_equal: flag(role.relation_equal),
        owner_dynamic: flag(role.owner_dynamic),
        ...changes(role),
      };
    }),
    unresolved: list(row.unresolved, (value) => {
      const item = record(value, responseError);
      if (item.api_id !== undefined && typeof item.api_id !== 'string')
        throw new Error(responseError);
      return {
        code: string(item.code),
        role_id: item.role_id === undefined ? undefined : string(item.role_id),
        node_key: item.node_key === undefined ? undefined : string(item.node_key),
        api_id: item.api_id,
        permission_key:
          item.permission_key === undefined
            ? undefined
            : string(item.permission_key),
      };
    }),
  };
}
