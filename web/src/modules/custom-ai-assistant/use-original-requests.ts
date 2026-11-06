import { ref } from 'vue';
import { getSessionSnapshot, SESSION_KEY } from '@/utils/auth';
import * as api from './api';
import type { Overview } from './api';
import {
  OriginalRequestStore,
  conflictKey,
  type MutationEndpoint,
  type PendingRequest,
  type RequestLocks,
} from './request-recovery';
import { runOriginal, type RecoveryOutcome } from './request-runner';

const locks: RequestLocks = {
  async run<T>(key: string, work: () => Promise<T>): Promise<T> {
    if (typeof navigator === 'undefined' || !navigator.locks)
      throw new Error(
        '当前浏览器缺少原生请求恢复锁，尚未发送；请使用支持Web Locks的Admin浏览器'
      );
    return navigator.locks.request('custom-ai-assistant:' + key, work);
  },
};
function sessionStamp() {
  getSessionSnapshot();
  const session = localStorage.getItem(SESSION_KEY);
  if (!session) throw new Error('原生SESSION身份未就绪，尚未发送');
  return { session, generation: getSessionSnapshot().generation };
}
export function useOriginalRequests() {
  const requests = ref<PendingRequest[]>([]),
    recoveryError = ref(''),
    running = ref<Record<string, boolean>>({}),
    stopping = ref<Record<string, boolean>>({});
  let store: OriginalRequestStore | null = null,
    stamp: ReturnType<typeof sessionStamp> | null = null,
    epoch = 0;
  const sequences = new Map<string, number>();
  const controllers = new Map<string, AbortController>();
  const stoppedMessage =
    '已停止本次等待，不代表服务器未执行或任务已取消。原UUID和输入继续保留；请仅核对回执后再决定是否沿原请求恢复。';
  const live = (s: ReturnType<typeof sessionStamp>) => {
    try {
      const now = sessionStamp();
      return now.session === s.session && now.generation === s.generation;
    } catch {
      return false;
    }
  };
  function clearView() {
    epoch++;
    for (const controller of controllers.values()) controller.abort();
    controllers.clear();
    store = null;
    stamp = null;
    requests.value = [];
    recoveryError.value = '';
    running.value = {};
    stopping.value = {};
  }
  async function reload(target = store, expected = epoch) {
    if (!target) return;
    try {
      const rows = await target.list();
      if (expected === epoch && target === store && stamp && live(stamp))
        requests.value = rows;
    } catch (e) {
      if (expected === epoch && target === store && stamp && live(stamp))
        recoveryError.value =
          e instanceof Error ? e.message : '原请求存储不可读，未发送';
      throw e;
    }
  }
  async function bind(overview: Overview, expectedGeneration: number) {
    const captured = sessionStamp();
    if (captured.generation !== expectedGeneration)
      throw new Error('身份已切换，旧成员概览不能绑定当前请求空间');
    clearView();
    const expected = epoch;
    const next = new OriginalRequestStore(
      localStorage,
      {
        session: captured.session,
        tenantId: overview.current_tenant_id,
        memberId: overview.current_member_id,
      },
      locks
    );
    if (!live(captured)) throw new Error('SESSION已经切换');
    store = next;
    stamp = captured;
    requests.value = [];
    recoveryError.value = '';
    await reload(next, expected);
  }
  function current() {
    if (!store || !stamp || !live(stamp))
      throw new Error('请先重新加载当前实际成员，再恢复原请求');
    return { store, stamp, epoch };
  }
  async function prepare(
    endpoint: MutationEndpoint,
    kind: string,
    payload: Record<string, unknown>
  ) {
    const c = current();
    const record = await c.store.prepare(endpoint, kind, payload);
    if (!live(c.stamp))
      throw new Error('SESSION已改变；原请求留在原隔离空间，未发送');
    await reload(c.store, c.epoch);
    return record;
  }
  async function run(
    record: PendingRequest,
    retry: boolean,
    secret?: string,
    recoveryFileKey?: string
  ): Promise<RecoveryOutcome> {
    const c = current();
    if (
      record.scope.tenantId !== c.store.scope.tenantId ||
      record.scope.memberId !== c.store.scope.memberId
    )
      throw new Error('原请求不属于当前租户与实际成员');
    if (running.value[record.id])
      throw new Error('正在核对同一原请求，请勿重复点击');
    const sequence = (sequences.get(record.id) || 0) + 1;
    const controller = new AbortController();
    sequences.set(record.id, sequence);
    controllers.set(record.id, controller);
    running.value = { ...running.value, [record.id]: true };
    recoveryError.value = '';
    try {
      let outcome = await runOriginal(
        c.store,
        locks,
        record,
        { status: api.requestStatus, send: api.command },
        {
          retry,
          secret,
          recoveryFileKey,
          signal: controller.signal,
          sessionCurrent: () =>
            c.epoch === epoch && c.store === store && live(c.stamp),
        }
      );
      if (controller.signal.aborted && outcome.state !== 'applied')
        outcome = { state: 'unknown', warning: stoppedMessage };
      if (
        c.epoch === epoch &&
        c.store === store &&
        live(c.stamp) &&
        outcome.warning
      )
        recoveryError.value = outcome.warning;
      return outcome;
    } catch (e) {
      const failure = controller.signal.aborted ? new Error(stoppedMessage) : e;
      if (c.epoch === epoch && c.store === store && live(c.stamp))
        recoveryError.value =
          failure instanceof Error ? failure.message : '结果仍未知；原请求保留';
      throw failure;
    } finally {
      if (controllers.get(record.id) === controller) {
        controllers.delete(record.id);
        stopping.value = { ...stopping.value, [record.id]: false };
      }
      if (store?.key === c.store.key && live(c.stamp)) {
        if (sequences.get(record.id) === sequence)
          running.value = { ...running.value, [record.id]: false };
        await reload(store, epoch).catch(() => undefined);
      }
    }
  }
  function stopWaiting(record: PendingRequest) {
    current();
    const controller = controllers.get(record.id);
    if (!controller) return;
    stopping.value = { ...stopping.value, [record.id]: true };
    recoveryError.value = stoppedMessage;
    controller.abort();
  }
  async function release(record: PendingRequest) {
    const c = current(),
      outcome = await run(record, false);
    if (outcome.state === 'applied') return outcome;
    if (outcome.state !== 'rejected')
      throw new Error(
        '尚无完整业务拒绝和当前无锚点回执，不能换UUID或清除原请求'
      );
    if (!live(c.stamp)) throw new Error('SESSION已改变，原请求保留');
    await c.store.forget(record);
    await reload(c.store, c.epoch);
    return outcome;
  }
  function pendingFor(
    endpoint: MutationEndpoint,
    kind: string,
    payload: Record<string, unknown>
  ) {
    return requests.value.find(
      (r) =>
        conflictKey(r.endpoint, r.kind, r.payload) ===
        conflictKey(endpoint, kind, payload)
    );
  }
  return {
    requests,
    recoveryError,
    running,
    stopping,
    bind,
    clearView,
    prepare,
    run,
    stopWaiting,
    release,
    pendingFor,
    reload,
  };
}
