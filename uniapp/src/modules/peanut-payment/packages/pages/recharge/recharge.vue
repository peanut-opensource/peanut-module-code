<template>
  <view class="page">
    <view v-if="loadingConfig" class="state-card">正在读取充值配置…</view>
    <view v-else-if="config && config.status !== 1" class="state-card">
      充值功能暂未开启
    </view>
    <view v-else-if="config && config.channels.length === 0" class="state-card">
      当前终端暂无可用支付方式
    </view>

    <template v-else-if="config">
      <view class="amount-select">
        <view class="label">选择充值金额</view>
        <view class="hint"
          >最低充值 {{ config.min_amount }} 元，当前余额
          {{ config.balance }} 元</view
        >
        <view class="preset-amounts">
          <view
            v-for="preset in presets"
            :key="preset"
            class="preset-item"
            :class="{ active: amount === String(preset) }"
            @click="amount = String(preset)"
          >
            {{ preset }}元
          </view>
        </view>
        <view class="custom-amount">
          <input
            v-model="amount"
            type="digit"
            placeholder="或输入自定义金额"
            class="custom-input"
            maxlength="10"
          />
        </view>
      </view>

      <view class="channel-select">
        <view class="label">支付方式</view>
        <view
          v-for="channel in config.channels"
          :key="channel.pay_way"
          class="channel-item"
          :class="{ active: selectedPayWay === channel.pay_way }"
          @click="selectedPayWay = channel.pay_way"
        >
          <text>{{ channel.name }}</text>
          <text v-if="channel.is_default === 1" class="default-label"
            >默认</text
          >
        </view>
      </view>

      <view class="btn-area">
        <button class="btn-primary" :disabled="loading" @click="handleRecharge">
          {{ loading ? '处理中...' : `立即充值 ${amount || 0} 元` }}
        </button>
      </view>

      <view v-if="nativeCode" class="native-card">
        <view class="native-title">请使用对应支付应用扫码</view>
        <text class="native-code" selectable @click="copyNativeCode">{{
          nativeCode
        }}</text>
        <button class="copy-btn" @click="copyNativeCode">复制二维码链接</button>
      </view>

      <view v-if="currentOrder" class="order-card">
        <view>订单号：{{ currentOrder.sn }}</view>
        <view>金额：{{ currentOrder.order_amount }} 元</view>
        <view>状态：{{ currentOrder.pay_status_text }}</view>
      </view>
    </template>
  </view>
</template>

<script setup lang="ts">
  import { computed, ref } from 'vue';
  import { onLoad } from '@dcloudio/uni-app';
  import { requestNativePayment } from '@/modules/peanut-payment/utils/native-payment';
  import {
    createRecharge,
    getRechargeConfig,
    getRechargeDetail,
    getRechargeTerminal,
    prepayRecharge,
    type PaymentResult,
    type RechargeConfig,
    type RechargeOrder,
  } from '@/modules/peanut-payment/api/recharge';

  const terminal = getRechargeTerminal();
  const config = ref<RechargeConfig | null>(null);
  const amount = ref('');
  const selectedPayWay = ref<number | null>(null);
  const currentOrder = ref<RechargeOrder | null>(null);
  const nativeCode = ref('');
  const loadingConfig = ref(true);
  const loading = ref(false);

  const presets = computed(() => {
    const minimum = Number(config.value?.min_amount || 0);
    return [10, 50, 100, 200, 500, 1000].filter((value) => value >= minimum);
  });

  onLoad(loadConfig);

  async function loadConfig() {
    loadingConfig.value = true;
    try {
      const data = await getRechargeConfig(terminal);
      config.value = data;
      amount.value = data.min_amount;
      selectedPayWay.value =
        data.channels.find((channel) => channel.is_default === 1)?.pay_way ??
        data.channels[0]?.pay_way;
    } catch (error) {
      console.error('Failed to load recharge config:', error);
    } finally {
      loadingConfig.value = false;
    }
  }

  function validAmount(value: string): boolean {
    if (!/^(?:0|[1-9]\d{0,5})(?:\.\d{1,2})?$/.test(value)) return false;
    const minimum = Number(config.value?.min_amount || 0);
    return Number(value) >= minimum && Number(value) > 0;
  }

  async function handleRecharge() {
    const value = amount.value.trim();
    if (!validAmount(value)) {
      uni.showToast({
        title: `请输入不低于 ${config.value?.min_amount || '最低'} 元的金额`,
        icon: 'none',
      });
      return;
    }
    if (!selectedPayWay.value) {
      uni.showToast({ title: '请选择支付方式', icon: 'none' });
      return;
    }

    loading.value = true;
    nativeCode.value = '';
    try {
      // The server owns the member, amount validation and default channel. The
      // client submits only its derived terminal and the requested amount.
      const order = await createRecharge({ terminal, amount: value });
      currentOrder.value = order;
      const prepared = await prepayRecharge({
        order_id: order.id,
        pay_way: selectedPayWay.value,
      });
      currentOrder.value = prepared.order;
      await consumePayment(prepared.payment);
    } finally {
      loading.value = false;
    }
  }

  async function consumePayment(payment: PaymentResult) {
    const scene = String(payment.scene).toUpperCase();
    if (scene === 'JSAPI' || scene === 'APP') {
      await requestNativePayment(payment);
      if (currentOrder.value) {
        // Read the authoritative order state after returning from the SDK. This
        // never calls a payment callback or fabricates an in-app settlement.
        currentOrder.value = await getRechargeDetail(currentOrder.value.id);
      }
      uni.showToast({ title: '支付请求已提交' });
      return;
    }

    if (scene === 'MWEB' || scene === 'WAP' || scene === 'PAGE') {
      const url = String(
        payment.payload.h5_url || payment.payload.gateway_url || ''
      );
      if (!url) throw new Error('支付跳转地址缺失');
      openExternal(url);
      return;
    }

    if (scene === 'NATIVE') {
      const codeUrl = String(payment.payload.code_url || '');
      if (!codeUrl) throw new Error('二维码地址缺失');
      nativeCode.value = codeUrl;
      return;
    }

    throw new Error('当前支付场景暂不支持');
  }

  function openExternal(url: string) {
    const location = (globalThis as { location?: { href: string } }).location;
    if (location) {
      location.href = url;
      return;
    }
    uni.setClipboardData({ data: url });
    uni.showModal({ title: '请打开支付链接', content: url, showCancel: false });
  }

  function copyNativeCode() {
    if (!nativeCode.value) return;
    uni.setClipboardData({ data: nativeCode.value });
    uni.showToast({ title: '已复制' });
  }
</script>

<style scoped>
  .page {
    background: #f5f5f5;
    min-height: 100vh;
    padding-bottom: 40rpx;
  }
  .state-card,
  .amount-select,
  .channel-select,
  .native-card,
  .order-card {
    background: #fff;
    margin: 24rpx;
    border-radius: 16rpx;
    padding: 32rpx;
  }
  .state-card {
    color: #999;
    text-align: center;
  }
  .label {
    font-size: 30rpx;
    font-weight: 600;
    color: #333;
  }
  .hint {
    margin-top: 12rpx;
    color: #999;
    font-size: 24rpx;
  }
  .preset-amounts {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 20rpx;
    margin-top: 28rpx;
  }
  .preset-item {
    background: #f5f5f5;
    border: 2rpx solid transparent;
    border-radius: 12rpx;
    padding: 24rpx;
    text-align: center;
    font-size: 28rpx;
    color: #333;
  }
  .preset-item.active {
    background: #e6f4ff;
    border-color: #2979ff;
    color: #2979ff;
    font-weight: 600;
  }
  .custom-amount {
    margin-top: 28rpx;
  }
  .custom-input {
    width: 100%;
    height: 80rpx;
    background: #f5f5f5;
    border-radius: 12rpx;
    padding: 0 24rpx;
    box-sizing: border-box;
    font-size: 30rpx;
    color: #333;
  }
  .channel-select {
    padding-bottom: 16rpx;
  }
  .channel-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-top: 20rpx;
    border: 2rpx solid #eee;
    border-radius: 12rpx;
    padding: 22rpx 24rpx;
    color: #333;
  }
  .channel-item.active {
    border-color: #2979ff;
    color: #2979ff;
  }
  .default-label {
    color: #999;
    font-size: 22rpx;
  }
  .btn-area {
    padding: 16rpx 40rpx 40rpx;
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
  .native-title {
    font-size: 28rpx;
    color: #333;
    margin-bottom: 18rpx;
  }
  .native-code {
    display: block;
    color: #2979ff;
    font-size: 24rpx;
    line-height: 1.5;
    word-break: break-all;
  }
  .copy-btn {
    margin-top: 20rpx;
    font-size: 26rpx;
  }
  .order-card {
    color: #666;
    font-size: 26rpx;
    line-height: 1.9;
  }
</style>
