import type { OpsTransportResult } from '../src/contracts';

export const statusData = {
  health: {
    status: 'healthy',
    checks: [
      { key: 'database', status: 'up', critical: true, latency_ms: 1.25 },
    ],
  },
  version: {
    commit: 'a'.repeat(40),
    tree: 'b'.repeat(40),
    release_key: 'starter-v1.stage-c',
    built_at: '2026-07-24T00:00:00.000Z',
  },
  migrations: {
    applied: 12,
    target: 12,
    pending: 0,
    inventory_digest: 'c'.repeat(64),
    drift: false,
  },
  upgrade: {
    state: 'ready',
    code: 'UPGRADE_PREFLIGHT_READY',
    source_commit: 'd'.repeat(40),
    target_commit: 'a'.repeat(40),
    repository_clean: true,
    backup_verified: true,
    source_evidence_matches: true,
  },
};
export const taskData = {
  task_key: `job_${'1'.repeat(32)}`,
  task_type: 'ops.backup.create',
  status: 'queued',
  attempt_count: 0,
  max_attempts: 3,
  revision: 1,
  last_error_code: null,
  available_at: '2026-07-24T01:00:00.000Z',
  created_at: '2026-07-24T01:00:00.000Z',
  updated_at: '2026-07-24T01:00:00.000Z',
  completed_at: null,
};
export const maintenanceData = {
  maintenance_key: `maintenance_${'2'.repeat(32)}`,
  state: 'scheduled',
  reason_key: 'upgrade',
  starts_at: '2026-07-24T03:00:00.000Z',
  ends_at: '2026-07-24T04:00:00.000Z',
  revision: 1,
};
export const envelope = (data: unknown): unknown => ({
  data,
  meta: { request_id: 'req_ops_1' },
});
export const result = (
  status: number,
  body: unknown,
  headers = new Headers()
): OpsTransportResult => ({ status, body, headers });
