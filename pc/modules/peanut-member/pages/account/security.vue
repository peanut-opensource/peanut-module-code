<template>
  <div class="bg-white rounded-xl shadow-sm p-8">
    <h2 class="text-xl font-bold text-gray-800 mb-6">账户安全</h2>

    <!-- Change password -->
    <div class="mb-8">
      <h3 class="text-base font-semibold text-gray-700 mb-4">修改密码</h3>
      <el-form
        :model="pwdForm"
        label-width="100px"
        size="large"
        @submit.prevent="handleChangePwd"
      >
        <el-form-item label="原密码">
          <el-input
            v-model="pwdForm.old_password"
            type="password"
            show-password
            placeholder="请输入原密码"
          />
        </el-form-item>
        <el-form-item label="新密码">
          <el-input
            v-model="pwdForm.new_password"
            type="password"
            show-password
            :placeholder="passwordHint"
          />
        </el-form-item>
        <el-form-item label="确认密码">
          <el-input
            v-model="pwdForm.new_password_confirm"
            type="password"
            show-password
            placeholder="再次输入新密码"
          />
        </el-form-item>
        <el-form-item>
          <el-button type="primary" native-type="submit" :loading="pwdLoading"
            >确认修改</el-button
          >
        </el-form-item>
      </el-form>
    </div>

    <el-divider />

    <!-- Bind mobile -->
    <div>
      <h3 class="text-base font-semibold text-gray-700 mb-4">绑定手机号</h3>
      <el-form
        :model="mobileForm"
        label-width="100px"
        size="large"
        @submit.prevent="handleBindMobile"
      >
        <el-form-item label="手机号">
          <el-input
            v-model="mobileForm.mobile"
            type="tel"
            placeholder="请输入手机号"
            maxlength="11"
          />
        </el-form-item>
        <el-form-item>
          <el-button
            type="primary"
            native-type="submit"
            :loading="mobileLoading"
            >立即绑定</el-button
          >
        </el-form-item>
      </el-form>
    </div>
  </div>
</template>

<script setup lang="ts">
  definePageMeta({ layout: 'user', middleware: 'auth' });

  const request = useRequest();
  const pwdLoading = ref(false);
  interface PasswordPolicy {
    minimum_length: number;
    maximum_length: number;
    length_unit: 'utf8_bytes';
  }
  const passwordPolicy = ref<PasswordPolicy | null>(null);
  const passwordPolicyError = ref('');
  const passwordHint = computed(() =>
    passwordPolicy.value
      ? `密码须为 ${passwordPolicy.value.minimum_length}～${passwordPolicy.value.maximum_length} 个 UTF-8 字节`
      : passwordPolicyError.value || '正在读取密码要求'
  );
  async function loadPasswordPolicy() {
    try {
      const policy = await request.get<PasswordPolicy>(
        'installapi/password-policy',
        undefined,
        false
      );
      if (
        !Number.isSafeInteger(policy?.minimum_length) ||
        !Number.isSafeInteger(policy?.maximum_length) ||
        policy.minimum_length < 1 ||
        policy.maximum_length < policy.minimum_length ||
        policy.length_unit !== 'utf8_bytes'
      ) {
        throw new Error('Invalid password policy');
      }
      passwordPolicy.value = policy;
      passwordPolicyError.value = '';
    } catch {
      passwordPolicy.value = null;
      passwordPolicyError.value = '无法读取密码要求，请重试';
    }
  }
  onMounted(loadPasswordPolicy);
  const mobileLoading = ref(false);

  const pwdForm = ref({
    old_password: '',
    new_password: '',
    new_password_confirm: '',
  });
  const mobileForm = ref({ mobile: '' });

  async function handleChangePwd() {
    if (!passwordPolicy.value) {
      await loadPasswordPolicy();
      if (!passwordPolicy.value)
        return ElMessage.error(passwordPolicyError.value);
    }
    if (!pwdForm.value.old_password || !pwdForm.value.new_password)
      return ElMessage.warning('请填写完整密码信息');
    if (pwdForm.value.new_password !== pwdForm.value.new_password_confirm)
      return ElMessage.warning('两次密码不一致');
    const length = new TextEncoder().encode(pwdForm.value.new_password).length;
    if (
      length < passwordPolicy.value.minimum_length ||
      length > passwordPolicy.value.maximum_length
    )
      return ElMessage.warning(passwordHint.value);
    pwdLoading.value = true;
    try {
      await request.post('api/user/changePassword', pwdForm.value);
      ElMessage.success('密码修改成功');
      pwdForm.value = {
        old_password: '',
        new_password: '',
        new_password_confirm: '',
      };
    } catch {
      // useRequest already reports the server message.
    } finally {
      pwdLoading.value = false;
    }
  }

  async function handleBindMobile() {
    if (!/^1\d{10}$/.test(mobileForm.value.mobile))
      return ElMessage.warning('请输入正确的手机号');
    mobileLoading.value = true;
    try {
      await request.post('api/user/bindMobile', mobileForm.value);
      ElMessage.success('绑定成功');
    } catch {
      // useRequest already reports the server message.
    } finally {
      mobileLoading.value = false;
    }
  }
</script>
