<template>
  <div class="flex items-center gap-3">
    <template v-if="userStore.isLoggedIn">
      <NuxtLink v-if="useModuleAvailable('peanut.payment')" to="/recharge" class="text-gray-600 hover:text-primary"
        >充值</NuxtLink
      >
      <el-dropdown>
        <div class="flex items-center gap-2 cursor-pointer">
          <el-avatar :size="32" :src="avatar" />
          <span class="text-gray-700">{{ nickname }}</span>
        </div>
        <template #dropdown>
          <el-dropdown-menu>
            <el-dropdown-item @click="navigateTo('/user/info')"
              >个人资料</el-dropdown-item
            >
            <el-dropdown-item v-if="useModuleAvailable('peanut.article')" @click="navigateTo('/user/collection')"
              >我的收藏</el-dropdown-item
            >
            <el-dropdown-item @click="navigateTo('/account/security')"
              >账户安全</el-dropdown-item
            >
            <el-dropdown-item divided @click="handleLogout"
              >退出登录</el-dropdown-item
            >
          </el-dropdown-menu>
        </template>
      </el-dropdown>
    </template>
    <NuxtLink v-else to="/login">
      <el-button type="primary" size="small">登录</el-button>
    </NuxtLink>
  </div>
</template>

<script setup lang="ts">
  const userStore = useUserStore();
  const request = useRequest();
  const avatar = computed(() =>
    typeof userStore.userInfo?.avatar === 'string'
      ? userStore.userInfo.avatar
      : ''
  );
  const nickname = computed(() =>
    typeof userStore.userInfo?.nickname === 'string'
      ? userStore.userInfo.nickname
      : '用户'
  );

  async function handleLogout() {
    let revoked = true;
    try {
      await request.post('api/login/logout');
    } catch {
      revoked = false;
    } finally {
      userStore.clearSession();
    }
    await navigateTo('/');
    if (revoked) ElMessage.success('已退出登录');
    else ElMessage.warning('服务端撤销失败，已清除本地会话');
  }
</script>
