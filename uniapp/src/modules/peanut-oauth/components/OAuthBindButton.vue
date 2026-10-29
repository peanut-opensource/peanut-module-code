<template>
  <view v-if="isMiniProgram" class="menu-item" @click="handleBindWechat">
    <text class="title">绑定微信小程序</text>
    <text class="arrow">›</text>
  </view>
</template>
<script setup lang="ts">
  import { bindWechatIdentity } from '../api/oauth';
  const runtimeInfo = (
    typeof uni !== 'undefined' && typeof uni.getSystemInfoSync === 'function'
      ? uni.getSystemInfoSync()
      : {}
  ) as { uniPlatform?: string };
  const isMiniProgram = runtimeInfo.uniPlatform === 'mp-weixin';

  async function handleBindWechat() {
    try {
      const code = await new Promise<string>((resolve, reject) => {
        uni.login({
          provider: 'weixin',
          success: (result) => {
            const value = String((result as { code?: string }).code || '');
            value ? resolve(value) : reject(new Error('微信登录凭证缺失'));
          },
          fail: reject,
        });
      });
      await bindWechatIdentity('mnp', code);
      uni.showToast({ title: '微信绑定成功' });
    } catch (error) {
      uni.showToast({
        title: error instanceof Error ? error.message : '微信绑定失败',
        icon: 'none',
      });
    }
  }
</script>
<style scoped>
  .menu-item {
    display: flex;
    justify-content: space-between;
    padding: 30rpx 32rpx;
    border-bottom: 1rpx solid #f5f5f5;
  }
  .title {
    font-size: 28rpx;
  }
  .arrow {
    font-size: 36rpx;
    color: #ccc;
  }
</style>
