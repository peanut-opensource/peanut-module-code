import axios from 'axios';
import { getSessionSnapshot } from '@/utils/auth';
import {
  ResponseEvidence,
  NativeCommandFailure,
  type MutationEndpoint,
  type RequestReceipt,
} from './request-recovery';
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
export interface Lookup {
  id: number;
  title: string;
  version: number;
  capability: string | null;
  provider_model_id?: string | null;
  consumer_module?: string | null;
}
export interface Comparison {
  source_text: string | null;
  candidate_text: string | null;
  source: Record<string, unknown>;
  fixed_result: Record<string, unknown>;
  business_approval: false;
}
const base = '/adminapi/custom.ai-assistant.';
async function current<T>(work: () => Promise<{ data: T }>): Promise<T> {
  const generation = getSessionSnapshot().generation;
  const response = await work();
  if (generation !== getSessionSnapshot().generation)
    throw new Error('会话已切换，请重新加载');
  return response.data;
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

export interface GenerateTextInput {
  route_id: number;
  consumer_module: string;
  topic: string;
  outline?: string;
  request_id: string;
  confirm_send: true;
}

export async function generateText(input: GenerateTextInput, signal: AbortSignal) {
  const result = await current<{ candidate_id: number; request_id: string; text: string }>(() =>
    axios.post(base + 'generateText', input, { signal, timeout: 45000 })
  );
  if (!Number.isSafeInteger(result?.candidate_id) || result.candidate_id < 1 ||
      result.request_id !== input.request_id || typeof result.text !== 'string' || !result.text.trim())
    throw new Error('未取得匹配原请求的正文候选，请核对原请求。');
  return result;
}
export const textRoutes = (consumer: string, page: number, signal: AbortSignal) =>
  current<{ list: Lookup[]; count: number; page_size: number }>(() =>
    axios.get(base + 'textRoutes', { params: { consumer_module: consumer, page }, signal })
  );
