<template>
  <view class="page">
    <view class="contact-section">
      <image
        v-if="service.qrcode"
        :src="service.qrcode"
        class="cs-img"
        mode="aspectFit"
      />
      <view v-else class="cs-placeholder">客服</view>
      <view class="cs-title">{{ service.title || '联系客服' }}</view>
      <view v-if="service.remark" class="cs-desc">{{ service.remark }}</view>
    </view>

    <view class="info-list">
      <view
        v-if="service.mobile"
        class="info-item"
        @click="callPhone(service.mobile)"
      >
        <text class="info-icon">📞</text>
        <text class="info-text">{{ service.mobile }}</text>
        <text class="action">拨打</text>
      </view>
      <view v-if="service.time" class="info-item">
        <text class="info-icon">🕐</text>
        <text class="info-text">服务时间：{{ service.time }}</text>
      </view>
    </view>
  </view>
</template>

<script setup lang="ts">
  import { reactive } from 'vue';
  import { onShow } from '@dcloudio/uni-app';
  import {
    getDecorationComponent,
    getMobileDecoration,
  } from '@/utils/decoration';

  const service = reactive({
    title: '',
    time: '',
    mobile: '',
    qrcode: '',
    remark: '',
  });

  async function loadService() {
    try {
      const page = await getMobileDecoration(3);
      const component = getDecorationComponent(page, 'customer-service');
      const content = component?.content || {};
      service.title = typeof content.title === 'string' ? content.title : '';
      service.time = typeof content.time === 'string' ? content.time : '';
      service.mobile = typeof content.mobile === 'string' ? content.mobile : '';
      service.qrcode = typeof content.qrcode === 'string' ? content.qrcode : '';
      service.remark = typeof content.remark === 'string' ? content.remark : '';
    } catch (error) {
      console.error('Failed to load customer service decoration:', error);
    }
  }
  onShow(loadService);

  function callPhone(phone: string) {
    uni.makePhoneCall({ phoneNumber: phone });
  }
</script>

<style scoped>
  .page {
    background: #f5f5f5;
    min-height: 100vh;
  }
  .contact-section {
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 80rpx 40rpx 40rpx;
    background: #fff;
  }
  .cs-img,
  .cs-placeholder {
    width: 200rpx;
    height: 200rpx;
  }
  .cs-placeholder {
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    background: #edf4ff;
    color: #2979ff;
    font-size: 40rpx;
  }
  .cs-title {
    font-size: 36rpx;
    font-weight: 600;
    color: #333;
    margin-top: 24rpx;
  }
  .cs-desc {
    font-size: 26rpx;
    color: #999;
    margin-top: 12rpx;
    text-align: center;
  }
  .info-list {
    background: #fff;
    margin-top: 20rpx;
  }
  .info-item {
    display: flex;
    align-items: center;
    padding: 28rpx 32rpx;
    border-bottom: 1rpx solid #f5f5f5;
  }
  .info-icon {
    font-size: 36rpx;
    margin-right: 20rpx;
  }
  .info-text {
    flex: 1;
    font-size: 28rpx;
    color: #333;
  }
  .action {
    font-size: 26rpx;
    color: #2979ff;
  }
</style>
