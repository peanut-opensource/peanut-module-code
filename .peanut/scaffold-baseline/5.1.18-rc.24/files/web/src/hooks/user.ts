import { useRouter } from 'vue-router';
import { ElMessage } from 'element-plus';

import { useUserStore } from '@/store';
import { getSessionSnapshot } from '@/utils/auth';

export default function useUser() {
  const router = useRouter();
  const userStore = useUserStore();
  const logout = async (logoutTo?: string) => {
    const session = getSessionSnapshot();
    let cleared = false;
    let failed = false;
    try {
      cleared = await userStore.logout(session);
    } catch {
      failed = true;
    }
    if (!cleared && !failed) return;
    const afterLogout = getSessionSnapshot();
    // Clearing the local session advances its identity generation once.
    if (
      afterLogout.generation !== session.generation + 1 ||
      afterLogout.token !== null
    )
      return;
    const currentRoute = router.currentRoute.value;
    if (failed) ElMessage.warning('退出时发生错误，本地登录状态已清除');
    else ElMessage.success('登出成功');
    await router.push({
      name: logoutTo && typeof logoutTo === 'string' ? logoutTo : 'login',
      query: {
        ...router.currentRoute.value.query,
        redirect: currentRoute.fullPath,
      },
    });
  };
  return {
    logout,
  };
}
