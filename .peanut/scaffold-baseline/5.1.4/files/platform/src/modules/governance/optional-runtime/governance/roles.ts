import type { components } from '../generated/api';
import { requireGovernancePermission } from './catalog';
import type { GovernanceAudience, GovernanceCatalog } from './types';

const fail = (code: string): never => {
  throw new Error(code);
};

const canonicalId = (value: string): string => {
  if (!/^[1-9][0-9]*$/.test(value)) fail('GOVERNANCE_ROLE_INVALID');
  return value;
};

export const requireRevision = (
  ifMatch: string,
  currentRevision: number
): number => {
  if (ifMatch === '') fail('PRECONDITION_REQUIRED');
  const match =
    /^"rev-([1-9][0-9]*)"$/.exec(ifMatch) ?? fail('PRECONDITION_INVALID');
  const revision = Number(match[1]);
  if (!Number.isSafeInteger(revision) || revision !== currentRevision)
    fail('REVISION_MISMATCH');
  return revision;
};

export interface RolePermissionDraftInput {
  audience: GovernanceAudience;
  roleId: string;
  currentRevision: number;
  ifMatch: string;
  permissionKeys: readonly string[];
  availableModules: ReadonlySet<string>;
  catalog: GovernanceCatalog;
}

export const createRolePermissionDraft = (input: RolePermissionDraftInput) => {
  const keys = [...new Set(input.permissionKeys)].sort();
  for (const key of keys) {
    const permission = requireGovernancePermission(
      input.catalog,
      key,
      input.audience
    );
    if (
      !['core', 'platform'].includes(permission.moduleKey) &&
      !input.availableModules.has(permission.moduleKey)
    )
      fail('GOVERNANCE_PERMISSION_MODULE_UNAVAILABLE');
  }
  return {
    kind: 'validated-draft' as const,
    audience: input.audience,
    roleId: canonicalId(input.roleId),
    expectedRevision: requireRevision(input.ifMatch, input.currentRevision),
    payload: {
      permission_keys: keys,
    } satisfies components['schemas']['ReplaceRolePermissionsRequest'],
  };
};

type ReplaceDataPolicyRequest =
  components['schemas']['ReplaceDataPolicyRequest'];
type DataPolicyGroupWrite = components['schemas']['DataPolicyGroupWrite'];
type DataPolicyConditionWrite =
  components['schemas']['DataPolicyConditionWrite'];
type DataPolicyTargetSetWrite =
  components['schemas']['DataPolicyTargetSetWrite'];

interface DataPolicyDraftBase {
  audience: GovernanceAudience;
  roleId: string;
  resourceKey: string;
  operation: string;
  payload: ReplaceDataPolicyRequest;
}

export interface CreateDataPolicyDraftInput extends DataPolicyDraftBase {
  mode: 'create';
}

export interface UpdateDataPolicyDraftInput extends DataPolicyDraftBase {
  mode: 'update';
  currentRevision: number;
  ifMatch: string;
}

export type DataPolicyDraftInput =
  | CreateDataPolicyDraftInput
  | UpdateDataPolicyDraftInput;

const text = (value: string, limit: number, code: string): string => {
  const canonical = value.trim();
  if (
    canonical === '' ||
    canonical.length > limit ||
    /[\u0000-\u001f\u007f]/.test(canonical)
  )
    fail(code);
  return canonical;
};

const targetSetDraft = (
  input: DataPolicyTargetSetWrite
): DataPolicyTargetSetWrite => {
  const targets = input.targets.map((target) => ({
    target_id: text(target.target_id, 128, 'DATA_POLICY_TARGETS_INVALID'),
  }));
  if (
    targets.length === 0 ||
    targets.length > 500 ||
    new Set(targets.map((target) => target.target_id)).size !== targets.length
  )
    fail('DATA_POLICY_TARGETS_INVALID');
  return {
    name: text(input.name, 120, 'DATA_POLICY_TARGET_SET_INVALID'),
    target_resource_key: text(
      input.target_resource_key,
      160,
      'DATA_POLICY_TARGET_SET_INVALID'
    ),
    targets,
  };
};

const conditionDraft = (
  input: DataPolicyConditionWrite
): DataPolicyConditionWrite => ({
  condition_key: text(
    input.condition_key,
    160,
    'DATA_POLICY_CONDITION_INVALID'
  ),
  ...(input.target_set === undefined
    ? {}
    : {
        target_set:
          input.target_set === null ? null : targetSetDraft(input.target_set),
      }),
});

const groupDraft = (input: DataPolicyGroupWrite): DataPolicyGroupWrite => {
  if (input.conditions.length === 0 || input.conditions.length > 20)
    fail('DATA_POLICY_CONDITIONS_INVALID');
  return {
    name: text(input.name, 120, 'DATA_POLICY_GROUP_INVALID'),
    conditions: input.conditions.map(conditionDraft),
  };
};

const optionalDate = (value: string | null | undefined): string | null => {
  if (value === undefined || value === null) return null;
  if (!Number.isFinite(Date.parse(value))) fail('DATA_POLICY_PERIOD_INVALID');
  return value;
};

export const createDataPolicyDraft = (input: DataPolicyDraftInput) => {
  if (input.audience !== 'tenant')
    fail('GOVERNANCE_DATA_POLICY_AUDIENCE_MISMATCH');
  const resourceKey = text(
    input.resourceKey,
    160,
    'GOVERNANCE_OPERATION_INVALID'
  );
  const operation = text(input.operation, 160, 'GOVERNANCE_OPERATION_INVALID');
  const reason =
    input.payload.reason === undefined || input.payload.reason === null
      ? null
      : text(input.payload.reason, 300, 'DATA_POLICY_REASON_INVALID');
  const validFrom = optionalDate(input.payload.valid_from);
  const validUntil = optionalDate(input.payload.valid_until);
  if (
    validFrom !== null &&
    validUntil !== null &&
    Date.parse(validUntil) <= Date.parse(validFrom)
  ) {
    fail('DATA_POLICY_PERIOD_INVALID');
  }
  if (
    input.payload.groups.length > 50 ||
    (input.payload.status === 'active' && input.payload.groups.length === 0)
  )
    fail('DATA_POLICY_GROUPS_INVALID');
  const groups = input.payload.groups.map(groupDraft);
  if (new Set(groups.map((group) => group.name)).size !== groups.length)
    fail('DATA_POLICY_GROUP_INVALID');

  return {
    kind: 'validated-draft' as const,
    mode: input.mode,
    audience: 'tenant' as const,
    roleId: canonicalId(input.roleId),
    expectedRevision:
      input.mode === 'create'
        ? null
        : requireRevision(input.ifMatch, input.currentRevision),
    resourceKey,
    operation,
    payload: {
      status: input.payload.status,
      reason,
      valid_from: validFrom,
      valid_until: validUntil,
      groups,
    } satisfies ReplaceDataPolicyRequest,
  };
};
