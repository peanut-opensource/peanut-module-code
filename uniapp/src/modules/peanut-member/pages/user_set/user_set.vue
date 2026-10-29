<template>
  <view class="page">
    <view class="menu-list">
      <view class="menu-item" @click="goChangePwd">
        <text class="title">修改密码</text>
        <text class="arrow">›</text>
      </view>
      <view class="menu-item" @click="goBindMobile">
        <text class="title">绑定手机号</text>
        <text class="arrow">›</text>
      </view>
      <ModuleSlot name="oauth-binding" />
      <view class="menu-item" @click="goPrivacy">
        <text class="title">隐私政策</text>
        <text class="arrow">›</text>
      </view>
      <view class="menu-item" @click="goService">
        <text class="title">用户协议</text>
        <text class="arrow">›</text>
      </view>
    </view>

    <view class="logout-area">
      <button class="btn-logout" @click="handleLogout">退出登录</button>
    </view>
  </view>
</template>

<script setup lang="ts">
  import { useUserStore } from '@/store/user';
  import { logout } from '@/modules/peanut-member/api/account';
  import ModuleSlot from '@/components/ModuleSlot.vue';

  const userStore = useUserStore();

  function goChangePwd() {
    uni.navigateTo({ url: '/pages/change_password/change_password' });
  }
  function goBindMobile() {
    uni.navigateTo({ url: '/pages/bind_mobile/bind_mobile' });
  }
  function goPrivacy() {
    uni.navigateTo({ url: '/pages/agreement/agreement?type=privacy' });
  }
  function goService() {
    uni.navigateTo({ url: '/pages/agreement/agreement?type=service' });
  }

  async function handleLogout() {
    const expectedToken = userStore.token;
    const expectedGeneration = userStore.sessionGeneration;
    const confirmed = await new Promise<boolean>((resolve) =>
      uni.showModal({
        title: '提示',
        content: '确定退出登录吗？',
        success: (res) => resolve(res.confirm),
      })
    );
    if (!confirmed) return;
    if (
      userStore.sessionGeneration !== expectedGeneration ||
      userStore.token !== expectedToken
    )
      return;
    try {
      await logout();
    } catch (_) {}
    if (
      userStore.sessionGeneration !== expectedGeneration ||
      userStore.token !== expectedToken
    )
      return;
    if (!userStore.clearIfToken(expectedToken || null)) return;
    uni.reLaunch({ url: '/pages/index/index' });
  }
</script>

<style scoped>
  .page {
    background: #f5f5f5;
    min-height: 100vh;
  }
  .menu-list {
    background: #fff;
    margin-top: 20rpx;
  }
  .menu-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 30rpx 32rpx;
    border-bottom: 1rpx solid #f5f5f5;
  }
  .title {
    font-size: 28rpx;
    color: #333;
  }
  .arrow {
    font-size: 36rpx;
    color: #ccc;
  }
  .logout-area {
    padding: 60rpx 40rpx;
  }
  .btn-logout {
    width: 100%;
    height: 90rpx;
    background: #fff;
    color: #ff4d4f;
    font-size: 32rpx;
    border-radius: 45rpx;
    border: 1rpx solid #ff4d4f;
  }
</style>
