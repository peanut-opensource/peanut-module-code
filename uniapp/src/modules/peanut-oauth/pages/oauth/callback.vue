<template>
  <view class="page">
    <view v-if="loading" class="state">正在完成微信授权…</view>
    <view v-else class="state error">{{
      error || '授权失败，请返回重试'
    }}</view>
  </view>
</template>

<script setup lang="ts">
  import { ref } from 'vue';
  import { onLoad } from '@dcloudio/uni-app';
  import {
    callbackWechatOAuth,
    stashOAuthCompletion,
    type OAuthResult,
  } from '@/modules/peanut-oauth/api/oauth';
  import { useUserStore } from '@/store/user';

  const userStore = useUserStore();
  const loading = ref(true);
  const error = ref('');

  onLoad((query) => {
    void handleCallback(query as Record<string, string | undefined>);
  });

  async function handleCallback(query: Record<string, string | undefined>) {
    const scene = query.scene === 'open_pc' ? 'open_pc' : 'oa';
    const code = String(query.code || '');
    const state = String(query.state || '');
    if (!code || !state) {
      error.value = '微信授权参数缺失';
      loading.value = false;
      return;
    }

    try {
      const result = await callbackWechatOAuth({ scene, code, state });
      await consumeResult(result);
    } catch (reason) {
      error.value = reason instanceof Error ? reason.message : '微信授权失败';
      loading.value = false;
    }
  }

  async function consumeResult(result: OAuthResult) {
    if (result.completed && result.token) {
      userStore.setToken(result.token);
      userStore.setUserInfo(result.member);
      uni.reLaunch({ url: safePath(result.return_path, '/pages/user/user') });
      return;
    }

    if (!result.completion_ticket) throw new Error('微信登录补全票据缺失');
    const returnPath = safePath(result.return_path, '/pages/user/user');
    stashOAuthCompletion(result, returnPath);
    uni.navigateTo({ url: '/pages/oauth/complete' });
  }

  function safePath(value: string | undefined, fallback: string): string {
    let path = value || fallback;
    try {
      path = decodeURIComponent(path);
    } catch (_) {}
    if (!path.startsWith('/') || path.startsWith('//') || path.includes('\\'))
      return fallback;
    // The server's PC OAuth callback path is not a UniApp page. Do not loop
    // back into the callback when a browser authorization returns that path.
    if (path === '/oauth/callback') return fallback;
    return path;
  }
</script>

<style scoped>
  .page {
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #f5f5f5;
  }
  .state {
    padding: 60rpx;
    color: #666;
    font-size: 30rpx;
    text-align: center;
  }
  .error {
    color: #d4380d;
  }
</style>
