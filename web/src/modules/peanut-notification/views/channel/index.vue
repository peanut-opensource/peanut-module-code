<template>
  <div class="container">
    <el-card shadow="never">
      <!-- 短信渠道 -->
      <h3 class="section-title">
        {{ $t('notice.channel.sms') }}
        <el-tag
          :type="detail.status?.sms ? 'success' : 'danger'"
          style="margin-left: 8px; vertical-align: middle"
        >
          {{
            detail.status?.sms
              ? $t('notice.channel.status.enabled')
              : $t('notice.channel.status.disabled')
          }}
        </el-tag>
      </h3>

      <el-alert :closable="false" style="margin-bottom: 16px">
        {{ $t('notice.channel.sms.current') }}：
        {{ providerName(detail.sms_default) }}
      </el-alert>

      <el-tabs v-model="smsTab">
        <el-tab-pane name="aliyun" :label="$t('notice.channel.aliyun')">
          <el-form
            :model="aliyunForm"
            label-position="top"
            style="max-width: 520px"
          >
            <el-form-item :label="$t('notice.channel.enabled')">
              <el-switch
                v-model="aliyunForm.status"
                :active-value="1"
                :inactive-value="0"
              />
            </el-form-item>
            <el-form-item :label="$t('notice.channel.access_key_id')">
              <el-input v-model="aliyunForm.access_key_id" clearable />
            </el-form-item>
            <el-form-item :label="$t('notice.channel.access_key_secret')">
              <el-input
                v-model="aliyunForm.access_key_secret"
                type="password"
                clearable
                show-password
              />
            </el-form-item>
            <el-form-item :label="$t('notice.channel.sign_name')">
              <el-input v-model="aliyunForm.sign_name" clearable />
            </el-form-item>
            <el-form-item>
              <el-button
                v-permission="['peanut.notification.channel.save']"
                type="primary"
                :loading="saving.aliyun"
                @click="saveSection('sms_aliyun', aliyunForm)"
              >
                {{ $t('notice.channel.save') }}
              </el-button>
            </el-form-item>
          </el-form>
        </el-tab-pane>
        <el-tab-pane name="tencent" :label="$t('notice.channel.tencent')">
          <el-form
            :model="tencentForm"
            label-position="top"
            style="max-width: 520px"
          >
            <el-form-item :label="$t('notice.channel.enabled')">
              <el-switch
                v-model="tencentForm.status"
                :active-value="1"
                :inactive-value="0"
              />
            </el-form-item>
            <el-form-item :label="$t('notice.channel.secret_id')">
              <el-input v-model="tencentForm.secret_id" clearable />
            </el-form-item>
            <el-form-item :label="$t('notice.channel.secret_key')">
              <el-input
                v-model="tencentForm.secret_key"
                type="password"
                clearable
                show-password
              />
            </el-form-item>
            <el-form-item :label="$t('notice.channel.sdk_app_id')">
              <el-input v-model="tencentForm.sdk_app_id" clearable />
            </el-form-item>
            <el-form-item :label="$t('notice.channel.sign_name')">
              <el-input v-model="tencentForm.sign_name" clearable />
            </el-form-item>
            <el-form-item :label="$t('notice.channel.region')">
              <el-input v-model="tencentForm.region" clearable />
            </el-form-item>
            <el-form-item>
              <el-button
                v-permission="['peanut.notification.channel.save']"
                type="primary"
                :loading="saving.tencent"
                @click="saveSection('sms_tencent', tencentForm)"
              >
                {{ $t('notice.channel.save') }}
              </el-button>
            </el-form-item>
          </el-form>
        </el-tab-pane>
      </el-tabs>
    </el-card>
  </div>
</template>

<script lang="ts" setup>
  import { reactive, ref, onMounted } from 'vue';
  import { useI18n } from 'vue-i18n';
  import { ElMessage } from 'element-plus';
  import {
    getNoticeChannelDetail,
    saveNoticeChannel,
    NoticeChannelDetail,
    SmsAliyunConfig,
    SmsTencentConfig,
    ChannelSection,
  } from '@/modules/peanut-notification/api';

  const { t } = useI18n();

  const detail = ref<Partial<NoticeChannelDetail>>({});
  const smsTab = ref('aliyun');

  const aliyunForm = reactive<SmsAliyunConfig>({
    access_key_id: '',
    access_key_secret: '',
    sign_name: '',
    status: 0,
  });
  const tencentForm = reactive<SmsTencentConfig>({
    secret_id: '',
    secret_key: '',
    sdk_app_id: '',
    sign_name: '',
    region: 'ap-guangzhou',
    status: 0,
  });
  const saving = reactive({ aliyun: false, tencent: false });

  const fetchDetail = async () => {
    const { data } = await getNoticeChannelDetail();
    detail.value = data;
    Object.assign(aliyunForm, data.sms_aliyun);
    Object.assign(tencentForm, data.sms_tencent);
  };

  const providerName = (provider?: string) => {
    if (provider === 'aliyun') return t('notice.channel.aliyun');
    if (provider === 'tencent') return t('notice.channel.tencent');
    return t('notice.channel.none');
  };

  const sectionLoadingKey: Record<ChannelSection, keyof typeof saving> = {
    sms_default: 'aliyun',
    sms_aliyun: 'aliyun',
    sms_tencent: 'tencent',
  };

  const saveSection = async (
    section: ChannelSection,
    form: Record<string, unknown>
  ) => {
    const loadingKey = sectionLoadingKey[section];
    saving[loadingKey] = true;
    try {
      await saveNoticeChannel(section, { ...form });
      ElMessage.success(t('notice.channel.tip.success'));
      fetchDetail();
    } finally {
      saving[loadingKey] = false;
    }
  };

  onMounted(fetchDetail);
</script>

<style scoped lang="less">
  .container {
    padding: 0 20px 20px;
  }

  .section-title {
    margin: 0 0 16px;
    color: var(--el-text-color-primary);
    font-size: 16px;
    font-weight: 600;
  }
</style>
