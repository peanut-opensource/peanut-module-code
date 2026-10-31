<template>
  <div class="container">
    <Breadcrumb :items="['menu.appSetting', 'menu.appSetting.channel']" />
    <el-card class="general-card">
      <template #header>{{ $t('menu.appSetting.channel') }}</template>
      <div>
        <el-tabs v-model="activeTab">
          <el-tab-pane
            v-if="canView('official-account')"
            lazy
            name="official-account"
            :label="$t('channel.tab.wechatOa')"
          >
            <OfficialAccountConfig />
          </el-tab-pane>
          <el-tab-pane
            v-if="canView('official-account-menu')"
            lazy
            name="official-account-menu"
            :label="$t('channel.tab.wechatOaMenu')"
          >
            <OfficialAccountMenu />
          </el-tab-pane>
          <el-tab-pane
            v-if="canView('official-account-reply')"
            lazy
            name="official-account-reply"
            :label="$t('channel.tab.wechatOaReply')"
          >
            <OfficialAccountReply />
          </el-tab-pane>
          <el-tab-pane
            v-if="canView('open-platform')"
            lazy
            name="open-platform"
            :label="$t('channel.tab.wechatOpen')"
          >
            <OpenPlatform />
          </el-tab-pane>

          <!-- 既有 H5 网页渠道 -->
          <el-tab-pane
            v-if="canView('h5')"
            lazy
            name="h5"
            :label="$t('channel.tab.h5')"
          >
            <el-alert
              v-if="configState.h5.failed"
              type="error"
              :closable="false"
            >
              {{ $t('channel.tip.loadFailed') }}
              <el-button
                :loading="configState.h5.loading"
                @click="fetchConfig('h5')"
              >
                {{ $t('channel.operation.retry') }}
              </el-button>
            </el-alert>
            <el-form
              v-loading="configState.h5.loading"
              ref="webPageFormRef"
              :disabled="!configState.h5.loaded || webPageSubmitLoading"
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
          <el-tab-pane
            v-if="canView('wechat_mini')"
            lazy
            name="wechat_mini"
            :label="$t('channel.tab.wechatMini')"
          >
            <el-alert
              v-if="configState.wechat_mini.failed"
              type="error"
              :closable="false"
            >
              {{ $t('channel.tip.loadFailed') }}
              <el-button
                :loading="configState.wechat_mini.loading"
                @click="fetchConfig('wechat_mini')"
              >
                {{ $t('channel.operation.retry') }}
              </el-button>
            </el-alert>
            <el-form
              v-loading="configState.wechat_mini.loading"
              ref="miniProgramFormRef"
              :disabled="
                !configState.wechat_mini.loaded || miniProgramSubmitLoading
              "
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
                  :placeholder="
                    miniProgramForm.app_secret_configured
                      ? $t('channel.officialAccount.secretMaskedPlaceholder')
                      : $t('channel.field.secret.placeholder')
                  "
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
  import { reactive, ref, watch } from 'vue';
  import { useRoute } from 'vue-router';
  import { useI18n } from 'vue-i18n';
  import { ElMessage, type FormInstance, type FormRules } from 'element-plus';
  import { hasPermission } from '@/hooks/permission';
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
  const route = useRoute();
  const channelPermissions = {
    'official-account': 'peanut.oauth.official-account.config',
    'official-account-menu': 'peanut.oauth.official-account.menu.detail',
    'official-account-reply': 'peanut.oauth.official-account.reply.list',
    'open-platform': 'peanut.oauth.open-platform.config',
    'h5': 'peanut.oauth.web-page.config',
    'wechat_mini': 'peanut.oauth.mini-program.config',
  };
  type ChannelTab = keyof typeof channelPermissions;
  const canView = (tab: ChannelTab) =>
    hasPermission(channelPermissions[tab], route.path);
  const activeTab = ref(
    (Object.keys(channelPermissions) as ChannelTab[]).find(canView) ?? ''
  );
  const configState = reactive({
    h5: { loading: false, loaded: false, failed: false },
    wechat_mini: { loading: false, loaded: false, failed: false },
  });
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
    app_secret_configured: false,
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

  const fetchConfig = async (tab: 'h5' | 'wechat_mini') => {
    const state = configState[tab];
    if (!canView(tab) || state.loading) return;
    state.loading = true;
    state.failed = false;
    try {
      if (tab === 'h5') {
        const { data } = await getWebPageConfig();
        Object.assign(webPageForm, data);
      } else {
        const { data } = await getMiniProgramConfig();
        Object.assign(miniProgramForm, data);
      }
      state.loaded = true;
    } catch {
      // The request layer reports the server error; retain a local retry action.
      state.failed = true;
    } finally {
      state.loading = false;
    }
  };
  watch(
    activeTab,
    (tab) => {
      if ((tab === 'h5' || tab === 'wechat_mini') && !configState[tab].loaded) {
        void fetchConfig(tab);
      }
    },
    { immediate: true }
  );

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
