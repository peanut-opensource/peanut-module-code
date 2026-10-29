<template>
  <div class="container">
    <Breadcrumb :items="['menu.user', 'menu.user.setting']" />
    <el-card class="general-card">
      <template #header>{{ $t('menu.user.setting') }}</template>
      <el-tabs model-value="1">
        <el-tab-pane name="1" :label="$t('userSetting.tab.basicInformation')">
          <div v-loading="loading" class="form-loading">
            <el-form
              ref="basicRef"
              :model="basicForm"
              :rules="basicRules"
              label-position="top"
              style="max-width: 480px; margin-top: 8px"
            >
              <el-form-item
                prop="avatar"
                :label="$t('userSetting.label.avatar')"
              >
                <el-upload
                  :http-request="
                    (options: UploadRequestOptions) => uploadFile(10, options)
                  "
                  :show-file-list="false"
                  accept="image/*"
                  :on-success="onAvatarSuccess"
                >
                  <el-avatar :size="88" shape="square" :src="avatarUrl">
                    <el-icon><Plus /></el-icon>
                  </el-avatar>
                </el-upload>
              </el-form-item>
              <el-form-item
                prop="username"
                :label="$t('userSetting.label.name')"
              >
                <el-input v-model="username" disabled />
              </el-form-item>
              <el-form-item
                prop="nickname"
                :label="$t('userSetting.basicInfo.form.label.nickname')"
              >
                <el-input
                  v-model="basicForm.nickname"
                  :placeholder="
                    $t('userSetting.basicInfo.placeholder.nickname')
                  "
                />
              </el-form-item>
              <el-form-item>
                <el-button
                  type="primary"
                  :loading="basicLoading"
                  @click="saveBasic"
                >
                  {{ $t('userSetting.save') }}
                </el-button>
              </el-form-item>
            </el-form>
          </div>
        </el-tab-pane>
        <el-tab-pane name="2" :label="$t('userSetting.tab.securitySettings')">
          <el-alert
            v-if="userStore.demoMode"
            class="demo-password-alert"
            type="info"
            :closable="false"
            :title="$t('userSetting.security.demoDisabled')"
          />
          <el-form
            ref="pwdRef"
            :model="pwdForm"
            :rules="pwdRules"
            label-position="top"
            style="max-width: 480px; margin-top: 8px"
          >
            <el-form-item
              prop="password_old"
              :label="$t('userSetting.security.oldPassword')"
            >
              <el-input
                v-model="pwdForm.password_old"
                :disabled="userStore.demoMode"
                type="password"
                show-password
                :placeholder="
                  $t('userSetting.security.oldPassword.placeholder')
                "
              />
            </el-form-item>
            <el-form-item
              prop="password"
              :label="$t('userSetting.security.newPassword')"
            >
              <el-input
                v-model="pwdForm.password"
                :disabled="userStore.demoMode"
                type="password"
                show-password
                :placeholder="passwordHint"
              />
            </el-form-item>
            <el-form-item
              prop="password_confirm"
              :label="$t('userSetting.security.confirmPassword')"
            >
              <el-input
                v-model="pwdForm.password_confirm"
                :disabled="userStore.demoMode"
                type="password"
                show-password
                :placeholder="
                  $t('userSetting.security.confirmPassword.placeholder')
                "
              />
            </el-form-item>
            <el-form-item>
              <el-button
                type="primary"
                :loading="pwdLoading"
                :disabled="userStore.demoMode"
                @click="savePassword"
              >
                {{ $t('userSetting.save') }}
              </el-button>
            </el-form-item>
          </el-form>
        </el-tab-pane>
      </el-tabs>
    </el-card>
  </div>
</template>

<script lang="ts" setup>
  import { computed, reactive, ref } from 'vue';
  import { useI18n } from 'vue-i18n';
  import {
    ElMessage,
    type FormInstance,
    type FormRules,
    type UploadRequestOptions,
  } from 'element-plus';
  import { Plus } from '@element-plus/icons-vue';
  import useLoading from '@/hooks/loading';
  import { useUserStore } from '@/store';
  import {
    getPasswordPolicy,
    passwordWithinPolicy,
    type PasswordPolicy,
  } from '@/api/password-policy';
  import { uploadFile, type FileRecord } from '@/modules/peanut-file/api';
  import {
    getAdminSelf,
    editAdminSelf,
    type EditSelfForm,
  } from '@/api/system/admin';

  const { t } = useI18n();
  const userStore = useUserStore();
  const { loading, setLoading } = useLoading(true);

  const username = ref('');
  const basicRef = ref<FormInstance>();
  const basicLoading = ref(false);
  const basicForm = reactive<{
    nickname: string;
    avatar: string;
    avatarUrl: string;
  }>({ nickname: '', avatar: '', avatarUrl: '' });
  const avatarUrl = computed(() => basicForm.avatarUrl || basicForm.avatar);

  const basicRules: FormRules = {
    nickname: [
      {
        required: true,
        message: t('userSetting.form.error.nickname.required'),
      },
    ],
  };

  const pwdRef = ref<FormInstance>();
  const pwdLoading = ref(false);
  const passwordPolicy = ref<PasswordPolicy | null>(null);
  const passwordPolicyError = ref('');
  const passwordHint = computed(() =>
    passwordPolicy.value
      ? t('userSetting.security.error.length', {
          min: passwordPolicy.value.minimum_length,
          max: passwordPolicy.value.maximum_length,
        })
      : passwordPolicyError.value || t('userSetting.security.policyLoading')
  );
  async function loadPasswordPolicy() {
    try {
      passwordPolicy.value = await getPasswordPolicy();
      passwordPolicyError.value = '';
    } catch {
      passwordPolicy.value = null;
      passwordPolicyError.value = t('userSetting.security.policyUnavailable');
    }
  }
  loadPasswordPolicy();
  const pwdForm = reactive({
    password_old: '',
    password: '',
    password_confirm: '',
  });
  const pwdRules = computed<FormRules>(() => ({
    password_old: [
      { required: true, message: t('userSetting.security.error.oldRequired') },
    ],
    password: [
      { required: true, message: t('userSetting.security.error.newRequired') },
      {
        validator: (
          _rule: unknown,
          value: string,
          cb: (error?: Error) => void
        ) =>
          cb(
            passwordPolicy.value &&
              passwordWithinPolicy(value, passwordPolicy.value)
              ? undefined
              : new Error(passwordHint.value)
          ),
      },
    ],
    password_confirm: [
      {
        required: true,
        message: t('userSetting.security.error.confirmRequired'),
      },
      {
        validator: (
          _rule: unknown,
          value: string,
          cb: (error?: Error) => void
        ) => {
          if (value !== pwdForm.password) {
            cb(new Error(t('userSetting.security.error.mismatch')));
          } else {
            cb();
          }
        },
      },
    ],
  }));

  const fetchData = async () => {
    setLoading(true);
    try {
      const { data } = await getAdminSelf();
      username.value = data.username;
      basicForm.nickname = data.nickname;
      basicForm.avatar = data.avatar_uri;
      basicForm.avatarUrl = data.avatar;
    } finally {
      setLoading(false);
    }
  };
  fetchData();

  const onAvatarSuccess = (file: FileRecord) => {
    basicForm.avatar = file.uri;
    basicForm.avatarUrl = file.url;
    ElMessage.success(t('userSetting.avatar.uploadSuccess'));
  };

  const saveBasic = async () => {
    const valid = await basicRef.value?.validate().catch(() => false);
    if (!valid) return;
    basicLoading.value = true;
    try {
      const payload: EditSelfForm = {
        nickname: basicForm.nickname,
        avatar: basicForm.avatar,
      };
      await editAdminSelf(payload);
      const { data } = await getAdminSelf();
      basicForm.nickname = data.nickname;
      basicForm.avatar = data.avatar_uri;
      basicForm.avatarUrl = data.avatar;
      userStore.setInfo({
        name: data.nickname,
        avatar: data.avatar,
      });
      ElMessage.success(t('userSetting.saveSuccess'));
    } catch {
      // The shared interceptor displays the server's business error.
    } finally {
      basicLoading.value = false;
    }
  };

  const savePassword = async () => {
    if (!passwordPolicy.value) {
      await loadPasswordPolicy();
      if (!passwordPolicy.value) {
        ElMessage.error(passwordPolicyError.value);
        return;
      }
    }
    const valid = await pwdRef.value?.validate().catch(() => false);
    if (!valid) return;
    pwdLoading.value = true;
    try {
      const payload: EditSelfForm = {
        nickname: basicForm.nickname,
        avatar: basicForm.avatar,
        password: pwdForm.password,
        password_confirm: pwdForm.password_confirm,
        password_old: pwdForm.password_old,
      };
      await editAdminSelf(payload);
      ElMessage.success(t('userSetting.security.success'));
      pwdRef.value?.resetFields();
    } finally {
      pwdLoading.value = false;
    }
  };
</script>

<script lang="ts">
  export default {
    name: 'Setting',
  };
</script>

<style scoped lang="less">
  .container {
    padding: 0 20px 20px 20px;
  }

  .form-loading {
    min-height: 240px;
  }

  .demo-password-alert {
    max-width: 480px;
    margin: 8px 0 16px;
  }
</style>
