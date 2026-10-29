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
      style="max-width: 720px; margin-top: 16px"
    >
      <el-form-item prop="name" :label="$t('channel.officialAccount.name')">
        <el-input
          v-model="form.name"
          :maxlength="100"
          :placeholder="$t('channel.officialAccount.namePlaceholder')"
        />
      </el-form-item>
      <el-form-item
        prop="original_id"
        :label="$t('channel.officialAccount.originalId')"
      >
        <el-input
          v-model="form.original_id"
          :maxlength="100"
          :placeholder="$t('channel.officialAccount.originalIdPlaceholder')"
        />
      </el-form-item>
      <el-form-item
        prop="qr_code"
        :label="$t('channel.officialAccount.qrCode')"
      >
        <el-space alignment="flex-end">
          <el-image
            v-if="form.qr_code"
            :src="form.qr_code"
            width="96"
            height="96"
            fit="cover"
          />
          <FilePicker
            :type="10"
            :limit="1"
            :button-text="$t('channel.officialAccount.selectQrCode')"
            @select="onQrSelected"
          />
          <el-button
            v-if="form.qr_code"
            type="danger"
            @click="form.qr_code = ''"
          >
            {{ $t('channel.operation.clear') }}
          </el-button>
        </el-space>
      </el-form-item>
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
      <el-form-item prop="token" :label="$t('channel.officialAccount.token')">
        <el-input
          v-model="form.token"
          type="password"
          show-password
          :maxlength="255"
          :placeholder="
            form.token_configured
              ? $t('channel.officialAccount.secretMaskedPlaceholder')
              : $t('channel.field.secret.placeholder')
          "
        />
      </el-form-item>
      <el-divider content-position="left">
        {{ $t('channel.officialAccount.callback') }}
      </el-divider>
      <el-alert type="info" show-icon :closable="false">
        {{ $t('channel.officialAccount.plaintextNotice') }}
      </el-alert>
      <el-form-item :label="$t('channel.officialAccount.callbackUrl')">
        <el-input :model-value="form.url" readonly>
          <template #append
            ><el-button @click="copyText(form.url)">
              {{ $t('channel.operation.copy') }}
            </el-button></template
          >
        </el-input>
      </el-form-item>
      <el-form-item
        v-for="field in domainFields"
        :key="field.key"
        :label="$t(field.label)"
      >
        <el-input :model-value="form[field.key]" readonly />
      </el-form-item>
      <el-form-item>
        <el-button
          v-permission="['peanut.oauth.official-account.save']"
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
  import FilePicker from '@/components/file-picker/index.vue';
  import { hasPermission } from '@/hooks/permission';
  import {
    getOfficialAccountConfig,
    saveOfficialAccountConfig,
    type OfficialAccountConfig,
    type OfficialAccountConfigForm,
  } from '@/modules/peanut-oauth/api';

  const { t } = useI18n();
  const canView = computed(() =>
    hasPermission('peanut.oauth.official-account.config')
  );
  const loading = ref(false);
  const submitLoading = ref(false);
  const formRef = ref<FormInstance>();
  const form = reactive<OfficialAccountConfig>({
    name: '',
    original_id: '',
    qr_code: '',
    app_id: '',
    app_secret: '',
    app_secret_configured: false,
    url: '',
    token: '',
    token_configured: false,
    business_domain: '',
    js_secure_domain: '',
    web_auth_domain: '',
    callback_mode: 'plaintext',
  });

  const rules = {
    app_id: [{ required: true, message: t('channel.field.appid.required') }],
    app_secret: [
      { required: true, message: t('channel.field.secret.required') },
    ],
  };

  const domainFields: Array<{
    key: 'business_domain' | 'js_secure_domain' | 'web_auth_domain';
    label: string;
  }> = [
    {
      key: 'business_domain',
      label: 'channel.officialAccount.businessDomain',
    },
    {
      key: 'js_secure_domain',
      label: 'channel.officialAccount.jsSecureDomain',
    },
    {
      key: 'web_auth_domain',
      label: 'channel.officialAccount.webAuthDomain',
    },
  ];

  const fetchData = async () => {
    if (!canView.value) return;
    loading.value = true;
    try {
      const { data } = await getOfficialAccountConfig();
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
      const data: OfficialAccountConfigForm = {
        name: form.name.trim(),
        original_id: form.original_id.trim(),
        qr_code: form.qr_code,
        app_id: form.app_id.trim(),
        app_secret: form.app_secret.trim(),
        token: form.token.trim(),
      };
      await saveOfficialAccountConfig(data);
      await fetchData();
      ElMessage.success(t('channel.tip.success'));
    } finally {
      submitLoading.value = false;
    }
  };

  const onQrSelected = (urls: string[]) => {
    form.qr_code = urls[0] || '';
  };

  const copyText = async (value: string) => {
    if (!value) return;
    await navigator.clipboard.writeText(value);
    ElMessage.success(t('channel.tip.copied'));
  };
</script>

<style scoped>
  .channel-panel {
    min-height: 180px;
  }
</style>

<script lang="ts">
  export default { name: 'OfficialAccountConfig' };
</script>
