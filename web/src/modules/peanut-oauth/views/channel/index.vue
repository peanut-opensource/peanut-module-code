<template>
  <div class="container">
    <Breadcrumb :items="['menu.appSetting', 'menu.appSetting.channel']" />
    <el-card class="general-card">
      <template #header>{{ $t('menu.appSetting.channel') }}</template>
      <div v-loading="loading">
        <el-tabs model-value="official-account">
          <el-tab-pane
            name="official-account"
            :label="$t('channel.tab.wechatOa')"
          >
            <OfficialAccountConfig />
          </el-tab-pane>
          <el-tab-pane
            name="official-account-menu"
            :label="$t('channel.tab.wechatOaMenu')"
          >
            <OfficialAccountMenu />
          </el-tab-pane>
          <el-tab-pane
            name="official-account-reply"
            :label="$t('channel.tab.wechatOaReply')"
          >
            <OfficialAccountReply />
          </el-tab-pane>
          <el-tab-pane
            name="open-platform"
            :label="$t('channel.tab.wechatOpen')"
          >
            <OpenPlatform />
          </el-tab-pane>

          <!-- 既有 H5 网页渠道 -->
          <el-tab-pane name="h5" :label="$t('channel.tab.h5')">
            <el-form
              ref="webPageFormRef"
              :model="webPageForm"
              :rules="webPageRules"
              label-position="top"
              style="max-width: 640px; margin-top: 16px"
            >
              <el-form-item prop="status" :label="$t('channel.h5.status')">
                <el-switch
                  v-model="webPageForm.status"
                  :active-value="1"
                  :inactive-value="0"
                />
              </el-form-item>
              <el-form-item :label="$t('channel.h5.url')">
                <el-input :model-value="webPageForm.url" readonly>
                  <template #append
                    ><el-button @click="copyH5Url">
                      {{ $t('channel.operation.copy') }}
                    </el-button></template
                  >
                </el-input>
              </el-form-item>
              <template v-if="webPageForm.status === 0">
                <el-form-item
                  prop="page_status"
                  :label="$t('channel.h5.closedMode')"
                >
                  <el-radio-group v-model="webPageForm.page_status">
                    <el-radio :value="0">
                      {{ $t('channel.h5.closedMode.blank') }}
                    </el-radio>
                    <el-radio :value="1">
                      {{ $t('channel.h5.closedMode.redirect') }}
                    </el-radio>
                  </el-radio-group>
                </el-form-item>
                <el-form-item
                  v-if="webPageForm.page_status === 1"
                  prop="page_url"
                  :label="$t('channel.h5.redirectUrl')"
                >
                  <el-input
                    v-model="webPageForm.page_url"
                    :placeholder="$t('channel.h5.redirectUrl.placeholder')"
                  />
                </el-form-item>
              </template>
              <el-form-item>
                <el-button
                  v-permission="['peanut.oauth.web-page.save']"
                  type="primary"
                  :loading="webPageSubmitLoading"
                  @click="handleWebPageSubmit"
                >
                  {{ $t('channel.operation.save') }}
                </el-button>
              </el-form-item>
            </el-form>
          </el-tab-pane>

          <!-- 既有微信小程序渠道 -->
          <el-tab-pane name="wechat_mini" :label="$t('channel.tab.wechatMini')">
            <el-form
              ref="miniProgramFormRef"
              :model="miniProgramForm"
              :rules="miniProgramRules"
              label-position="top"
              style="max-width: 720px; margin-top: 16px"
            >
              <el-form-item prop="name" :label="$t('channel.mini.name')">
                <el-input
                  v-model="miniProgramForm.name"
                  :placeholder="$t('channel.mini.name.placeholder')"
                />
              </el-form-item>
              <el-form-item
                prop="original_id"
                :label="$t('channel.mini.originalId')"
              >
                <el-input
                  v-model="miniProgramForm.original_id"
                  :placeholder="$t('channel.mini.originalId.placeholder')"
                />
              </el-form-item>
              <el-form-item prop="qr_code" :label="$t('channel.mini.qrCode')">
                <el-space alignment="flex-end">
                  <el-image
                    v-if="miniProgramForm.qr_code"
                    :src="miniProgramForm.qr_code"
                    width="96"
                    height="96"
                    fit="cover"
                  />
                  <FilePicker
                    :type="10"
                    :limit="1"
                    :button-text="$t('channel.mini.qrCode.select')"
                    @select="onMiniQrSelected"
                  />
                  <el-button
                    v-if="miniProgramForm.qr_code"
                    type="danger"
                    @click="miniProgramForm.qr_code = ''"
                  >
                    {{ $t('channel.operation.clear') }}
                  </el-button>
                </el-space>
              </el-form-item>
              <el-form-item prop="app_id" :label="$t('channel.field.appid')">
                <el-input
                  v-model="miniProgramForm.app_id"
                  :placeholder="$t('channel.field.appid.placeholder')"
                />
              </el-form-item>
              <el-form-item
                prop="app_secret"
                :label="$t('channel.field.secret')"
              >
                <el-input
                  v-model="miniProgramForm.app_secret"
                  type="password"
                  show-password
                  :placeholder="$t('channel.field.secret.placeholder')"
                />
              </el-form-item>
              <el-divider content-position="left">
                {{ $t('channel.mini.domains') }}
              </el-divider>
              <el-form-item
                v-for="field in miniDomainFields"
                :key="field.key"
                :label="$t(field.label)"
              >
                <el-input :model-value="miniProgramForm[field.key]" readonly>
                  <template #append
                    ><el-button @click="copyText(miniProgramForm[field.key])">
                      {{ $t('channel.operation.copy') }}
                    </el-button></template
                  >
                </el-input>
              </el-form-item>
              <el-form-item>
                <el-button
                  v-permission="['peanut.oauth.mini-program.save']"
                  type="primary"
                  :loading="miniProgramSubmitLoading"
                  @click="handleMiniProgramSubmit"
                >
                  {{ $t('channel.operation.save') }}
                </el-button>
              </el-form-item>
            </el-form>
          </el-tab-pane>
        </el-tabs>
      </div>
    </el-card>
  </div>
</template>

<script lang="ts" setup>
  import { reactive, ref } from 'vue';
  import { useI18n } from 'vue-i18n';
  import { ElMessage, type FormInstance, type FormRules } from 'element-plus';
  import useLoading from '@/hooks/loading';
  import FilePicker from '@/components/file-picker/index.vue';
  import {
    getWebPageConfig,
    saveWebPageConfig,
    getMiniProgramConfig,
    saveMiniProgramConfig,
    type MiniProgramConfig,
    type WebPageConfig,
  } from '@/modules/peanut-oauth/api';
  import OfficialAccountConfig from './OfficialAccountConfig.vue';
  import OfficialAccountMenu from './OfficialAccountMenu.vue';
  import OfficialAccountReply from './OfficialAccountReply.vue';
  import OpenPlatform from './OpenPlatform.vue';

  const { t } = useI18n();
  const { loading, setLoading } = useLoading(true);
  const webPageSubmitLoading = ref(false);
  const miniProgramSubmitLoading = ref(false);
  const webPageFormRef = ref<FormInstance>();
  const miniProgramFormRef = ref<FormInstance>();

  const webPageForm = reactive<WebPageConfig>({
    status: 1,
    page_status: 0,
    page_url: '',
    url: '',
  });
  const webPageRules: FormRules = {
    status: [{ required: true }],
    page_status: [{ required: true }],
    page_url: [
      {
        validator: (
          _rule: unknown,
          value: string,
          callback: (error?: Error) => void
        ) => {
          if (webPageForm.status === 0 && webPageForm.page_status === 1) {
            try {
              const url = new URL(value);
              if (!['http:', 'https:'].includes(url.protocol))
                throw new Error();
            } catch {
              callback(new Error(t('channel.h5.redirectUrl.invalid')));
              return;
            }
          }
          callback();
        },
      },
    ],
  };

  const miniProgramForm = reactive<MiniProgramConfig>({
    name: '',
    original_id: '',
    qr_code: '',
    app_id: '',
    app_secret: '',
    request_domain: '',
    socket_domain: '',
    upload_file_domain: '',
    download_file_domain: '',
    udp_domain: '',
    business_domain: '',
  });
  const miniProgramRules: FormRules = {
    app_id: [{ required: true, message: t('channel.field.appid.required') }],
    app_secret: [
      { required: true, message: t('channel.field.secret.required') },
    ],
  };
  const miniDomainFields: Array<{
    key:
      | 'request_domain'
      | 'socket_domain'
      | 'upload_file_domain'
      | 'download_file_domain'
      | 'udp_domain'
      | 'business_domain';
    label: string;
  }> = [
    { key: 'request_domain', label: 'channel.mini.domain.request' },
    { key: 'socket_domain', label: 'channel.mini.domain.socket' },
    { key: 'upload_file_domain', label: 'channel.mini.domain.upload' },
    { key: 'download_file_domain', label: 'channel.mini.domain.download' },
    { key: 'udp_domain', label: 'channel.mini.domain.udp' },
    { key: 'business_domain', label: 'channel.mini.domain.business' },
  ];

  const fetchData = async () => {
    setLoading(true);
    try {
      const [webPageResult, miniProgramResult] = await Promise.all([
        getWebPageConfig(),
        getMiniProgramConfig(),
      ]);
      Object.assign(webPageForm, webPageResult.data);
      Object.assign(miniProgramForm, miniProgramResult.data);
    } finally {
      setLoading(false);
    }
  };
  fetchData();

  const handleWebPageSubmit = async () => {
    const valid = await webPageFormRef.value?.validate().catch(() => false);
    if (!valid) return;
    webPageSubmitLoading.value = true;
    try {
      await saveWebPageConfig({
        status: webPageForm.status,
        page_status: webPageForm.page_status,
        page_url: webPageForm.page_url.trim(),
      });
      const { data } = await getWebPageConfig();
      Object.assign(webPageForm, data);
      ElMessage.success(t('channel.tip.success'));
    } finally {
      webPageSubmitLoading.value = false;
    }
  };

  const handleMiniProgramSubmit = async () => {
    const valid = await miniProgramFormRef.value?.validate().catch(() => false);
    if (!valid) return;
    miniProgramSubmitLoading.value = true;
    try {
      await saveMiniProgramConfig({
        name: miniProgramForm.name.trim(),
        original_id: miniProgramForm.original_id.trim(),
        qr_code: miniProgramForm.qr_code,
        app_id: miniProgramForm.app_id.trim(),
        app_secret: miniProgramForm.app_secret.trim(),
      });
      const { data } = await getMiniProgramConfig();
      Object.assign(miniProgramForm, data);
      ElMessage.success(t('channel.tip.success'));
    } finally {
      miniProgramSubmitLoading.value = false;
    }
  };

  const onMiniQrSelected = (urls: string[]) => {
    miniProgramForm.qr_code = urls[0] || '';
  };

  const copyText = async (value: string) => {
    if (!value) return;
    await navigator.clipboard.writeText(value);
    ElMessage.success(t('channel.tip.copied'));
  };

  const copyH5Url = async () => copyText(webPageForm.url);
</script>

<script lang="ts">
  export default { name: 'AppSettingChannel' };
</script>

<style scoped lang="less">
  .container {
    padding: 0 20px 20px 20px;
  }
</style>
