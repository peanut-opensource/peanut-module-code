import type { GovernanceAudience } from './types';

export type GovernanceAuditOutcome = 'success' | 'denied' | 'error';

export interface GovernanceAuditFilter {
  eventType?: string;
  action?: string;
  outcome?: GovernanceAuditOutcome;
  requestId?: string;
  targetType?: string;
  targetId?: string;
}

export interface GovernanceAuditDetailInput {
  id: string;
  audience: GovernanceAudience;
  eventType: string;
  action: string;
  outcome: GovernanceAuditOutcome;
  requestId: string;
  occurredAt: string;
  metadata: Readonly<Record<string, unknown>>;
}

const safeFilter = (value: string): boolean =>
  value !== '' && value.length <= 160 && !/[\u0000-\u001f\u007f]/.test(value);

export const normalizeAuditFilter = (
  input: GovernanceAuditFilter
): GovernanceAuditFilter => {
  for (const value of [
    input.eventType,
    input.action,
    input.requestId,
    input.targetType,
    input.targetId,
  ]) {
    if (value !== undefined && !safeFilter(value))
      throw new Error('AUDIT_FILTER_INVALID');
  }
  if ((input.targetType === undefined) !== (input.targetId === undefined)) {
    throw new Error('AUDIT_TARGET_FILTER_INCOMPLETE');
  }
  return { ...input };
};

export const projectAuditDetail = (
  input: GovernanceAuditDetailInput,
  metadataAllowlist: readonly string[]
) => {
  const metadata: Record<string, boolean | number | string | null> = {};
  if (
    metadataAllowlist.some(
      (key) =>
        !/^[a-z][a-z0-9_]{0,63}$/.test(key) ||
        /token|secret|cookie|password|sql|target_set/i.test(key)
    )
  )
    throw new Error('AUDIT_METADATA_ALLOWLIST_INVALID');
  const allowed = new Set(metadataAllowlist);
  for (const key of [...allowed].sort()) {
    const value = input.metadata[key];
    if (
      value === null ||
      ['boolean', 'number', 'string'].includes(typeof value)
    ) {
      metadata[key] = value as boolean | number | string | null;
    }
  }
  return { ...input, metadata };
};
