import axios from 'axios';

export type ConfigurationTransferScope = 'tenant';
export type ConfigurationTransferConflictPolicy =
  | 'abort'
  | 'overwrite'
  | 'skip';

export interface ConfigurationTransferSecret {
  reference: string;
  state: 'configured' | 'unconfigured';
}

export interface ConfigurationTransferEntry {
  adapter: string;
  key: string;
  value: unknown;
  secrets: ConfigurationTransferSecret[];
}

export interface ConfigurationTransferPackage {
  schema_version: 1;
  protocol: 'peanut.configuration-transfer';
  manifest: {
    identity: 'peanut.admin';
    version: string;
  };
  scope: ConfigurationTransferScope;
  created_at: string;
  assets: {
    strategy: 'logical-reference-only';
  };
  entries: ConfigurationTransferEntry[];
  checksum: string;
}

export interface ConfigurationTransferPlanEntry {
  adapter: string;
  key: string;
  action:
    | 'create'
    | 'replace'
    | 'replace-secret'
    | 'unchanged'
    | 'skip'
    | 'conflict';
  exists: boolean;
  current_revision: string | null;
  secrets: ConfigurationTransferSecret[];
}

export interface ConfigurationTransferPlan {
  protocol: 'peanut.configuration-transfer';
  schema_version: 1;
  scope: ConfigurationTransferScope;
  checksum: string;
  dry_run: boolean;
  status: 'ready' | 'blocked' | 'applied';
  can_apply: boolean;
  conflict_policy: ConfigurationTransferConflictPolicy;
  counts: {
    total: number;
    create: number;
    replace: number;
    unchanged: number;
    skip: number;
    conflict: number;
  };
  entries: ConfigurationTransferPlanEntry[];
  conflicts: Array<{
    adapter: string;
    key: string;
    current_revision: string | null;
    action: 'replace' | 'skip' | 'conflict';
  }>;
  missing_secret_references: string[];
  applied?: ConfigurationTransferPlanEntry[];
  skipped?: ConfigurationTransferPlanEntry[];
  applied_count?: number;
  skipped_count?: number;
}

export interface ConfigurationTransferRequest {
  package: ConfigurationTransferPackage | string;
  secret_bindings: Record<string, unknown>;
  conflict_policy: ConfigurationTransferConflictPolicy;
}

export function exportTenantConfiguration() {
  return axios.get<ConfigurationTransferPackage>(
    '/adminapi/peanut.import-export.configuration.export'
  );
}

export function dryRunTenantConfiguration(data: ConfigurationTransferRequest) {
  return axios.post<ConfigurationTransferPlan>(
    '/adminapi/peanut.import-export.configuration.dry-run',
    data
  );
}

export function applyTenantConfiguration(data: ConfigurationTransferRequest) {
  return axios.post<ConfigurationTransferPlan>(
    '/adminapi/peanut.import-export.configuration.apply',
    data
  );
}
