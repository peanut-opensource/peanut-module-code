<template>
  <div v-loading="loading" class="channel-panel">
    <el-alert v-if="!canView" type="warning" :closable="false">
      {{ $t('channel.officialAccount.permissionDenied') }}
    </el-alert>
    <el-form
      v-else
      ref="formRef"
      :model="form"
      :rules="rules"
      label-position="top"
      style="max-width: 560px; margin-top: 16px"
    >
      <el-alert
        type="info"
        show-icon
        :closable="false"
        style="margin-bottom: 16px"
      >
        {{ $t('channel.openPlatform.notice') }}
      </el-alert>
      <el-form-item prop="app_id" label="AppID">
        <el-input
          v-model="form.app_id"
          :maxlength="128"
          :placeholder="$t('channel.field.appid.placeholder')"
        />
      </el-form-item>
      <el-form-item prop="app_secret" label="AppSecret">
        <el-input
          v-model="form.app_secret"
          type="password"
          show-password
          :maxlength="255"
          :placeholder="
            form.app_secret_configured
              ? $t('channel.officialAccount.secretMaskedPlaceholder')
              : $t('channel.field.secret.placeholder')
          "
        />
      </el-form-item>
      <el-form-item>
        <el-button
          v-permission="['peanut.oauth.open-platform.save']"
          type="primary"
          :loading="submitLoading"
          @click="handleSubmit"
        >
          {{ $t('channel.operation.save') }}
        </el-button>
      </el-form-item>
    </el-form>
  </div>
</template>

<script lang="ts" setup>
  import { computed, onMounted, reactive, ref } from 'vue';
  import { useI18n } from 'vue-i18n';
  import { ElMessage, type FormInstance } from 'element-plus';
  import { hasPermission } from '@/hooks/permission';
  import {
    getOpenPlatformConfig,
    saveOpenPlatformConfig,
    type OpenPlatformConfig,
    type OpenPlatformConfigForm,
  } from '@/modules/peanut-oauth/api';

  const { t } = useI18n();
  const canView = computed(() =>
    hasPermission('peanut.oauth.open-platform.config')
  );
  const loading = ref(false);
  const submitLoading = ref(false);
  const formRef = ref<FormInstance>();
  const form = reactive<OpenPlatformConfig>({
    app_id: '',
    app_secret: '',
    app_secret_configured: false,
  });
  const rules = {
    app_id: [{ required: true, message: t('channel.field.appid.required') }],
    app_secret: [
      { required: true, message: t('channel.field.secret.required') },
    ],
  };

  const fetchData = async () => {
    if (!canView.value) return;
    loading.value = true;
    try {
      const { data } = await getOpenPlatformConfig();
      Object.assign(form, data);
    } finally {
      loading.value = false;
    }
  };
  onMounted(fetchData);

  const handleSubmit = async () => {
    const valid = await formRef.value?.validate().catch(() => false);
    if (!valid) return;
    submitLoading.value = true;
    try {
      const data: OpenPlatformConfigForm = {
        app_id: form.app_id.trim(),
        app_secret: form.app_secret.trim(),
      };
      await saveOpenPlatformConfig(data);
      await fetchData();
      ElMessage.success(t('channel.tip.success'));
    } finally {
      submitLoading.value = false;
    }
  };
</script>

<style scoped>
  .channel-panel {
    min-height: 160px;
  }
</style>

<script lang="ts">
  export default { name: 'OpenPlatform' };
</script>
