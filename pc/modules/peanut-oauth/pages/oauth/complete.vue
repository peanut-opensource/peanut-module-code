<template>
  <div
    class="min-h-screen flex items-center justify-center bg-gray-50 px-6 py-10"
  >
    <div class="bg-white rounded-2xl shadow-sm p-10 w-full max-w-lg">
      <template v-if="pending">
        <h2 class="text-2xl font-bold text-gray-800">完善微信账号</h2>
        <p class="text-gray-500 text-sm mt-2"
          >完成必要资料后即可继续使用账号。</p
        >

        <el-form
          class="mt-8"
          :model="form"
          label-width="110px"
          size="large"
          @submit.prevent="handleComplete"
        >
          <el-form-item v-if="pending.need_profile" label="昵称" required>
            <el-input
              v-model="form.nickname"
              maxlength="50"
              placeholder="请输入昵称"
            />
          </el-form-item>
          <el-form-item v-if="pending.need_profile" label="头像地址">
            <el-input
              v-model="form.avatar"
              placeholder="可选，填写头像图片地址"
            />
          </el-form-item>
          <el-form-item v-if="pending.need_mobile" label="手机号" required>
            <el-input
              v-model="form.mobile"
              type="tel"
              maxlength="11"
              placeholder="请输入手机号"
            />
          </el-form-item>
          <el-form-item v-if="pending.need_mobile" label="验证码" required>
            <el-input
              v-model="form.verification_code"
              maxlength="10"
              placeholder="请输入短信验证码"
            />
          </el-form-item>
          <el-form-item>
            <el-button type="primary" native-type="submit" :loading="submitting"
              >完成并登录</el-button
            >
            <NuxtLink to="/login" class="ml-4 text-gray-500">取消</NuxtLink>
          </el-form-item>
        </el-form>
      </template>
      <template v-else>
        <h2 class="text-xl font-semibold text-gray-800">无法继续微信登录</h2>
        <p class="text-gray-500 text-sm mt-3">{{ message }}</p>
        <NuxtLink to="/login">
          <el-button class="mt-6" type="primary">返回登录</el-button>
        </NuxtLink>
      </template>
    </div>
  </div>
</template>

<script setup lang="ts">
  import { completeWechat, type OAuthCompletionResult } from '~/modules/peanut-oauth/api/oauth';

  definePageMeta({ layout: false });

  const userStore = useUserStore();
  const request = useRequest();
  const pending = ref<OAuthCompletionResult | null>(null);
  const submitting = ref(false);
  const message = ref('登录补全票据缺失或已过期，请重新发起微信登录。');
  const form = reactive({
    nickname: '',
    avatar: '',
    mobile: '',
    verification_code: '',
  });

  function safeReturnPath(value: unknown): string {
    return typeof value === 'string' &&
      value.startsWith('/') &&
      !value.startsWith('//')
      ? value
      : '/';
  }

  onMounted(() => {
    const raw = sessionStorage.getItem('peanut_oauth_completion');
    if (!raw) return;
    try {
      const result = JSON.parse(raw) as OAuthCompletionResult;
      if (result.completed || !result.completion_ticket) return;
      pending.value = result;
      form.nickname = result.member?.nickname || '';
      form.avatar = result.member?.avatar || '';
    } catch {
      // Keep the default message when the browser storage is malformed.
    }
  });

  async function handleComplete() {
    const current = pending.value;
    if (!current) return;
    if (current.need_profile && !form.nickname.trim()) {
      ElMessage.warning('请输入昵称');
      return;
    }
    if (current.need_mobile && !/^1[3-9]\d{9}$/.test(form.mobile)) {
      ElMessage.warning('请输入正确的手机号');
      return;
    }
    if (current.need_mobile && !form.verification_code.trim()) {
      ElMessage.warning('请输入短信验证码');
      return;
    }

    const payload: {
      ticket: string;
      nickname?: string;
      avatar?: string;
      mobile?: string;
      verification_code?: string;
    } = { ticket: current.completion_ticket };
    if (current.need_profile) {
      payload.nickname = form.nickname.trim();
      if (form.avatar.trim()) payload.avatar = form.avatar.trim();
    }
    if (current.need_mobile) {
      payload.mobile = form.mobile.trim();
      payload.verification_code = form.verification_code.trim();
    }

    submitting.value = true;
    try {
      const result = await completeWechat(request, payload);
      if (!result.completed || !result.token || !result.member)
        throw new Error('登录补全结果不完整');
      userStore.login({ token: result.token, ...result.member });
      sessionStorage.removeItem('peanut_oauth_completion');
      await navigateTo(safeReturnPath(current.return_path));
    } catch (error) {
      message.value =
        error instanceof Error ? error.message : '登录补全失败，请重试';
      ElMessage.error(message.value);
    } finally {
      submitting.value = false;
    }
  }
</script>
