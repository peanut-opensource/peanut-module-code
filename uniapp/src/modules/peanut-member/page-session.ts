import { onShow } from '@dcloudio/uni-app';
import { useUserStore } from '@/store/user';

export function useMemberPageSession() {
  const userStore = useUserStore();
  onShow(() => {
    if (!userStore.isLoggedIn) uni.reLaunch({ url: '/pages/login/login' });
  });
  return userStore;
}
