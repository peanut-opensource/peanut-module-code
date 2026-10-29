<template>
  <view class="page">
    <view class="desc">
      <text>输入注册时的手机号，重置账号密码</text>
    </view>

    <view class="form">
      <view class="input-group">
        <input
          v-model="form.mobile"
          class="input"
          type="number"
          placeholder="请输入手机号"
          maxlength="11"
        />
      </view>
      <view class="input-group">
        <input
          v-model="form.new_password"
          :maxlength="-1"
          class="input"
          type="password"
          :placeholder="passwordHint"
        />
      </view>
      <view class="input-group">
        <input
          v-model="form.confirm_password"
          :maxlength="-1"
          class="input"
          type="password"
          placeholder="请再次输入新密码"
        />
      </view>
    </view>

    <view class="btn-area">
      <button class="btn-primary" :disabled="loading" @click="handleSubmit">
        {{ loading ? '提交中...' : '重置密码' }}
      </button>
    </view>

    <view class="footer">
      <text @click="goLogin" class="link">返回登录</text>
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
  const form = ref({ mobile: '', new_password: '', confirm_password: '' });

  async function handleSubmit() {
    if (!passwordPolicy.value) {
      await loadPasswordPolicy();
      if (!passwordPolicy.value)
        return uni.showToast({
          title: passwordPolicyError.value,
          icon: 'none',
        });
    }
    if (!/^1\d{10}$/.test(form.value.mobile))
      return uni.showToast({ title: '请输入正确的手机号', icon: 'none' });
    if (!form.value.new_password)
      return uni.showToast({ title: '请输入新密码', icon: 'none' });
    if (form.value.new_password !== form.value.confirm_password)
      return uni.showToast({ title: '两次密码不一致', icon: 'none' });
    if (!passwordWithinPolicy(form.value.new_password, passwordPolicy.value))
      return uni.showToast({ title: passwordHint.value, icon: 'none' });
    // Note: backend forget_pwd API not implemented in this version — show placeholder
    uni.showToast({ title: '功能开发中', icon: 'none' });
  }

  function goLogin() {
    uni.navigateBack();
  }
</script>

<style scoped>
  .page {
    background: #fff;
    min-height: 100vh;
    padding: 0 60rpx;
  }
  .desc {
    padding: 60rpx 0 40rpx;
    font-size: 28rpx;
    color: #999;
  }
  .form {
    margin-top: 20rpx;
  }
  .input-group {
    border-bottom: 1rpx solid #eee;
    margin-bottom: 40rpx;
  }
  .input {
    height: 80rpx;
    font-size: 30rpx;
    color: #333;
    width: 100%;
  }
  .btn-area {
    margin-top: 40rpx;
  }
  .btn-primary {
    width: 100%;
    height: 90rpx;
    background: #2979ff;
    color: #fff;
    font-size: 32rpx;
    border-radius: 45rpx;
    border: none;
  }
  .btn-primary[disabled] {
    opacity: 0.6;
  }
  .footer {
    text-align: center;
    margin-top: 30rpx;
  }
  .link {
    font-size: 28rpx;
    color: #2979ff;
  }
</style>
