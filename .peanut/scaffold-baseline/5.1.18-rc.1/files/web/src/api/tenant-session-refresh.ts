import axios from 'axios';
import { createSessionRecovery } from '@peanut-admin/client';
import type { SessionSnapshot } from '@peanut-admin/client';
import { isTenantAccessToken } from '@peanut-admin/vue';
import { refreshTenantSession } from '@/api/tenant-session';
import { getSessionSnapshot } from '@/utils/auth';
import { useUserStore } from '@/store';

export type TenantSessionSnapshot = SessionSnapshot;

/** The host owns its protocol, business cleanup and login destination. */
const recovery = createSessionRecovery({
  current: getSessionSnapshot,
  isRefreshable: isTenantAccessToken,
  refresh: (snapshot) => {
    if (snapshot.token === null)
      throw new Error('The request session has changed.');
    return refreshTenantSession(snapshot.token, snapshot.generation);
  },
  isRefreshRejected: (error) =>
    axios.isAxiosError<{ code?: number }>(error) &&
    error.response?.status === 401 &&
    error.response.data?.code === 40100,
  invalidate: async (snapshot) => {
    const { default: router } = await import('@/router');
    const redirect = router.currentRoute.value.fullPath;
    try {
      // An already rejected credential needs local cleanup, not another logout request.
      await useUserStore().logoutCallBack(snapshot.generation, snapshot.token);
    } finally {
      const current = getSessionSnapshot();
      if (
        current.generation === snapshot.generation + 1 &&
        current.token === null &&
        router.currentRoute.value.name !== 'login'
      ) {
        await router.replace({ name: 'login', query: { redirect } });
      }
    }
  },
});

export const refreshTenantAccessToken = recovery.refresh;
export const invalidateTenantSession = recovery.invalidate;
export const tenantSessionRecovery = recovery;
