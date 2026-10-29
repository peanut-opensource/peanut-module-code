export interface WorkflowEnvelope {
  data: unknown;
  meta: Record<string, unknown>;
}
export interface WorkflowTransport {
  request(
    path: string,
    method: 'GET' | 'POST' | 'PUT',
    body?: unknown,
    key?: string,
    signal?: AbortSignal
  ): Promise<WorkflowEnvelope>;
  dispose(): void;
}
export class WorkflowHttpError extends Error {
  constructor(
    readonly status: number,
    readonly code: string,
    message: string,
    readonly requestId: string | null,
    readonly details: Readonly<Record<string, unknown>> = {}
  ) {
    super(message);
    this.details = Object.freeze({ ...details });
  }
}
function object(value: unknown): Record<string, unknown> {
  if (!value || typeof value !== 'object' || Array.isArray(value))
    throw new Error('Invalid workflow HTTP envelope');
  return value as Record<string, unknown>;
}
/** Native V1 only. No host legacy Axios interceptor participates. */
export function createWorkflowTransport(options: {
  baseUrl: string;
  session: () => { generation: number; token: string | null };
  fetch?: (request: Request) => Promise<Response>;
}): WorkflowTransport {
  const fetcher = options.fetch ?? fetch;
  const capturedGeneration = options.session().generation;
  const controllers = new Set<AbortController>();
  let disposed = false;
  const valid = () => {
    if (disposed || capturedGeneration !== options.session().generation)
      throw new DOMException('Workflow session changed', 'AbortError');
  };
  return {
    dispose() {
      disposed = true;
      controllers.forEach((controller) => controller.abort());
      controllers.clear();
    },
    async request(path, method, body, key, signal) {
      valid();
      const controller = new AbortController();
      controllers.add(controller);
      const abort = () => controller.abort();
      signal?.addEventListener('abort', abort, { once: true });
      if (signal?.aborted) controller.abort();
      try {
        const base = new URL(options.baseUrl);
        const prefix = base.pathname.replace(/\/$/, '');
        const query = path.indexOf('?');
        base.pathname = `${
          prefix.endsWith('/adminapi') ? prefix : `${prefix}/adminapi`
        }/api/v1/workflow${query < 0 ? path : path.slice(0, query)}`;
        base.search = query < 0 ? '' : path.slice(query);
        const url = base;
        const { token } = options.session();
        const response = await fetcher(
          new Request(url, {
            method,
            credentials: 'same-origin',
            signal: controller.signal,
            headers: {
              Accept: 'application/json',
              ...(token ? { Authorization: `Bearer ${token}` } : {}),
              ...(body === undefined
                ? {}
                : { 'Content-Type': 'application/json' }),
              ...(key ? { 'Idempotency-Key': key } : {}),
            },
            ...(body === undefined ? {} : { body: JSON.stringify(body) }),
          })
        );
        valid();
        const envelope = object(await response.json());
        valid();
        if (controller.signal.aborted)
          throw new DOMException('Workflow request aborted', 'AbortError');
        if (!response.ok) {
          const details = object(envelope.data);
          if (
            typeof details.error_code !== 'string' ||
            typeof envelope.msg !== 'string'
          )
            throw new Error(
              `Invalid application problem envelope (${response.status})`
            );
          const code = details.error_code;
          const message = envelope.msg;
          throw new WorkflowHttpError(
            response.status,
            code,
            message,
            response.headers.get('X-Request-Id'),
            details
          );
        }
        if ('code' in envelope || !('data' in envelope))
          throw new Error('Invalid native V1 success envelope');
        return { data: envelope.data, meta: object(envelope.meta) };
      } finally {
        signal?.removeEventListener('abort', abort);
        controllers.delete(controller);
      }
    },
  };
}
