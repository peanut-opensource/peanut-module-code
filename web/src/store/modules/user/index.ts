import { defineStore } from 'pinia';
import { getUserInfo, LoginData } from '@/api/user';
import {
  assertSessionGeneration,
  clearToken,
  getSessionSnapshot,
  setToken,
} from '@/utils/auth';
import {
  queueSessionCookieRequest,
  selectTenant,
  tenantLogin,
  tenantLogout,
} from '@/api/tenant-session';
import { disposeTenantState, isTenantAccessToken } from '@peanut-admin/vue';
import { removeRouteListener } from '@/utils/route-listener';
import { UserState } from './types';
import useAppStore from '../app';
import useBrandStore from '../brand';

const useUserStore = defineStore('user', {
  state: (): UserState => ({
    name: undefined,
    avatar: undefined,
    job: undefined,
    organization: undefined,
    location: undefined,
    email: undefined,
    introduction: undefined,
    personalWebsite: undefined,
    jobName: undefined,
    organizationName: undefined,
    locationName: undefined,
    phone: undefined,
    registrationDate: undefined,
    accountId: undefined,
    tenantId: undefined,
    certification: undefined,
    role: '',
    permissions: [],
    action_bindings: [],
    menu: [],
    canSwitchTenant: false,
    tenantName: '',
    demoMode: false,
  }),

  getters: {
    userInfo(state: UserState): UserState {
      return { ...state };
    },
  },

  actions: {
    // Set user's information
    setInfo(partial: Partial<UserState>) {
      this.$patch(partial);
    },

    // Reset user's information
    resetInfo() {
      this.$reset();
    },

    // Get user's information
    async info() {
      const snapshot = getSessionSnapshot();
      const res = await getUserInfo();
      const current = getSessionSnapshot();
      if (snapshot.generation !== current.generation)
        throw new Error('The request session has changed.');
      const appStore = useAppStore();
      const brandStore = useBrandStore();
      appStore.setServerMenu(res.data.menu || []);
      this.setInfo(res.data);
      brandStore.setTenantName(res.data.tenantName);
      brandStore.replace(res.data.website);
    },

    // Login
    login(loginForm: LoginData) {
      const { generation } = getSessionSnapshot();
      return queueSessionCookieRequest(async () => {
        const resetLoginState = () => {
          this.resetInfo();
          useBrandStore().setTenantName();
          removeRouteListener();
          useAppStore().clearServerMenu();
        };
        try {
          resetLoginState();
          await disposeTenantState();
          assertSessionGeneration(generation);
          let outcome =
            loginForm.challengeToken && loginForm.tenantId
              ? await selectTenant(loginForm.challengeToken, loginForm.tenantId)
              : await tenantLogin(loginForm.username, loginForm.password);
          if (
            outcome.state === 'tenant_selection_required' &&
            loginForm.tenantId
          ) {
            assertSessionGeneration(generation);
            outcome = await selectTenant(
              outcome.challenge_token,
              loginForm.tenantId
            );
          }
          assertSessionGeneration(generation);
          if (outcome.state === 'authenticated') {
            setToken(outcome.access_token, 'tenant-switched');
          }
          return outcome;
        } catch (error) {
          if (getSessionSnapshot().generation === generation) {
            resetLoginState();
            clearToken();
          }
          throw error;
        }
      }, generation);
    },
    async logoutCallBack(
      expectedGeneration: number,
      expectedToken: string | null
    ) {
      const beforeDispose = getSessionSnapshot();
      if (
        beforeDispose.generation !== expectedGeneration ||
        beforeDispose.token !== expectedToken
      )
        return false;
      let disposalError: unknown;
      try {
        await disposeTenantState();
      } catch (error) {
        disposalError = error;
      }
      const beforeCommit = getSessionSnapshot();
      if (
        beforeCommit.generation !== expectedGeneration ||
        beforeCommit.token !== expectedToken
      )
        return false;
      const appStore = useAppStore();
      const brandStore = useBrandStore();
      this.resetInfo();
      brandStore.setTenantName();
      clearToken();
      removeRouteListener();
      appStore.clearServerMenu();
      if (disposalError !== undefined) throw disposalError;
      return true;
    },
    // Logout
    async logout(expected?: { generation: number; token: string | null }) {
      const beforeLogout = getSessionSnapshot();
      const generation = expected?.generation ?? beforeLogout.generation;
      const token = expected ? expected.token : beforeLogout.token;
      if (
        beforeLogout.generation !== generation ||
        beforeLogout.token !== token
      )
        return false;
      let started = false;
      try {
        return await queueSessionCookieRequest(async () => {
          const activeToken = getSessionSnapshot().token;
          started = true;
          let cleared = false;
          try {
            if (activeToken !== null && isTenantAccessToken(activeToken)) {
              await tenantLogout(activeToken);
            }
          } finally {
            cleared = await this.logoutCallBack(generation, activeToken);
          }
          return cleared;
        }, generation);
      } catch (error) {
        if (!started && getSessionSnapshot().generation !== generation)
          return false;
        throw error;
      }
    },
  },
});

export default useUserStore;
