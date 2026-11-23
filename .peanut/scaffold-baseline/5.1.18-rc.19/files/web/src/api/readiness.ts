import axios from 'axios';

export type ReadinessStatus =
  | 'configured'
  | 'observed'
  | 'action_required'
  | 'unverified'
  | 'not_implemented';

export interface ReadinessEntry {
  kind: 'route' | 'owner';
  route: string | null;
  audience: 'tenant_admin' | 'platform_operator' | 'deployment_owner';
}

export interface ReadinessItem {
  key:
    | 'brand'
    | 'notification'
    | 'storage'
    | 'backup'
    | 'worker'
    | 'domain_tls'
    | 'account_security';
  scope: 'tenant' | 'instance';
  status: ReadinessStatus;
  verification_level: string;
  impact_key: string;
  action_key: string;
  entry: ReadinessEntry;
  production_blocking: boolean;
  facts: Record<string, boolean | number | string>;
}

export interface ReadinessChecklist {
  production_ready: boolean;
  summary: Record<ReadinessStatus | 'production_blockers', number>;
  items: ReadinessItem[];
}

export function getReadinessChecklist() {
  return axios.get<ReadinessChecklist>('/adminapi/readiness/checklist');
}
