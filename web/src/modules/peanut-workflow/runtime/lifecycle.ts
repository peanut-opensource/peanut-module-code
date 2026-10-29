import { onBeforeUnmount, watch } from 'vue';
import { registerTenantDisposer } from '@peanut-admin/vue';
import { useUserStore } from '@/store';
import { getSessionSnapshot } from '@/utils/auth';

/** A page owns its state. Identity changes dispose it even before route removal. */
// eslint-disable-next-line import/prefer-default-export -- Keep the named lifecycle API used by both Workflow pages.
export function useWorkflowLifecycle(dispose: () => void) {
  const user = useUserStore();
  const { generation } = getSessionSnapshot();
  const { id: memberId, tenantName } = user;
  let disposed = false;
  let unregister: (() => void) | undefined;
  let stop: (() => void) | undefined;
  let removeStorageListener: (() => void) | undefined;
  const close = () => {
    if (disposed) return;
    disposed = true;
    // Remove page-owned subscriptions before a consumer cleanup can throw.
    stop?.();
    unregister?.();
    removeStorageListener?.();
    dispose();
  };
  const valid = () => {
    if (
      !disposed &&
      (generation !== getSessionSnapshot().generation ||
        memberId !== user.id ||
        tenantName !== user.tenantName)
    )
      close();
    return !disposed;
  };
  function storage(event: StorageEvent) {
    // Re-read the authoritative identity, including clear() and legacy tokens.
    // An ordinary token refresh or a sessionStorage event must not close a page.
    if (event.storageArea === null || event.storageArea === localStorage)
      valid();
  }
  unregister = registerTenantDisposer(
    `peanut.workflow.page.${crypto.randomUUID()}`,
    close
  );
  if (disposed) {
    unregister();
  } else {
    stop = watch(() => [user.id, user.tenantName], close, { flush: 'sync' });
    window.addEventListener('storage', storage);
    removeStorageListener = () =>
      window.removeEventListener('storage', storage);
  }
  onBeforeUnmount(close);
  return { valid, close };
}
