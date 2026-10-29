<template>
  <view>
    <button
      v-if="isMiniProgram"
      class="btn-wechat"
      :disabled="wechatLoading"
      @click="handleMiniProgramLogin"
    >
      {{ wechatLoading ? '微信登录中...' : '微信一键登录' }}
    </button>
    <button
      v-else
      class="btn-wechat"
      :disabled="wechatLoading"
      @click="handleOfficialAccountLogin"
    >
      {{ wechatLoading ? '正在跳转...' : '微信公众号登录' }}
    </button>
  </view>
</template>
<script setup lang="ts">
  import { ref, computed } from 'vue';
  import { useUserStore } from '@/store/user';
  import {
    beginWechatOAuth,
    loginWechatMiniProgram,
    stashOAuthCompletion,
    type OAuthResult,
  } from '@/modules/peanut-oauth/api/oauth';
  const userStore = useUserStore();
  const wechatLoading = ref(false);
  const runtimeInfo = (
    typeof uni !== 'undefined' && typeof uni.getSystemInfoSync === 'function'
      ? uni.getSystemInfoSync()
      : {}
  ) as { uniPlatform?: string };
  const isMiniProgram = computed(() => runtimeInfo.uniPlatform === 'mp-weixin');

  async function handleMiniProgramLogin() {
    wechatLoading.value = true;
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
      await consumeWechatResult(await loginWechatMiniProgram(code));
    } catch (error) {
      uni.showToast({
        title: error instanceof Error ? error.message : '微信登录失败',
        icon: 'none',
      });
    } finally {
      wechatLoading.value = false;
    }
  }

  async function handleOfficialAccountLogin() {
    wechatLoading.value = true;
    try {
      const result = await beginWechatOAuth({
        scene: 'oa',
        return_path: '/pages/user/user',
      });
      const location = (globalThis as { location?: { href: string } }).location;
      if (location) {
        location.href = result.authorization_url;
      } else {
        uni.setClipboardData({ data: result.authorization_url });
        uni.showModal({
          title: '请打开微信授权链接',
          content: result.authorization_url,
          showCancel: false,
        });
      }
    } catch (error) {
      uni.showToast({
        title: error instanceof Error ? error.message : '微信授权失败',
        icon: 'none',
      });
    } finally {
      wechatLoading.value = false;
    }
  }

  async function consumeWechatResult(result: OAuthResult) {
    if (result.completed && result.token) {
      // A completion ticket is not a login token. Only persist a token after the
      // server has returned completed=true.
      userStore.setToken(result.token);
      userStore.setUserInfo(result.member);
      uni.reLaunch({ url: '/pages/user/user' });
      return;
    }
    if (!result.completion_ticket) throw new Error('微信登录补全票据缺失');
    stashOAuthCompletion(result, '/pages/user/user');
    uni.navigateTo({ url: '/pages/oauth/complete' });
  }
</script>
<style scoped>
  .btn-wechat {
    margin-top: 24rpx;
    background: #07c160;
    color: white;
  }
</style>
