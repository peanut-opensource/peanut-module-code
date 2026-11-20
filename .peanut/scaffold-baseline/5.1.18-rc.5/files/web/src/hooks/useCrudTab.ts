import {
  computed,
  inject,
  onActivated,
  onDeactivated,
  onScopeDispose,
  ref,
  watch,
} from 'vue';
import { routerKey } from 'vue-router';
import { getTabKey } from '@peanut-admin/ui-vue/tabs';

/** Watches the same tab key used by page-layout, before KeepAlive hooks run. */
export default function useCrudTab(
  onInactive: () => void,
  onActive?: () => void
) {
  const router = inject(routerKey, null);
  const key = router ? getTabKey(router.currentRoute.value) : undefined;
  const active = ref(true);
  const current = computed(
    () =>
      active.value && (!router || getTabKey(router.currentRoute.value) === key)
  );

  watch(
    current,
    (isCurrent) => {
      if (isCurrent) onActive?.();
      else onInactive();
    },
    { flush: 'sync' }
  );
  onActivated(() => {
    active.value = true;
  });
  onDeactivated(() => {
    active.value = false;
  });
  onScopeDispose(() => {
    active.value = false;
    onInactive();
  });

  return current;
}
