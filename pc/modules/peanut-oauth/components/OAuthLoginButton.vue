<template>
  <div>
    <el-divider content-position="center">或</el-divider>
    <el-button
      class="w-full"
      :loading="wechatLoading"
      @click="handleWechatLogin"
    >
      使用微信登录
    </el-button>
  </div>
</template>

<script setup lang="ts">
  import { beginWechatPc } from '../api/oauth';
  const wechatLoading = ref(false);
  async function handleWechatLogin() {
    wechatLoading.value = true;
    try {
      const result = await beginWechatPc(useRequest(), '/');
      if (!result.authorization_url) {
        ElMessage.error('微信授权地址为空');
        return;
      }
      window.location.assign(result.authorization_url);
    } catch {
      // useRequest already reports the server message.
    } finally {
      wechatLoading.value = false;
    }
  }
</script>
