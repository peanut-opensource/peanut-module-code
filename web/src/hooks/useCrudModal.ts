import { useCrudModal as useCoreCrudModal } from '@peanut-admin/vue';
import type { CrudModalOptions } from '@peanut-admin/vue';
import useCrudTab from './useCrudTab';

export type { CrudModalMode } from '@peanut-admin/vue';

export default function useCrudModal<
  TForm extends object,
  TRecord,
  TOptions = never
>(config: CrudModalOptions<TForm, TRecord, TOptions>) {
  const active = useCrudTab(() => undefined);
  return useCoreCrudModal({ ...config, scope: { active } });
}
