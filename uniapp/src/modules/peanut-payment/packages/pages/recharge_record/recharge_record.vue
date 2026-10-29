<template>
  <view class="page">
    <view v-if="loading" class="empty">正在加载…</view>
    <view v-else-if="records.length === 0" class="empty">暂无充值记录</view>
    <view
      v-for="item in records"
      :key="item.id"
      class="record-item"
      @click="viewDetail(item.id)"
    >
      <view class="record-header">
        <view class="record-type">充值 · {{ item.pay_way_text }}</view>
        <view class="record-amount">{{ item.order_amount }} 元</view>
      </view>
      <view class="record-line"
        >{{ item.pay_status_text }} · {{ item.terminal_text }}</view
      >
      <view class="record-footer">
        <text class="record-time">{{ item.create_time }}</text>
        <text class="record-sn">{{ item.sn }}</text>
      </view>
    </view>
    <view
      v-if="records.length > 0 && records.length < total"
      class="load-more"
      @click="loadMore"
    >
      加载更多
    </view>
  </view>
</template>

<script setup lang="ts">
  import { ref } from 'vue';
  import { onShow } from '@dcloudio/uni-app';
  import {
    getRechargeDetail,
    getRechargeLists,
    type RechargeOrder,
  } from '@/modules/peanut-payment/api/recharge';

  const records = ref<RechargeOrder[]>([]);
  const pageNo = ref(1);
  const pageSize = 20;
  const total = ref(0);
  const loading = ref(false);

  onShow(() => loadRecords(1));

  async function loadRecords(page: number) {
    loading.value = true;
    try {
      const data = await getRechargeLists({ pageNo: page, pageSize });
      pageNo.value = data.pageNo;
      total.value = data.count;
      records.value =
        page === 1 ? data.lists : [...records.value, ...data.lists];
    } catch (error) {
      console.error('Failed to load recharge records:', error);
    } finally {
      loading.value = false;
    }
  }

  function loadMore() {
    if (loading.value || records.value.length >= total.value) return;
    loadRecords(pageNo.value + 1);
  }

  async function viewDetail(orderId: number) {
    try {
      const order = await getRechargeDetail(orderId);
      uni.showModal({
        title: '充值订单详情',
        content: [
          `订单号：${order.sn}`,
          `金额：${order.order_amount} 元`,
          `支付方式：${order.pay_way_text}`,
          `状态：${order.pay_status_text}`,
          `创建时间：${order.create_time}`,
        ].join('\n'),
        showCancel: false,
      });
    } catch (error) {
      console.error('Failed to load recharge detail:', error);
    }
  }
</script>

<style scoped>
  .page {
    background: #f5f5f5;
    min-height: 100vh;
    padding: 24rpx;
    box-sizing: border-box;
  }
  .empty {
    text-align: center;
    color: #999;
    padding: 120rpx 0;
    font-size: 28rpx;
  }
  .record-item {
    background: #fff;
    border-radius: 12rpx;
    margin-bottom: 20rpx;
    padding: 30rpx;
  }
  .record-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 14rpx;
  }
  .record-type {
    font-size: 28rpx;
    color: #666;
  }
  .record-amount {
    font-size: 34rpx;
    font-weight: 600;
    color: #333;
  }
  .record-line {
    color: #666;
    font-size: 24rpx;
  }
  .record-footer {
    display: flex;
    justify-content: space-between;
    gap: 16rpx;
    margin-top: 16rpx;
    font-size: 22rpx;
    color: #999;
  }
  .record-time,
  .record-sn {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
  .record-sn {
    max-width: 52%;
  }
  .load-more {
    color: #2979ff;
    font-size: 26rpx;
    text-align: center;
    padding: 20rpx;
  }
</style>
