import axios from 'axios';
import { getSessionSnapshot } from '@/utils/auth';
import {
  ResponseEvidence,
  NativeCommandFailure,
  type MutationEndpoint,
  type RequestReceipt,
} from './request-recovery';
import { base, current } from './api-session';
import type { Lookup } from './text-draft-api';

export { generateText, textRoutes } from './text-draft-api';
export type { GenerateTextInput, Lookup } from './text-draft-api';

export interface BusinessRecord {
  id: number;
  title: string;
  status: string;
  version: number;
  created_by: number;
  details: Record<string, unknown>;
  created_at: number;
  updated_at: number;
  history?: BusinessRecord[];
  history_next_before_id?: number | null;
  native_task?: {
    job_key: string;
    status: string;
    revision: number;
    attempt_count: number;
    last_error_code: string | null;
  };
}

/** A preparation has no native revision; its business version is the CAS input. */
export function canCancelTaskPreparation(record: BusinessRecord): boolean {
  const d = record.details;
  return (
    Number.isSafeInteger(record.id) &&
    record.id > 0 &&
    Number.isSafeInteger(record.version) &&
    record.version > 0 &&
    record.status === 'preparing' &&
    !record.native_task &&
    !d.native_job_key &&
    ['preparing', 'prepared'].includes(String(d.stage)) &&
    d.attempt_started_at == null &&
    d.provider_id == null &&
    d.collection_claim == null &&
    d.collect_request_id == null &&
    d.fixed_result == null
  );
}
export function canCancelTask(record: BusinessRecord): boolean {
  if (canCancelTaskPreparation(record)) return true;
  const job = record.native_task;
  const d = record.details;
  if (
    !job ||
    !Number.isSafeInteger(job.revision) ||
    job.revision < 1 ||
    d.collection_claim != null ||
    d.collect_request_id != null ||
    d.fixed_result != null
  )
    return false;
  return (
    ['queued', 'running'].includes(job.status) ||
    (job.status === 'dead' &&
      ['prepared', 'blocked_source_authorization'].includes(String(d.stage)) &&
      d.attempt_started_at == null &&
      d.provider_id == null)
  );
}
export function taskActionPayload(
  record: BusinessRecord,
  action: string,
  requestId: string
): Record<string, unknown> {
  if (
    !Number.isSafeInteger(record.id) ||
    record.id < 1 ||
    !['cancel', 'retry', 'observeUnknown', 'poll'].includes(action)
  )
    throw new Error('任务动作无效，请刷新后重试');
  if (action === 'cancel' && canCancelTaskPreparation(record))
    return {
      id: record.id,
      action,
      version: record.version,
      request_id: requestId,
    };
  const job = record.native_task;
  if (
    !job ||
    !job.job_key ||
    !Number.isSafeInteger(job.revision) ||
    job.revision < 1 ||
    (record.details.native_job_key &&
      record.details.native_job_key !== job.job_key) ||
    (action === 'cancel' && !canCancelTask(record))
  )
    throw new Error('请刷新执行实况，核对真实任务修订后再办理');
  return {
    id: record.id,
    action,
    job_revision: job.revision,
    request_id: requestId,
  };
}

export interface Overview {
  permissions: Record<string, boolean>;
  current_member_id: number;
  current_tenant_id: number;
  capabilities: string[];
  result_types: Record<string, string>;
  protocols: string[];
  execution: { state: string; code: string; reason: string };
}
export interface Page {
  list: BusinessRecord[];
  count: number;
}
export interface HistoryPage {
  list: BusinessRecord[];
  next_before_id: number | null;
}
export interface Comparison {
  source_text: string | null;
  candidate_text: string | null;
  source: Record<string, unknown>;
  fixed_result: Record<string, unknown>;
  business_approval: false;
}
export const overview = (signal: AbortSignal) =>
  current<Overview>(() => axios.get(base + 'overview', { signal }));
export const listing = (
  params: Record<string, string | number>,
  signal: AbortSignal
) => current<Page>(() => axios.get(base + 'list', { params, signal }));
export const detail = (kind: string, id: number, signal: AbortSignal) =>
  current<BusinessRecord>(() =>
    axios.get(base + 'detail', { params: { kind, id }, signal })
  );
export const history = (
  kind: string,
  id: number,
  beforeId: number,
  signal: AbortSignal
) =>
  current<HistoryPage>(() =>
    axios.get(base + 'history', {
      params: { kind, id, before_id: beforeId, limit: 50 },
      signal,
    })
  );
export const lookups = (
  kind: string,
  operation: 'use' | 'configure',
  signal: AbortSignal,
  search = '',
  page = 1
) =>
  current<{ list: Lookup[]; count: number }>(() =>
    axios.get(base + 'lookups', {
      params: { kind, operation, search, page },
      signal,
    })
  );
export const status = (id: number, signal: AbortSignal) =>
  current<BusinessRecord>(() =>
    axios.get(base + 'taskStatus', { params: { id }, signal })
  );
export const requestStatus = (
  descriptor: Record<string, unknown>,
  signal: AbortSignal
) =>
  current<RequestReceipt>(() =>
    axios.get(base + 'requestStatus', { params: descriptor, signal })
  );
export async function command(
  endpoint: MutationEndpoint,
  data: Record<string, unknown>,
  signal: AbortSignal
): Promise<unknown> {
  const generation = getSessionSnapshot().generation,
    evidence = new ResponseEvidence();
  const adapter = axios.defaults.adapter;
  if (typeof adapter !== 'function')
    throw new Error('AI_NATIVE_HTTP_ADAPTER_UNAVAILABLE');
  const defaults = axios.defaults.transformResponse;
  const transforms = Array.isArray(defaults)
    ? defaults
    : defaults
    ? [defaults]
    : [];
  try {
    return await current<unknown>(() =>
      axios.post(base + endpoint, data, {
        signal,
        adapter: (config) => {
          evidence.sent();
          return adapter(config);
        },
        transformResponse: [
          (body: unknown) => {
            evidence.start();
            return body;
          },
          ...transforms,
          (body: unknown, _headers: unknown, status?: number) => {
            evidence.capture(body, status);
            return body;
          },
        ],
      })
    );
  } catch (error) {
    throw new NativeCommandFailure(
      error instanceof Error ? error.message : '请求结果未知',
      generation === getSessionSnapshot().generation
        ? evidence.rejection()
        : null
    );
  }
}
export const candidate = (id: number, signal: AbortSignal) =>
  current<Comparison>(() =>
    axios.get(base + 'candidate', { params: { id }, signal })
  );
export const resolve = (id: number, operation: string, signal: AbortSignal) =>
  current<Record<string, unknown>>(() =>
    axios.post(base + 'resolve', { id, operation }, { signal })
  );
/** Replacement selection consumes only a fully checked current task response. */
export async function acceptedTask(
  id: number,
  signal: AbortSignal
): Promise<BusinessRecord> {
  const value = await current<unknown>(() =>
    axios.get(base + 'detail', { params: { kind: 'task', id }, signal })
  );
  if (!value || typeof value !== 'object' || Array.isArray(value))
    throw new Error('候选响应不完整。');
  const row = value as Record<string, unknown>;
  if (
    row.id !== id ||
    row.status !== 'accepted' ||
    typeof row.title !== 'string' ||
    !row.details ||
    typeof row.details !== 'object' ||
    Array.isArray(row.details) ||
    ['id', 'version', 'created_by'].some(
      (key) =>
        typeof row[key] !== 'number' ||
        !Number.isSafeInteger(row[key]) ||
        Number(row[key]) < 1
    ) ||
    ['created_at', 'updated_at'].some(
      (key) =>
        typeof row[key] !== 'number' ||
        !Number.isSafeInteger(row[key]) ||
        Number(row[key]) < 0
    )
  )
    throw new Error('须选择当前真实已采纳的任务及其固定版本。');
  return {
    id,
    title: row.title,
    status: 'accepted',
    version: row.version as number,
    created_by: row.created_by as number,
    details: row.details as Record<string, unknown>,
    created_at: row.created_at as number,
    updated_at: row.updated_at as number,
  };
}
