import type { WorkItem } from '../types';

export const TASK_ACTION_LABELS: Record<string, string> = {
  claim: '领取任务',
  release: '归还候选池',
  approve: '同意',
  reject: '拒绝',
  complete: '完成办理',
  resubmit: '重新送审',
  return: '退回补正',
};
export const INSTANCE_ACTION_LABELS: Record<string, string> = {
  withdraw: '撤回申请',
  terminate: '终止流程',
  retry: '重试阻塞步骤',
  reassign: '重分配待处理任务',
};
export function visibleTaskActions(task: WorkItem): string[] {
  return task.available_actions.filter((action) =>
    Object.prototype.hasOwnProperty.call(TASK_ACTION_LABELS, action)
  );
}
export function requiresReason(action: string): boolean {
  return [
    'reject',
    'return',
    'withdraw',
    'terminate',
    'reassign',
    'retry',
  ].includes(action);
}
export function safeFormEntry(entry: string | null): string | null {
  if (
    !entry ||
    !entry.startsWith('/') ||
    entry.startsWith('//') ||
    entry.includes('\\')
  )
    return null;
  return entry;
}
