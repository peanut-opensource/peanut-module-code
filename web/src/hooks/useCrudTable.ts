import { useAsyncAction, useAsyncList } from '@peanut-admin/vue';
import type { AsyncListOptions } from '@peanut-admin/vue';
import useCrudTab from './useCrudTab';

interface CrudTableOptions<TRecord, TFilters extends Record<string, unknown>>
  extends AsyncListOptions<TRecord, TFilters> {
  deleteRecord?: (record: TRecord, signal: AbortSignal) => Promise<unknown>;
  deleteErrorMessage?: (error: unknown) => string;
}

export default function useCrudTable<
  TRecord,
  TFilters extends Record<string, unknown>
>(options: CrudTableOptions<TRecord, TFilters>) {
  const list = useAsyncList(options);
  const deletion = useAsyncAction(
    options.deleteErrorMessage ?? options.errorMessage
  );
  let requestedPage = list.pagination.page;
  let resumePage: number | undefined;

  const current = useCrudTab(
    () => {
      if (list.loading.value || deletion.loading.value)
        resumePage = requestedPage;
      list.dispose();
      deletion.cancel();
    },
    () => {
      if (resumePage === undefined) return;
      const page = resumePage;
      resumePage = undefined;
      list.load(page);
    }
  );

  const fetchList = async (page = list.pagination.page) => {
    if (!current.value) return false;
    requestedPage = page;
    return list.load(page);
  };

  const handleSearch = () => fetchList(1);
  const handleReset = () => {
    list.resetFilters();
    return handleSearch();
  };

  const handleDelete = async (record: TRecord) => {
    const remove = options.deleteRecord;
    if (!current.value || !remove) return false;
    const result = await deletion.run(async ({ signal }) => {
      await remove(record, signal);
      if (!signal.aborted) await fetchList(requestedPage);
    });
    return result.status === 'completed' && current.value;
  };

  return {
    loading: list.loading,
    pagination: list.pagination,
    searchForm: list.filters,
    data: list.items,
    error: list.error,
    clear: list.clear,
    deleting: deletion.loading,
    deleteError: deletion.error,
    fetchList,
    handleSearch,
    handleReset,
    handleDelete,
  };
}
