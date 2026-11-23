import { getCurrentScope, onScopeDispose, ref, shallowRef } from 'vue';

/** Starts loading immediately; revision checks discard late responses after reload or cancel. */
export default function useRequest<T>(
  api: () => Promise<{ data: T }>,
  defaultValue?: T,
  isLoading = true
) {
  const loading = ref(isLoading);
  const response = shallowRef<T | undefined>(defaultValue);
  const error = shallowRef<Error | null>(null);
  let revision = 0;

  const cancel = () => {
    revision += 1;
    loading.value = false;
  };
  const reload = async (showLoading = true): Promise<void> => {
    const current = ++revision;
    loading.value = showLoading;
    error.value = null;
    try {
      const result = await api();
      if (current === revision) response.value = result.data;
    } catch (reason: unknown) {
      if (current === revision) {
        error.value =
          reason instanceof Error ? reason : new Error('REQUEST_FAILED');
      }
    } finally {
      if (current === revision) loading.value = false;
    }
  };
  if (getCurrentScope()) onScopeDispose(cancel);
  void reload(isLoading);
  return { loading, response, error, reload, cancel };
}
