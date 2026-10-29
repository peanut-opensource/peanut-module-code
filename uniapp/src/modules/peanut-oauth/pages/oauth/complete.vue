<template>
  <view class="page">
    <view class="card">
      <view class="title">完善微信登录资料</view>
      <view v-if="needProfile" class="field">
        <text class="label">昵称</text>
        <input
          v-model="nickname"
          class="input"
          placeholder="请输入昵称"
          maxlength="50"
        />
      </view>
      <view v-if="needProfile" class="field">
        <text class="label">头像地址（可选）</text>
        <input v-model="avatar" class="input" placeholder="请输入头像地址" />
      </view>
      <view v-if="needMobile" class="field">
        <text class="label">手机号</text>
        <input
          v-model="mobile"
          class="input"
          type="number"
          maxlength="11"
          placeholder="请输入手机号"
        />
      </view>
      <view v-if="needMobile" class="field">
        <text class="label">验证码</text>
        <input
          v-model="verificationCode"
          class="input"
          type="number"
          maxlength="6"
          placeholder="请输入验证码"
        />
      </view>
      <button class="btn-primary" :disabled="loading" @click="handleComplete">
        {{ loading ? '提交中...' : '完成登录' }}
      </button>
    </view>
  </view>
</template>

<script setup lang="ts">
  import { ref } from 'vue';
  import { onLoad } from '@dcloudio/uni-app';
  import {
    completeWechatOAuth,
    consumeOAuthCompletion,
    type OAuthResult,
  } from '@/modules/peanut-oauth/api/oauth';
  import { useUserStore } from '@/store/user';

  const userStore = useUserStore();
  const ticket = ref('');
  const returnPath = ref('/pages/user/user');
  const needProfile = ref(false);
  const needMobile = ref(false);
  const nickname = ref('');
  const avatar = ref('');
  const mobile = ref('');
  const verificationCode = ref('');
  const loading = ref(false);

  onLoad(() => {
    const state = consumeOAuthCompletion();
    ticket.value = state?.ticket || '';
    needProfile.value = state?.need_profile || false;
    needMobile.value = state?.need_mobile || false;
    returnPath.value = safePath(state?.return_path);
  });

  async function handleComplete() {
    if (!ticket.value) {
      uni.showToast({ title: '登录补全票据无效', icon: 'none' });
      return;
    }
    if (needProfile.value && !nickname.value.trim()) {
      uni.showToast({ title: '请输入昵称', icon: 'none' });
      return;
    }
    if (needMobile.value && !/^1[3-9]\d{9}$/.test(mobile.value)) {
      uni.showToast({ title: '请输入正确的手机号', icon: 'none' });
      return;
    }
    if (needMobile.value && !verificationCode.value.trim()) {
      uni.showToast({ title: '请输入验证码', icon: 'none' });
      return;
    }

    loading.value = true;
    try {
      const payload: {
        ticket: string;
        nickname?: string;
        avatar?: string;
        mobile?: string;
        verification_code?: string;
      } = { ticket: ticket.value };
      if (needProfile.value) {
        payload.nickname = nickname.value.trim();
        if (avatar.value.trim()) payload.avatar = avatar.value.trim();
      }
      if (needMobile.value) {
        payload.mobile = mobile.value;
        payload.verification_code = verificationCode.value.trim();
      }
      const result = await completeWechatOAuth(payload);
      finishLogin(result);
    } finally {
      loading.value = false;
    }
  }

  function finishLogin(result: OAuthResult) {
    if (!result.completed || !result.token) throw new Error('登录补全未完成');
    userStore.setToken(result.token);
    userStore.setUserInfo(result.member);
    uni.reLaunch({ url: returnPath.value });
  }

  function safePath(value: string | undefined): string {
    let path = value || '/pages/user/user';
    try {
      path = decodeURIComponent(path);
    } catch (_) {}
    if (
      !path.startsWith('/') ||
      path.startsWith('//') ||
      path.includes('\\') ||
      path === '/oauth/callback'
    ) {
      return '/pages/user/user';
    }
    return path;
  }
</script>

<style scoped>
  .page {
    min-height: 100vh;
    padding: 24rpx;
    box-sizing: border-box;
    background: #f5f5f5;
  }
  .card {
    background: #fff;
    border-radius: 16rpx;
    padding: 40rpx 32rpx;
  }
  .title {
    margin-bottom: 28rpx;
    color: #333;
    font-size: 34rpx;
    font-weight: 600;
  }
  .field {
    margin-bottom: 24rpx;
  }
  .label {
    display: block;
    margin-bottom: 10rpx;
    color: #666;
    font-size: 26rpx;
  }
  .input {
    width: 100%;
    height: 76rpx;
    padding: 0 20rpx;
    box-sizing: border-box;
    border: 1rpx solid #eee;
    border-radius: 10rpx;
    color: #333;
    font-size: 28rpx;
  }
  .btn-primary {
    width: 100%;
    height: 86rpx;
    margin-top: 20rpx;
    border: none;
    border-radius: 43rpx;
    background: #2979ff;
    color: #fff;
    font-size: 30rpx;
  }
  .btn-primary[disabled] {
    opacity: 0.6;
  }
</style>
