import {
  descriptor,
  isBusinessResult,
  receiptMatches,
  lifecycleInputFingerprint,
  NativeCommandFailure,
  type PendingRequest,
  type OriginalRequestStore,
  type RequestLocks,
  type RequestReceipt,
  type MutationEndpoint,
} from './request-recovery';

export interface RecoveryTransport {
  status(
    descriptor: Record<string, unknown>,
    signal: AbortSignal
  ): Promise<unknown>;
  send(
    endpoint: MutationEndpoint,
    payload: Record<string, unknown>,
    signal: AbortSignal
  ): Promise<unknown>;
}
export interface RecoveryOutcome {
  state: 'applied' | 'preparing' | 'unknown' | 'rejected';
  result?: unknown;
  warning?: string;
}
export async function runOriginal(
  store: OriginalRequestStore,
  locks: RequestLocks,
  original: PendingRequest,
  transport: RecoveryTransport,
  options: {
    retry: boolean;
    signal: AbortSignal;
    sessionCurrent: () => boolean;
    secret?: string;
    recoveryFileKey?: string;
  }
): Promise<RecoveryOutcome> {
  return locks.run(store.key + ':request:' + original.id, async () => {
    const current = () => {
      if (!options.sessionCurrent() || options.signal.aborted)
        throw new Error('原请求SESSION已改变或本次读取已取消，原UUID仍保留');
    };
    current();
    let record = await store.assertOriginal(original);
    const expectedInputHash = await lifecycleInputFingerprint(record);
    current();
    const query = async (): Promise<RequestReceipt> => {
      current();
      const value = await transport.status(descriptor(record!), options.signal);
      current();
      if (!receiptMatches(record!, value, expectedInputHash))
        throw new Error('服务器回执不完整或不属于原实际成员/操作；结果仍未知');
      return value;
    };
    const finish = async (
      result: unknown,
      receipt?: RequestReceipt
    ): Promise<RecoveryOutcome> => {
      current();
      // A legacy bucket may have changed while the server receipt was in flight.
      record = await store.assertOriginal(record!);
      current();
      if (record.endpoint === 'candidateLifecycle') {
        const evidence = receipt ?? result;
        if (
          !receiptMatches(record, evidence, expectedInputHash) ||
          evidence.state !== 'applied'
        )
          throw new Error('原采纳操作完整输入指纹尚未核实，原UUID继续保留。');
        result = evidence.result;
      }
      if (
        !isBusinessResult(
          result,
          typeof record!.payload.id === 'number'
            ? record!.payload.id
            : undefined
        )
      )
        throw new Error('成功回执不完整，原请求保留待核');
      try {
        await store.forget(record!);
      } catch {
        return {
          state: 'applied',
          result,
          warning:
            '服务器已确认原请求发生；本地清理失败，仍须按原UUID核对，勿重复提交',
        };
      }
      return { state: 'applied', result };
    };
    let receipt = await query();
    if (receipt.state === 'applied') return finish(receipt.result, receipt);
    if (
      receipt.state === 'unconfirmed' &&
      !receipt.anchor_present &&
      record.rejection &&
      record.attempts === 1 &&
      !record.hadUncertain
    ) {
      await store.phase(record, 'rejected', record.rejection);
      return { state: 'rejected' };
    }
    if (receipt.state === 'preparing') {
      record = await store.phase(record, 'preparing');
    }
    if (!options.retry)
      return { state: receipt.state === 'preparing' ? 'preparing' : 'unknown' };
    if (options.recoveryFileKey) {
      if (
        record.endpoint !== 'collectCandidate' ||
        receipt.state !== 'preparing' ||
        !receipt.anchor_present
      )
        throw new Error('仅原领取已有当前成员锚点时可补充同字节File恢复信息');
      record = await store.recoveryFile(record, options.recoveryFileKey);
    }
    const firstAttempt =
      record.phase === 'prepared' &&
      record.attempts === 0 &&
      !record.hadUncertain;
    const payload = await store.payload(record, options.secret);
    current();
    // This durable transition must succeed before the native transport is called.
    record = await store.phase(record, 'unknown');
    current();
    try {
      const result = await transport.send(
        record.endpoint,
        payload,
        options.signal
      );
      current();
      return await finish(result);
    } catch (error) {
      if (!options.sessionCurrent()) throw error;
      if (error instanceof NativeCommandFailure && error.evidence) {
        record = await store.phase(
          record,
          'unknown',
          error.evidence,
          false,
          firstAttempt
        );
        receipt = await query();
        if (receipt.state === 'applied') return finish(receipt.result, receipt);
        if (
          receipt.state === 'unconfirmed' &&
          !receipt.anchor_present &&
          record.attempts === 1 &&
          !record.hadUncertain
        ) {
          await store.phase(record, 'rejected', error.evidence);
          return { state: 'rejected' };
        }
        if (receipt.state === 'preparing') {
          await store.phase(record, 'preparing', error.evidence);
          return { state: 'preparing' };
        }
        await store.phase(record, 'unknown', error.evidence, true);
        return {
          state: 'unknown',
          warning:
            '早先结果未确认，后续拒绝不能解除原UUID；请继续查询或同号恢复',
        };
      }
      // No native complete rejection: retain this uncertainty across refresh and later known rejections.
      await store.phase(record, 'unknown', undefined, true);
      return {
        state: 'unknown',
        warning:
          error instanceof Error ? error.message : '响应未确认，原请求保留',
      };
    }
  });
}
