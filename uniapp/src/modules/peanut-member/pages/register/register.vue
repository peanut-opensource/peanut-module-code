<template>
  <view class="register-page">
    <view class="title-area">
      <view class="title">创建账号</view>
      <view class="subtitle">欢迎加入我们</view>
    </view>

    <view class="form">
      <view class="input-group">
        <input
          v-model="form.account"
          placeholder="请设置账号（字母或数字）"
          class="input"
          type="text"
        />
      </view>
      <view class="input-group">
        <input
          v-model="form.password"
          :maxlength="-1"
          :placeholder="passwordHint"
          class="input"
          type="password"
        />
      </view>
      <view class="input-group">
        <input
          v-model="form.password_confirm"
          :maxlength="-1"
          placeholder="请再次输入密码"
          class="input"
          type="password"
        />
      </view>

      <button class="btn-primary" :disabled="loading" @click="handleRegister">
        {{ loading ? '注册中...' : '立即注册' }}
      </button>
    </view>

    <view class="footer">
      <text>已有账号？</text>
      <text class="link" @click="goLogin">立即登录</text>
    </view>
  </view>
</template>

<script setup lang="ts">
  import { computed, ref } from 'vue';
  import { onShow } from '@dcloudio/uni-app';
  import {
    getPasswordPolicy,
    passwordWithinPolicy,
    type PasswordPolicy,
  } from '@/api/password-policy';
  import { register } from '@/modules/peanut-member/api/account';

  const loading = ref(false);
  const passwordPolicy = ref<PasswordPolicy | null>(null);
  const passwordPolicyError = ref('');
  const passwordHint = computed(() =>
    passwordPolicy.value
      ? `密码须为 ${passwordPolicy.value.minimum_length}～${passwordPolicy.value.maximum_length} 个 UTF-8 字节`
      : passwordPolicyError.value || '正在读取密码要求'
  );
  async function loadPasswordPolicy() {
    try {
      passwordPolicy.value = await getPasswordPolicy();
      passwordPolicyError.value = '';
    } catch {
      passwordPolicy.value = null;
      passwordPolicyError.value = '无法读取密码要求，请重试';
    }
  }
  onShow(loadPasswordPolicy);
  const form = ref({ account: '', password: '', password_confirm: '' });

  async function handleRegister() {
    if (!passwordPolicy.value) {
      await loadPasswordPolicy();
      if (!passwordPolicy.value)
        return uni.showToast({
          title: passwordPolicyError.value,
          icon: 'none',
        });
    }
    if (!form.value.account)
      return uni.showToast({ title: '请输入账号', icon: 'none' });
    if (!form.value.password)
      return uni.showToast({ title: '请输入密码', icon: 'none' });
    if (form.value.password !== form.value.password_confirm)
      return uni.showToast({ title: '两次密码不一致', icon: 'none' });
    if (!passwordWithinPolicy(form.value.password, passwordPolicy.value))
      return uni.showToast({ title: passwordHint.value, icon: 'none' });

    loading.value = true;
    try {
      await register(form.value);
      uni.showToast({ title: '注册成功，请登录', icon: 'success' });
      uni.reLaunch({ url: '/pages/login/login' });
    } finally {
      loading.value = false;
    }
  }

  function goLogin() {
    uni.navigateTo({ url: '/pages/login/login' });
  }
</script>

<style scoped>
  .register-page {
    min-height: 100vh;
    background: #fff;
    padding: 0 60rpx;
  }
  .title-area {
    padding: 120rpx 0 80rpx;
  }
  .title {
    font-size: 48rpx;
    font-weight: 700;
    color: #333;
  }
  .subtitle {
    font-size: 28rpx;
    color: #999;
    margin-top: 16rpx;
  }
  .input-group {
    border-bottom: 1rpx solid #eee;
    margin-bottom: 40rpx;
  }
  .input {
    width: 100%;
    height: 80rpx;
    font-size: 30rpx;
    color: #333;
  }
  .btn-primary {
    width: 100%;
    height: 90rpx;
    background: #2979ff;
    color: #fff;
    font-size: 32rpx;
    border-radius: 45rpx;
    border: none;
    margin-top: 40rpx;
  }
  .btn-primary[disabled] {
    opacity: 0.6;
  }
  .footer {
    text-align: center;
    margin-top: 40rpx;
    font-size: 28rpx;
    color: #666;
  }
  .link {
    color: #2979ff;
  }
</style>
