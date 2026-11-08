import { defineStore } from 'pinia';
import { ref, computed, watch } from 'vue';

interface SessionIdentity {
  id?: number;
  sn?: string;
  nickname?: string;
  avatar?: string;
  mobile?: string;
}

interface SessionLogin {
  token: string;
  id: number;
  sn: string;
  nickname: string;
  avatar: string;
  mobile: string;
}

const STORAGE_KEY = 'user_store';

export const useUserStore = defineStore('user', () => {
  // Rehydrate from uni storage on init
  const saved = uni.getStorageSync(STORAGE_KEY);
  const initial = saved ? JSON.parse(saved) : {};

  const token = ref<string>(initial.token || '');
  const sessionGeneration = ref(0);
  const userInfo = ref<SessionIdentity & Record<string, unknown>>(
    initial.userInfo || {}
  );

  const isLoggedIn = computed(() => !!token.value);

  // Persist to uni storage on change
  watch(
    () => ({ token: token.value, userInfo: userInfo.value }),
    (val) => uni.setStorageSync(STORAGE_KEY, JSON.stringify(val)),
    { deep: true }
  );

  function setToken(newToken: string) {
    sessionGeneration.value += 1;
    token.value = newToken;
  }

  function clearIfToken(expectedToken: string | null) {
    if ((token.value || null) !== expectedToken) return false;
    token.value = '';
    sessionGeneration.value += 1;
    userInfo.value = {};
    uni.removeStorageSync(STORAGE_KEY);
    return true;
  }

  function setUserInfo(info: SessionIdentity) {
    userInfo.value = { ...userInfo.value, ...info };
  }

  function login(data: SessionLogin) {
    setToken(data.token);
    userInfo.value = {
      id: data.id,
      sn: data.sn,
      nickname: data.nickname,
      avatar: data.avatar,
      mobile: data.mobile,
    };
  }

  function logout() {
    sessionGeneration.value += 1;
    token.value = '';
    userInfo.value = {};
    uni.removeStorageSync(STORAGE_KEY);
  }

  return {
    token,
    sessionGeneration,
    userInfo,
    isLoggedIn,
    setToken,
    clearIfToken,
    setUserInfo,
    login,
    logout,
  };
});
