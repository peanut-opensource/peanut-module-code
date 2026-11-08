import { createModuleClient } from '../../generated/openapi';

export type TaskStatus =
  | 'queued'
  | 'running'
  | 'succeeded'
  | 'dead'
  | 'cancelled';

export interface TaskJob {
  readonly jobKey: string;
  readonly taskType: string;
  readonly status: TaskStatus;
  readonly attemptCount: number;
  readonly maxAttempts: number;
  readonly revision: number;
  readonly lastErrorCode: string | null;
  readonly availableAt: string;
  readonly createdAt: string;
  readonly updatedAt: string;
  readonly completedAt: string | null;
}

export interface TaskJobList {
  readonly items: readonly TaskJob[];
  readonly page: number;
  readonly pageSize: number;
  readonly total: number;
}

export interface TaskTransportResult {
  readonly body: unknown;
  readonly headers: Headers;
  readonly status: number;
}

export interface TaskJobTransport {
  list: (
    status: TaskStatus,
    page: number,
    pageSize: number,
    signal: AbortSignal
  ) => Promise<TaskTransportResult>;
  cancel: (
    jobKey: string,
    revision: number,
    signal: AbortSignal
  ) => Promise<TaskTransportResult>;
  retry: (
    jobKey: string,
    revision: number,
    signal: AbortSignal
  ) => Promise<TaskTransportResult>;
}

const jobKeyPattern = /^job_[0-9a-f]{32}$/;
const taskTypePattern = /^[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*$/;
const errorCodePattern = /^[A-Z][A-Z0-9_]{2,63}$/;
const statuses: readonly TaskStatus[] = [
  'queued',
  'running',
  'succeeded',
  'dead',
  'cancelled',
];

const record = (value: unknown): Record<string, unknown> => {
  if (typeof value !== 'object' || value === null || Array.isArray(value))
    throw new Error('TASK_RESPONSE_INVALID');
  return value as Record<string, unknown>;
};

const exactKeys = (
  value: Record<string, unknown>,
  keys: readonly string[]
): void => {
  const actual = Object.keys(value).sort();
  const expected = [...keys].sort();
  if (
    actual.length !== expected.length ||
    actual.some((key, index) => key !== expected[index])
  ) {
    throw new Error('TASK_RESPONSE_INVALID');
  }
};

const timestamp = (value: unknown): value is string =>
  typeof value === 'string' &&
  /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/.test(value) &&
  Number.isFinite(Date.parse(value));

export const parseTaskJob = (value: unknown): TaskJob => {
  const item = record(value);
  exactKeys(item, [
    'job_key',
    'task_type',
    'status',
    'attempt_count',
    'max_attempts',
    'revision',
    'last_error_code',
    'available_at',
    'created_at',
    'updated_at',
    'completed_at',
  ]);
  if (
    typeof item.job_key !== 'string' ||
    !jobKeyPattern.test(item.job_key) ||
    typeof item.task_type !== 'string' ||
    !taskTypePattern.test(item.task_type) ||
    typeof item.status !== 'string' ||
    !statuses.includes(item.status as TaskStatus) ||
    typeof item.attempt_count !== 'number' ||
    !Number.isSafeInteger(item.attempt_count) ||
    item.attempt_count < 0 ||
    typeof item.max_attempts !== 'number' ||
    !Number.isSafeInteger(item.max_attempts) ||
    item.max_attempts < 1 ||
    item.max_attempts > 10 ||
    item.attempt_count > item.max_attempts ||
    typeof item.revision !== 'number' ||
    !Number.isSafeInteger(item.revision) ||
    item.revision < 1 ||
    (item.last_error_code !== null &&
      (typeof item.last_error_code !== 'string' ||
        !errorCodePattern.test(item.last_error_code))) ||
    !timestamp(item.available_at) ||
    !timestamp(item.created_at) ||
    !timestamp(item.updated_at) ||
    (item.completed_at !== null && !timestamp(item.completed_at)) ||
    ['succeeded', 'dead', 'cancelled'].includes(item.status) !==
      (item.completed_at !== null)
  )
    throw new Error('TASK_RESPONSE_INVALID');
  return {
    jobKey: item.job_key,
    taskType: item.task_type,
    status: item.status as TaskStatus,
    attemptCount: item.attempt_count,
    maxAttempts: item.max_attempts,
    revision: item.revision,
    lastErrorCode: item.last_error_code as string | null,
    availableAt: item.available_at as string,
    createdAt: item.created_at as string,
    updatedAt: item.updated_at as string,
    completedAt: item.completed_at as string | null,
  };
};

export const parseTaskResponse = (value: unknown): TaskJob => {
  const body = record(value);
  exactKeys(body, ['data', 'meta']);
  record(body.meta);
  return parseTaskJob(body.data);
};

export const parseTaskList = (value: unknown): TaskJobList => {
  const body = record(value);
  exactKeys(body, ['data', 'meta']);
  const data = record(body.data);
  const meta = record(body.meta);
  exactKeys(data, ['items']);
  if (!Array.isArray(data.items)) throw new Error('TASK_RESPONSE_INVALID');
  (['page', 'page_size', 'total'] as const).forEach((key) => {
    const number = meta[key];
    if (
      typeof number !== 'number' ||
      !Number.isSafeInteger(number) ||
      number < (key === 'total' ? 0 : 1)
    ) {
      throw new Error('TASK_RESPONSE_INVALID');
    }
  });
  return {
    items: data.items.map(parseTaskJob),
    page: meta.page as number,
    pageSize: meta.page_size as number,
    total: meta.total as number,
  };
};

export const createTaskJobFetchTransport = (options: {
  readonly baseUrl: string;
  readonly fetch?: (request: Request) => Promise<Response>;
}): TaskJobTransport => {
  const fetcher = options.fetch ?? fetch;
  const sdk = createModuleClient({
    baseUrl: options.baseUrl,
    fetch: fetcher,
  });
  const result = async (
    request: Promise<{ data?: unknown; error?: unknown; response: Response }>
  ): Promise<TaskTransportResult> => {
    const resolved = await request;
    return {
      body: resolved.data ?? resolved.error ?? null,
      headers: resolved.response.headers,
      status: resolved.response.status,
    };
  };
  return {
    list(status, page, pageSize, signal) {
      return result(
        sdk.listTaskJobs({
          credentials: 'include',
          headers: { Accept: 'application/json' },
          params: { query: { status, page, page_size: pageSize } },
          signal,
        })
      );
    },
    cancel(jobKey, revision, signal) {
      return result(
        sdk.cancelTaskJob({
          body: { revision },
          credentials: 'include',
          headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/json',
          },
          params: { path: { jobKey } },
          signal,
        })
      );
    },
    retry(jobKey, revision, signal) {
      return result(
        sdk.retryTaskJob({
          body: { revision },
          credentials: 'include',
          headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/json',
          },
          params: { path: { jobKey } },
          signal,
        })
      );
    },
  };
};
