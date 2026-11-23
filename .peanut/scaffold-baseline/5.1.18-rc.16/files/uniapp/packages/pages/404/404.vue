<template>
  <view class="page">
    <view class="error-content">
      <image src="/static/404.png" class="error-img" mode="aspectFit" />
      <view class="error-title">页面不存在</view>
      <view class="error-desc">抱歉，您访问的页面不存在或已被删除</view>
      <button class="btn-back" @click="goHome">返回首页</button>
    </view>
  </view>
</template>

<script setup lang="ts">
  function goHome() {
    uni.reLaunch({ url: '/pages/index/index' });
  }
</script>

<style scoped>
  .page {
    background: #f5f5f5;
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
  }
  .error-content {
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 60rpx;
  }
  .error-img {
    width: 300rpx;
    height: 300rpx;
    opacity: 0.5;
  }
  .error-title {
    font-size: 36rpx;
    font-weight: 600;
    color: #333;
    margin-top: 40rpx;
  }
  .error-desc {
    font-size: 26rpx;
    color: #999;
    margin-top: 16rpx;
    text-align: center;
  }
  .btn-back {
    width: 400rpx;
    height: 80rpx;
    background: #2979ff;
    color: #fff;
    font-size: 28rpx;
    border-radius: 40rpx;
    border: none;
    margin-top: 60rpx;
  }
</style>
