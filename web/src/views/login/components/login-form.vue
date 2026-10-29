<template>
  <div class="login-form-wrapper">
    <div class="login-form-title">{{ $t('login.form.title') }}</div>
    <div class="login-form-sub-title">{{ brandStore.website.slogan }}</div>
    <div class="login-form-error-msg">{{ errorMessage }}</div>
    <el-form
      ref="loginForm"
      :model="userInfo"
      class="login-form"
      label-position="top"
      @submit.prevent="handleSubmit"
    >
      <el-form-item
        prop="username"
        :rules="[
          { required: true, message: $t('login.form.userName.errMsg') },
          { type: 'email', message: $t('login.form.userName.formatErrMsg') },
        ]"
      >
        <el-input
          v-model="userInfo.username"
          :placeholder="$t('login.form.userName.placeholder')"
        >
          <template #prefix>
            <el-icon><User /></el-icon>
          </template>
        </el-input>
      </el-form-item>
      <el-form-item v-if="tenantChoices.length > 0" prop="tenantId">
        <el-select
          v-model="userInfo.tenantId"
          :placeholder="$t('login.form.tenant.placeholder')"
          style="width: 100%"
        >
          <el-option
            v-for="tenant in tenantChoices"
            :key="tenant.tenant_id"
            :label="tenant.tenant_name"
            :value="tenant.tenant_id"
          />
        </el-select>
      </el-form-item>
      <el-form-item
        prop="password"
        :rules="[{ required: true, message: $t('login.form.password.errMsg') }]"
      >
        <el-input
          v-model="userInfo.password"
          :placeholder="$t('login.form.password.placeholder')"
          type="password"
          clearable
          show-password
        >
          <template #prefix>
            <el-icon><Lock /></el-icon>
          </template>
        </el-input>
      </el-form-item>
      <div class="login-form-actions">
        <el-button
          class="login-form-button"
          type="primary"
          native-type="submit"
          :loading="loading"
        >
          {{ $t('login.form.login') }}
        </el-button>
      </div>
    </el-form>
  </div>
</template>

<script lang="ts" setup>
  import { ref, reactive, watch } from 'vue';
  import { useRouter } from 'vue-router';
  import { ElMessage, type FormInstance } from 'element-plus';
  import { Lock, User } from '@element-plus/icons-vue';
  import { useI18n } from 'vue-i18n';
  import { useBrandStore, useUserStore } from '@/store';
  import useLoading from '@/hooks/loading';
  import type { LoginData } from '@/api/user';
  import type { TenantChoice } from '@peanut-admin/vue';

  const router = useRouter();
  const { t } = useI18n();
  const errorMessage = ref('');
  const { loading, setLoading } = useLoading();
  const userStore = useUserStore();
  const brandStore = useBrandStore();
  const loginForm = ref<FormInstance>();

  const userInfo = reactive({
    username: '',
    password: '',
    tenantId: undefined as number | undefined,
    challengeToken: undefined as string | undefined,
  });
  const tenantChoices = ref<TenantChoice[]>([]);

  watch(
    () => brandStore.demo,
    (demo) => {
      if (!demo.enabled) return;
      if (!userInfo.username) userInfo.username = demo.email;
      if (!userInfo.password) userInfo.password = demo.password;
    },
    { immediate: true, deep: true }
  );

  const handleSubmit = async () => {
    if (loading.value) return;
    const valid = await loginForm.value?.validate().catch(() => false);
    if (!valid) return;
    setLoading(true);
    try {
      const outcome = await userStore.login({ ...userInfo } as LoginData);
      if (
        outcome &&
        typeof outcome === 'object' &&
        'state' in outcome &&
        outcome.state === 'tenant_selection_required'
      ) {
        tenantChoices.value = outcome.tenants;
        userInfo.challengeToken = outcome.challenge_token;
        userInfo.tenantId = tenantChoices.value[0]?.tenant_id;
        return;
      }
      const { redirect, ...othersQuery } = router.currentRoute.value.query;
      const redirectRoute =
        typeof redirect === 'string' &&
        redirect !== 'login' &&
        router.hasRoute(redirect)
          ? redirect
          : 'Workplace';
      router.push({
        name: redirectRoute,
        query: {
          ...othersQuery,
        },
      });
      ElMessage.success(t('login.form.login.success'));
    } catch (err) {
      errorMessage.value = (err as Error).message;
    } finally {
      setLoading(false);
    }
  };
</script>

<style lang="less" scoped>
  .login-form {
    &-wrapper {
      width: 320px;
    }

    &-title {
      color: var(--el-text-color-primary);
      font-weight: 500;
      font-size: 24px;
      line-height: 32px;
    }

    &-sub-title {
      color: var(--el-text-color-secondary);
      font-size: 16px;
      line-height: 24px;
    }

    &-error-msg {
      height: 32px;
      color: var(--el-color-danger);
      line-height: 32px;
    }

    &-actions {
      display: flex;
      flex-direction: column;
      gap: 16px;
    }

    &-button {
      width: 100%;
      margin-left: 0;
    }
  }
</style>
