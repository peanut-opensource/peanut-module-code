<template>
  <view class="page">
    <view class="form">
      <view class="input-group">
        <input
          v-model="mobile"
          class="input"
          type="number"
          placeholder="请输入手机号"
          maxlength="11"
        />
      </view>
    </view>

    <view class="btn-area">
      <button class="btn-primary" :disabled="loading" @click="handleSubmit">
        {{ loading ? '绑定中...' : '立即绑定' }}
      </button>
    </view>
  </view>
</template>

<script setup lang="ts">
  import { ref } from 'vue';
  import { bindMobile } from '@/modules/peanut-member/api/user';

  const loading = ref(false);
  const mobile = ref('');

  async function handleSubmit() {
    if (!/^1\d{10}$/.test(mobile.value))
      return uni.showToast({ title: '请输入正确的手机号', icon: 'none' });

    loading.value = true;
    try {
      await bindMobile({ mobile: mobile.value });
      uni.showToast({ title: '绑定成功' });
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
    padding: 30rpx 32rpx;
  }
  .input-group {
    border-bottom: 1rpx solid #eee;
  }
  .input {
    height: 80rpx;
    font-size: 30rpx;
    color: #333;
    width: 100%;
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
