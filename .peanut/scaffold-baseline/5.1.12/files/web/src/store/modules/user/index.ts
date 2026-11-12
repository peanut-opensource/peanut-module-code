import { defineStore } from 'pinia';
import { getUserInfo, LoginData } from '@/api/user';
import {
  advanceSessionGeneration,
  clearToken,
  getSessionSnapshot,
  setToken,
} from '@/utils/auth';
import { selectTenant, tenantLogin, tenantLogout } from '@/api/tenant-session';
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
    async login(loginForm: LoginData) {
      let generation = advanceSessionGeneration();
      const resetLoginState = () => {
        this.resetInfo();
        useBrandStore().setTenantName();
        removeRouteListener();
        useAppStore().clearServerMenu();
      };
      try {
        resetLoginState();
        await disposeTenantState();
        if (getSessionSnapshot().generation !== generation) {
          throw new Error('The login session has changed.');
        }
        if (loginForm.challengeToken && loginForm.tenantId) {
          const authenticated = await selectTenant(
            loginForm.challengeToken,
            loginForm.tenantId,
            generation
          );
          if (getSessionSnapshot().generation !== generation) {
            throw new Error('The login session has changed.');
          }
          resetLoginState();
          generation = advanceSessionGeneration();
          setToken(authenticated.access_token);
          return authenticated;
        }
        const outcome = await tenantLogin(
          loginForm.username,
          loginForm.password,
          generation
        );
        if (!outcome || typeof outcome !== 'object') {
          throw new Error('Tenant session returned no session data.');
        }
        if (getSessionSnapshot().generation !== generation) {
          throw new Error('The login session has changed.');
        }
        if (outcome.state === 'tenant_selection_required') {
          if (!loginForm.tenantId) return outcome;
          const authenticated = await selectTenant(
            outcome.challenge_token,
            loginForm.tenantId,
            generation
          );
          if (getSessionSnapshot().generation !== generation) {
            throw new Error('The login session has changed.');
          }
          resetLoginState();
          generation = advanceSessionGeneration();
          setToken(authenticated.access_token);
          return authenticated;
        }
        resetLoginState();
        generation = advanceSessionGeneration();
        setToken(outcome.access_token);
        return outcome;
      } catch (err) {
        if (getSessionSnapshot().generation === generation) {
          resetLoginState();
          clearToken();
        }
        throw err;
      }
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
      const transition: { generation: number | null; token: string | null } = {
        generation: null,
        token,
      };
      let cleared = false;
      try {
        if (token !== null && isTenantAccessToken(token)) {
          await tenantLogout(
            { generation, token },
            (currentToken, nextGeneration) => {
              transition.token = currentToken;
              transition.generation = nextGeneration;
            }
          );
        } else {
          transition.generation = advanceSessionGeneration();
        }
      } catch (error) {
        if (
          transition.generation === null &&
          getSessionSnapshot().generation !== generation
        )
          return false;
        throw error;
      } finally {
        if (transition.generation !== null) {
          const afterRemoteLogout = getSessionSnapshot();
          if (
            afterRemoteLogout.generation === transition.generation &&
            afterRemoteLogout.token === transition.token
          ) {
            cleared = await this.logoutCallBack(
              transition.generation,
              transition.token
            );
          }
        }
      }
      return cleared;
    },
  },
});

export default useUserStore;
