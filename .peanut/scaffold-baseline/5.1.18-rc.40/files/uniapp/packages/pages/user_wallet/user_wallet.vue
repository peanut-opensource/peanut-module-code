<template>
  <view class="page">
    <view class="wallet-header">
      <view class="balance-label">账户余额（元）</view>
      <view class="balance-amount">{{ balance }}</view>
    </view>

    <view class="action-cards">
      <view class="action-card" @click="goRecharge">
        <text class="action-icon">💰</text>
        <text class="action-title">充值</text>
      </view>
      <view class="action-card" @click="goRecord">
        <text class="action-icon">📋</text>
        <text class="action-title">明细</text>
      </view>
    </view>

    <view class="recent-section">
      <view class="section-title">最近明细</view>
      <view v-if="logs.length === 0" class="empty">暂无记录</view>
      <view v-for="item in logs" :key="item.id" class="log-item">
        <view class="log-info">
          <view class="log-remark">{{ item.remark }}</view>
          <view class="log-time">{{ item.create_time }}</view>
        </view>
        <view class="log-amount" :class="{ positive: item.change_amount > 0 }">
          {{ item.change_amount > 0 ? '+' : '' }}{{ item.change_amount }}
        </view>
      </view>
    </view>
  </view>
</template>

<script setup lang="ts">
  import { ref, computed, onMounted } from 'vue';
  import { useUserStore } from '@/store/user';
  import { getAccountLogs } from '@/api/shop';
  import type { AccountLog } from '@/api/shop';

  const userStore = useUserStore();
  const balance = computed(() => userStore.userInfo.balance || '0.00');
  const logs = ref<AccountLog[]>([]);

  onMounted(async () => {
    try {
      const data = await getAccountLogs({ page_size: 10 });
      logs.value = data.lists;
    } catch (error) {
      console.error('Failed to load account logs:', error);
    }
  });

  function goRecharge() {
    uni.navigateTo({ url: '/packages/pages/recharge/recharge' });
  }
  function goRecord() {
    uni.navigateTo({ url: '/packages/pages/recharge_record/recharge_record' });
  }
</script>

<style scoped>
  .page {
    background: #f5f5f5;
    min-height: 100vh;
  }
  .wallet-header {
    background: linear-gradient(135deg, #2979ff, #1d54c4);
    padding: 80rpx 40rpx 60rpx;
    text-align: center;
    color: #fff;
  }
  .balance-label {
    font-size: 28rpx;
    opacity: 0.9;
  }
  .balance-amount {
    font-size: 72rpx;
    font-weight: 700;
    margin-top: 20rpx;
  }
  .action-cards {
    display: flex;
    gap: 24rpx;
    padding: 24rpx;
    margin-top: -40rpx;
  }
  .action-card {
    flex: 1;
    background: #fff;
    border-radius: 16rpx;
    padding: 40rpx 20rpx;
    display: flex;
    flex-direction: column;
    align-items: center;
    box-shadow: 0 4rpx 20rpx rgba(0, 0, 0, 0.08);
  }
  .action-icon {
    font-size: 48rpx;
  }
  .action-title {
    font-size: 28rpx;
    color: #333;
    margin-top: 16rpx;
  }
  .recent-section {
    background: #fff;
    margin: 24rpx;
    border-radius: 16rpx;
    padding: 30rpx;
  }
  .section-title {
    font-size: 30rpx;
    font-weight: 600;
    color: #333;
    margin-bottom: 20rpx;
  }
  .empty {
    text-align: center;
    color: #999;
    padding: 60rpx 0;
    font-size: 26rpx;
  }
  .log-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 24rpx 0;
    border-bottom: 1rpx solid #f5f5f5;
  }
  .log-item:last-child {
    border-bottom: none;
  }
  .log-info {
    flex: 1;
  }
  .log-remark {
    font-size: 28rpx;
    color: #333;
    margin-bottom: 8rpx;
  }
  .log-time {
    font-size: 24rpx;
    color: #999;
  }
  .log-amount {
    font-size: 32rpx;
    font-weight: 600;
    color: #333;
  }
  .log-amount.positive {
    color: #52c41a;
  }
</style>
