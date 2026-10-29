import { inject, provide } from 'vue';
import type { InjectionKey } from 'vue';
import type { TaskActionDetails } from '../api';
import type { WorkItem, WorkflowInstance } from '../types';

export interface WorkflowTaskFormContext {
  /** Business form writes are only part of completion or resubmission, never claim or approval. */
  action: 'complete' | 'resubmit';
  workItem: WorkItem;
  instance: WorkflowInstance;
}
export type WorkflowTaskFormResult = TaskActionDetails & {
  attachment_file_keys: string[];
  comment?: string;
};
export type WorkflowTaskFormProvider = (
  context: WorkflowTaskFormContext
) => Promise<WorkflowTaskFormResult | null>;
const FORM_PROVIDER: InjectionKey<WorkflowTaskFormProvider> = Symbol(
  'workflow-business-form'
);
/** Business modules register trusted form handling; HTTP never supplies a class or executable callback. */
export function provideWorkflowTaskForm(provider: WorkflowTaskFormProvider) {
  provide(FORM_PROVIDER, provider);
}
export function useWorkflowTaskForm() {
  return inject(FORM_PROVIDER, null);
}
