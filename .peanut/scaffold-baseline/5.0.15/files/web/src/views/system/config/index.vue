<template>
  <div class="container">
    <Breadcrumb :items="['menu.appSetting', 'menu.appSetting.website']" />
    <el-card class="general-card">
      <template #header>{{ $t('menu.appSetting.website') }}</template>
      <div v-loading="loading" style="width: 100%">
        <el-form
          ref="formRef"
          :model="form"
          :rules="rules"
          label-position="top"
          style="max-width: 560px"
        >
          <el-form-item
            v-for="field in fields"
            :key="field.key"
            :prop="field.key"
            :label="$t(`systemConfig.field.${field.key}`)"
          >
            <el-input
              v-model="form[field.key]"
              :type="field.multiline ? 'textarea' : 'text'"
              :rows="field.multiline ? 3 : undefined"
              :placeholder="$t('systemConfig.field.placeholder')"
            />
          </el-form-item>
          <el-form-item>
            <el-button
              v-permission="['peanut.settings.manage']"
              type="primary"
              :loading="submitLoading"
              @click="handleSubmit"
            >
              {{ $t('systemConfig.operation.save') }}
            </el-button>
          </el-form-item>
        </el-form>
      </div>
    </el-card>
  </div>
</template>

<script lang="ts" setup>
  import { reactive, ref } from 'vue';
  import { useI18n } from 'vue-i18n';
  import { ElMessage } from 'element-plus';
  import type { FormInstance } from 'element-plus';
  import useLoading from '@/hooks/loading';
  import { useBrandStore } from '@/store';
  import {
    getWebsiteConfig,
    saveWebsiteConfig,
    type WebsiteConfig,
  } from '@/api/system/config';

  const { t } = useI18n();
  const { loading, setLoading } = useLoading(true);
  const submitLoading = ref(false);
  const formRef = ref<FormInstance>();
  const brandStore = useBrandStore();

  const form = reactive<WebsiteConfig>({ ...brandStore.website });

  const fields: { key: keyof WebsiteConfig; multiline?: boolean }[] = [
    { key: 'name' },
    { key: 'web_logo' },
    { key: 'web_favicon' },
    { key: 'login_image' },
    { key: 'shop_name' },
    { key: 'shop_logo' },
    { key: 'pc_logo' },
    { key: 'pc_title' },
    { key: 'pc_ico' },
    { key: 'pc_desc', multiline: true },
    { key: 'pc_keywords', multiline: true },
    { key: 'h5_favicon' },
    { key: 'slogan', multiline: true },
    { key: 'copyright' },
    { key: 'official_url' },
    { key: 'github_url' },
  ];

  const rules = {
    name: [{ required: true, message: t('systemConfig.field.name.required') }],
    shop_name: [
      { required: true, message: t('systemConfig.field.shop_name.required') },
    ],
  };

  const fetchData = async () => {
    setLoading(true);
    try {
      const { data } = await getWebsiteConfig();
      Object.assign(form, data);
    } finally {
      setLoading(false);
    }
  };
  fetchData();

  const handleSubmit = async () => {
    const valid = await formRef.value?.validate().catch(() => false);
    if (!valid) return;
    submitLoading.value = true;
    try {
      const { data } = await saveWebsiteConfig({ ...form });
      brandStore.replace(data);
      ElMessage.success(t('systemConfig.tip.success'));
    } finally {
      submitLoading.value = false;
    }
  };
</script>

<script lang="ts">
  export default {
    name: 'SystemConfig',
  };
</script>

<style scoped lang="less">
  .container {
    padding: 0 20px 20px 20px;
  }
</style>
