import { useCrudTable as useCoreCrudTable } from '@peanut-admin/vue';
import type { CrudTableOptions } from '@peanut-admin/vue';
import useCrudTab from './useCrudTab';

export default function useCrudTable<
  TRecord,
  TFilters extends Record<string, unknown>
>(options: CrudTableOptions<TRecord, TFilters>) {
  const active = useCrudTab(() => undefined);
  return useCoreCrudTable({ ...options, scope: { active } });
}
