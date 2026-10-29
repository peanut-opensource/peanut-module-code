<template>
  <view class="page">
    <view class="form">
      <view class="input-group">
        <view class="input-label">原密码</view>
        <input
          v-model="form.old_password"
          class="input"
          type="password"
          placeholder="请输入原密码"
        />
      </view>
      <view class="input-group">
        <view class="input-label">新密码</view>
        <input
          v-model="form.new_password"
          :maxlength="-1"
          class="input"
          type="password"
          :placeholder="passwordHint"
        />
      </view>
      <view class="input-group">
        <view class="input-label">确认密码</view>
        <input
          v-model="form.new_password_confirm"
          :maxlength="-1"
          class="input"
          type="password"
          placeholder="请再次输入新密码"
        />
      </view>
    </view>

    <view class="btn-area">
      <button class="btn-primary" :disabled="loading" @click="handleSubmit">
        {{ loading ? '提交中...' : '确认修改' }}
      </button>
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
  import { changePassword } from '@/modules/peanut-member/api/user';

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
  const form = ref({
    old_password: '',
    new_password: '',
    new_password_confirm: '',
  });

  async function handleSubmit() {
    if (!passwordPolicy.value) {
      await loadPasswordPolicy();
      if (!passwordPolicy.value)
        return uni.showToast({
          title: passwordPolicyError.value,
          icon: 'none',
        });
    }
    if (!form.value.old_password)
      return uni.showToast({ title: '请输入原密码', icon: 'none' });
    if (!form.value.new_password)
      return uni.showToast({ title: '请输入新密码', icon: 'none' });
    if (form.value.new_password !== form.value.new_password_confirm)
      return uni.showToast({ title: '两次密码不一致', icon: 'none' });
    if (!passwordWithinPolicy(form.value.new_password, passwordPolicy.value))
      return uni.showToast({ title: passwordHint.value, icon: 'none' });

    loading.value = true;
    try {
      await changePassword(form.value);
      uni.showToast({ title: '修改成功' });
      setTimeout(() => uni.navigateBack(), 800);
    } finally {
      loading.value = false;
    }
  }
</script>

<style scoped>
  .page {
    background: #f5f5f5;
    min-height: 100vh;
  }
  .form {
    background: #fff;
    margin-top: 20rpx;
  }
  .input-group {
    padding: 24rpx 32rpx;
    border-bottom: 1rpx solid #f5f5f5;
  }
  .input-label {
    font-size: 26rpx;
    color: #999;
    margin-bottom: 12rpx;
  }
  .input {
    font-size: 30rpx;
    color: #333;
  }
  .btn-area {
    padding: 60rpx 40rpx;
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
</style>
