<template>
  <div class="min-h-screen flex items-center justify-center bg-gray-50 px-6">
    <div
      class="bg-white rounded-2xl shadow-sm p-10 w-full max-w-md text-center"
    >
      <el-icon v-if="loading" class="is-loading" :size="36" color="#409eff"
        ><Loading
      /></el-icon>
      <h2 class="text-xl font-semibold text-gray-800 mt-4">{{
        loading ? '正在完成微信登录…' : title
      }}</h2>
      <p v-if="message" class="text-gray-500 text-sm mt-3">{{ message }}</p>
      <NuxtLink v-if="!loading" to="/login">
        <el-button class="mt-6" type="primary">返回登录</el-button>
      </NuxtLink>
    </div>
  </div>
</template>

<script setup lang="ts">
  import { callbackWechatPc, type OAuthCallbackResult } from '~/modules/peanut-oauth/api/oauth';

  definePageMeta({ layout: false });

  const route = useRoute();
  const userStore = useUserStore();
  const request = useRequest();
  const loading = ref(true);
  const title = ref('微信登录失败');
  const message = ref('');

  function queryString(value: unknown): string {
    if (Array.isArray(value)) return String(value[0] || '');
    return typeof value === 'string' ? value : '';
  }

  function safeReturnPath(value: unknown): string {
    const path = queryString(value);
    return path.startsWith('/') && !path.startsWith('//') ? path : '/';
  }

  function saveCompletion(
    result: Extract<OAuthCallbackResult, { completed: false }>
  ) {
    if (!import.meta.client) return;
    sessionStorage.setItem('peanut_oauth_completion', JSON.stringify(result));
  }

  onMounted(async () => {
    const error = queryString(route.query.error);
    const code = queryString(route.query.code);
    const state = queryString(route.query.state);
    if (error) {
      message.value = queryString(route.query.error_description) || error;
      loading.value = false;
      return;
    }
    if (!code || !state) {
      message.value = '微信授权参数不完整，请重新登录';
      loading.value = false;
      return;
    }

    try {
      const result = await callbackWechatPc(request, code, state);
      if (result.completed) {
        if (!result.token || !result.member)
          throw new Error('微信登录结果不完整');
        userStore.login({ token: result.token, ...result.member });
        title.value = '登录成功';
        await navigateTo(safeReturnPath(result.return_path));
        return;
      }

      saveCompletion(result);
      await navigateTo('/oauth/complete');
    } catch (error) {
      message.value =
        error instanceof Error ? error.message : '微信登录失败，请重试';
      loading.value = false;
    }
  });
</script>
