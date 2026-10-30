<template>
  <div class="tenant-license">
    <el-alert
      v-if="error"
      :title="error"
      type="warning"
      :closable="false"
      show-icon
    >
      <el-button link @click="load">重新加载许可证</el-button>
    </el-alert>
    <el-alert
      v-else-if="loaded"
      :type="license?.effective ? 'info' : 'warning'"
      :title="title"
      :closable="false"
      show-icon
    >
      <template #default>
        <span v-if="license"
          >生效：{{ displayTime(license.effective_at) }}；到期：{{
            license.expires_at ? displayTime(license.expires_at) : '长期有效'
          }}。</span
        >
        <span v-if="!license?.effective"
          >可选业务暂不可用，请联系平台开通或续期；仍可登录、维护账号安全及进行所有者核心管理。</span
        >
        <span v-else>业务还需具备对应模块权益及成员权限。</span>
        <el-button link :disabled="loading" @click="load">刷新状态</el-button>
      </template>
    </el-alert>
  </div>
</template>

<script setup lang="ts">
  import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
  import {
    getTenantLicense,
    type TenantLicenseSummary,
  } from '@/api/tenant-license';

  const license = ref<TenantLicenseSummary | null>(null);
  const loading = ref(false);
  const loaded = ref(false);
  const error = ref('');
  const labels = {
    active: '生效中',
    suspended: '已暂停',
    upcoming: '未生效',
    expired: '已到期',
  };
  const title = computed(() =>
    license.value
      ? `当前套餐：${license.value.plan_name} (${license.value.plan_code}) · ${
          labels[license.value.state]
        }`
      : '当前租户未开通许可证'
  );
  let controller: AbortController | null = null;
  function displayTime(value: string) {
    return new Date(value).toLocaleString();
  }
  async function load() {
    controller?.abort();
    const current = new AbortController();
    controller = current;
    loading.value = true;
    error.value = '';
    try {
      const result = await getTenantLicense(current.signal);
      if (current.signal.aborted) return;
      license.value = result;
      loaded.value = true;
    } catch (cause) {
      if (!current.signal.aborted)
        error.value = cause instanceof Error ? cause.message : '无法加载许可证';
    } finally {
      if (!current.signal.aborted) loading.value = false;
    }
  }
  onMounted(load);
  onBeforeUnmount(() => controller?.abort());
</script>

<style scoped>
  .tenant-license {
    padding: 12px 16px 0;
  }
</style>
