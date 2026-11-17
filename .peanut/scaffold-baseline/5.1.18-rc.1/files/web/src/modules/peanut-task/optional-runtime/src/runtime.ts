import { inject, reactive } from 'vue';
import type { InjectionKey } from 'vue';

import { parseTaskList, parseTaskResponse } from './contracts';
import type {
  TaskJob,
  TaskJobTransport,
  TaskStatus,
  TaskTransportResult,
} from './contracts';

export const TASK_JOB_MODULE_KEY = 'peanut.task' as const;
export const TASK_JOB_ROUTE_NAME = 'peanut.task.jobs' as const;
export const TASK_JOB_ROUTE_PATH = '/system/task-jobs' as const;
export const TASK_JOB_READ_PERMISSION = 'peanut.task.jobs.read' as const;
export const TASK_JOB_MANAGE_PERMISSION = 'peanut.task.jobs.manage' as const;
export const TASK_JOB_STORE_KEY = 'peanut.task.jobs.runtime' as const;

export interface TaskJobError {
  readonly message: string;
  readonly messageKey?: string;
  readonly requestId: string | null;
  readonly status: number | null;
}
export interface TaskJobState {
  items: TaskJob[];
  status: TaskStatus;
  page: number;
  pageSize: number;
  total: number;
  loading: boolean;
  mutating: boolean;
  error: TaskJobError | null;
}
export interface TaskJobRuntime {
  readonly state: TaskJobState;
  /** UI capability hint; server authorization remains required for every mutation. */
  readonly canManage: () => boolean;
  load: () => Promise<void>;
  setStatus: (status: TaskStatus) => Promise<void>;
  setPage: (page: number) => Promise<void>;
  cancel: (job: TaskJob) => Promise<void>;
  retry: (job: TaskJob) => Promise<void>;
  dispose: () => void;
}
export interface TaskJobRuntimeOptions {
  readonly transport: TaskJobTransport;
  readonly canRead: () => boolean;
  readonly canManage: () => boolean;
}

const failure = (result: TaskTransportResult): TaskJobError => {
  const body =
    typeof result.body === 'object' &&
    result.body !== null &&
    !Array.isArray(result.body)
      ? (result.body as Record<string, unknown>)
      : {};
  const id = body.request_id ?? result.headers.get('X-Request-Id');
  const detail =
    typeof body.detail === 'string' && body.detail !== '' ? body.detail : null;
  return {
    message: detail ?? `Task request failed (${result.status}).`,
    ...(detail === null ? { messageKey: 'taskJobs.error.requestFailed' } : {}),
    requestId: typeof id === 'string' && id !== '' ? id : null,
    status: result.status,
  };
};

/** Owns permission-checked task requests and abort controllers for one mounted runtime. */
export const createTaskJobRuntime = (
  options: TaskJobRuntimeOptions
): TaskJobRuntime => {
  const state = reactive<TaskJobState>({
    items: [],
    status: 'queued',
    page: 1,
    pageSize: 20,
    total: 0,
    loading: false,
    mutating: false,
    error: null,
  });
  const controllers = new Set<AbortController>();
  let generation = 0;
  const run = async <T>(
    operation: (signal: AbortSignal) => Promise<T>
  ): Promise<T> => {
    const controller = new AbortController();
    controllers.add(controller);
    try {
      return await operation(controller.signal);
    } finally {
      controllers.delete(controller);
    }
  };
  const load = async (): Promise<void> => {
    generation += 1;
    const current = generation;
    state.loading = true;
    state.error = null;
    try {
      if (!options.canRead()) {
        state.items = [];
        state.total = 0;
        state.error = {
          message: 'You do not have permission to read task jobs.',
          messageKey: 'taskJobs.error.permissionDenied',
          requestId: null,
          status: 403,
        };
        return;
      }
      const result = await run((signal) =>
        options.transport.list(state.status, state.page, state.pageSize, signal)
      );
      if (current !== generation) return;
      if (result.status !== 200) {
        state.error = failure(result);
        return;
      }
      const list = parseTaskList(result.body);
      state.items = [...list.items];
      state.page = list.page;
      state.pageSize = list.pageSize;
      state.total = list.total;
    } catch {
      if (current === generation)
        state.error = {
          message: 'The task service could not be reached.',
          messageKey: 'taskJobs.error.serviceUnavailable',
          requestId: null,
          status: null,
        };
    } finally {
      if (current === generation) state.loading = false;
    }
  };
  const mutate = async (
    job: TaskJob,
    operation: 'cancel' | 'retry'
  ): Promise<void> => {
    if (!options.canManage() || state.mutating) return;
    state.mutating = true;
    state.error = null;
    try {
      const result = await run((signal) =>
        options.transport[operation](job.jobKey, job.revision, signal)
      );
      if (result.status !== 200) {
        state.error = failure(result);
        return;
      }
      parseTaskResponse(result.body);
      await load();
    } catch {
      state.error = {
        message: 'The task action could not be completed.',
        messageKey: 'taskJobs.error.actionFailed',
        requestId: null,
        status: null,
      };
    } finally {
      state.mutating = false;
    }
  };
  return {
    state,
    canManage: options.canManage,
    load,
    async setStatus(status) {
      if (state.mutating) return;
      state.status = status;
      state.page = 1;
      await load();
    },
    async setPage(page) {
      if (state.mutating || !Number.isSafeInteger(page) || page < 1) return;
      state.page = page;
      await load();
    },
    cancel: (job) => mutate(job, 'cancel'),
    retry: (job) => mutate(job, 'retry'),
    dispose() {
      generation += 1;
      controllers.forEach((controller) => controller.abort());
      controllers.clear();
    },
  };
};

export const taskJobRuntimeKey: InjectionKey<TaskJobRuntime> =
  Symbol(TASK_JOB_STORE_KEY);
export const useTaskJobRuntime = (): TaskJobRuntime => {
  const runtime = inject(taskJobRuntimeKey);
  if (runtime === undefined) throw new Error('TASK_JOB_RUNTIME_MISSING');
  return runtime;
};
