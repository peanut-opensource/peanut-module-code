export const useUserStore = defineStore('user', () => {
  const token = useCookie<string>('token', { maxAge: 604800 });
  const userInfo = useCookie<Record<string, unknown>>('user_info', {
    maxAge: 604800,
  });

  const isLoggedIn = computed(() => !!token.value);

  function login(data: {
    token: string;
    id: number;
    sn: string;
    nickname: string;
    avatar: string;
    mobile: string;
  }) {
    token.value = data.token;
    userInfo.value = {
      id: data.id,
      sn: data.sn,
      nickname: data.nickname,
      avatar: data.avatar,
      mobile: data.mobile,
    };
  }

  function setUserInfo(info: Record<string, unknown>) {
    userInfo.value = { ...(userInfo.value || {}), ...info };
  }

  // Transport expiry handling must only clear local request state. Explicit logout
  // callers invoke the server endpoint before calling this method.
  function clearSession() {
    token.value = '';
    userInfo.value = {};
  }

  return { token, userInfo, isLoggedIn, login, setUserInfo, clearSession };
});
