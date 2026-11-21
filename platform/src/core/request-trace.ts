// @peanut-admin/client exports: formatRequestError.
// The 5.x hosts use this source until their dependency locks adopt these exports.
/** Only an HTTP response can supply a trace ID. Keep upstream 5xx text private. */
export function formatRequestError(
  message: string,
  response?: { status: number; headers?: Record<string, unknown> }
): string {
  const safeMessage =
    response && response.status >= 500 ? '服务暂时不可用，请稍后重试' : message;
  const traceId = Object.entries(response?.headers ?? {}).find(
    ([name]) => name.toLowerCase() === 'x-trace-id'
  )?.[1];
  if (
    typeof traceId !== 'string' ||
    !/^[A-Za-z0-9][A-Za-z0-9._:-]{0,63}$/.test(traceId) ||
    traceId.trim() !== traceId
  )
    return safeMessage;
  return `${safeMessage}（追踪 ID：${traceId}）`;
}
