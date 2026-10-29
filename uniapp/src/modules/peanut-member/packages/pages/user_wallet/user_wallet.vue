<template>
  <view class="wallet-page">
    <view class="wallet-card">
      <view class="label">账户余额</view>
      <view class="amount">¥{{ balance }}</view>
      <view class="points">积分 {{ userInfo.points || 0 }}</view>
    </view>
  </view>
</template>

<script setup lang="ts">
  import { computed, onMounted } from 'vue';
  import { getUserCenter } from '@/modules/peanut-member/api/user';
  import { useUserStore } from '@/store/user';

  const userStore = useUserStore();
  const userInfo = computed(() => userStore.userInfo);
  const balance = computed(() =>
    Number(userInfo.value.balance || 0).toFixed(2)
  );

  onMounted(async () => {
    const data = await getUserCenter();
    userStore.setUserInfo(data);
  });
</script>

<style scoped>
  .wallet-page {
    min-height: 100vh;
    padding: 32rpx;
    background: #f5f5f5;
  }
  .wallet-card {
    padding: 48rpx;
    border-radius: 20rpx;
    color: #fff;
    background: linear-gradient(135deg, #2979ff, #1d54c4);
  }
  .label {
    font-size: 26rpx;
    opacity: 0.85;
  }
  .amount {
    margin-top: 18rpx;
    font-size: 60rpx;
    font-weight: 600;
  }
  .points {
    margin-top: 30rpx;
    font-size: 28rpx;
  }
</style>
