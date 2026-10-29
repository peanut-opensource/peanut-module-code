<template>
  <div class="container">
    <Breadcrumb :items="['menu.appSetting', 'menu.appSetting.user']" />
    <el-card v-loading="loading" class="general-card" header="用户默认资料">
      <el-form :model="user" label-position="top" class="form-width">
        <el-form-item label="默认头像">
          <FilePicker
            :type="10"
            :limit="1"
            button-text="选择头像"
            @select="(urls: string[]) => (user.default_avatar = urls[0] || '')"
          />
          <img
            v-if="user.default_avatar"
            :src="user.default_avatar"
            class="avatar"
            alt="默认头像"
          />
        </el-form-item>
        <el-button
          v-permission="['config/user/save']"
          type="primary"
          :loading="savingUser"
          @click="submitUser"
        >
          保存用户配置
        </el-button>
      </el-form>
    </el-card>

    <el-card
      v-loading="loading"
      class="general-card"
      header="登录配置"
      style="margin-top: 16px"
    >
      <el-form :model="login" label-position="top" class="form-width">
        <el-form-item label="登录方式">
          <el-checkbox-group v-model="login.login_way">
            <el-checkbox :value="1">账号密码</el-checkbox>
            <el-checkbox :value="2">手机验证码</el-checkbox>
          </el-checkbox-group>
        </el-form-item>
        <el-form-item label="登录协议">
          <el-switch
            v-model="login.login_agreement"
            :active-value="1"
            :inactive-value="0"
          />
        </el-form-item>
        <el-form-item label="第三方登录总开关">
          <el-switch
            v-model="login.third_auth"
            :active-value="1"
            :inactive-value="0"
          />
        </el-form-item>
        <el-form-item label="微信登录">
          <el-switch
            v-model="login.wechat_auth"
            :active-value="1"
            :inactive-value="0"
          />
        </el-form-item>
        <el-form-item label="微信首次登录强制绑定手机">
          <el-switch
            v-model="login.coerce_mobile"
            :active-value="1"
            :inactive-value="0"
          />
        </el-form-item>
        <el-button
          v-permission="['config/login/save']"
          type="primary"
          :loading="savingLogin"
          @click="submitLogin"
        >
          保存登录配置
        </el-button>
      </el-form>
    </el-card>
  </div>
</template>

<script setup lang="ts">
  import { reactive, ref } from 'vue';
  import { ElMessage } from 'element-plus';
  import FilePicker from '@/components/file-picker/index.vue';
  import useLoading from '@/hooks/loading';
  import {
    getLoginSetting,
    getUserSetting,
    saveLoginSetting,
    saveUserSetting,
    type LoginSetting,
    type UserSetting,
  } from '@/api/system-settings';

  const { loading, setLoading } = useLoading(true);
  const savingUser = ref(false);
  const savingLogin = ref(false);
  const user = reactive<UserSetting>({ default_avatar: '' });
  const login = reactive<LoginSetting>({
    login_way: [1, 2],
    coerce_mobile: 0,
    login_agreement: 0,
    third_auth: 0,
    wechat_auth: 0,
  });

  async function load() {
    setLoading(true);
    try {
      const [userResult, loginResult] = await Promise.all([
        getUserSetting(),
        getLoginSetting(),
      ]);
      Object.assign(user, userResult.data);
      Object.assign(login, loginResult.data);
    } finally {
      setLoading(false);
    }
  }
  load();

  async function submitUser() {
    savingUser.value = true;
    try {
      await saveUserSetting({ ...user });
      ElMessage.success('用户配置已保存');
    } finally {
      savingUser.value = false;
    }
  }

  async function submitLogin() {
    savingLogin.value = true;
    try {
      await saveLoginSetting({ ...login });
      ElMessage.success('登录配置已保存');
    } finally {
      savingLogin.value = false;
    }
  }
</script>

<style scoped lang="less">
  .container {
    padding: 0 20px 20px;
  }

  .form-width {
    max-width: 620px;
  }

  .avatar {
    width: 72px;
    height: 72px;
    margin-left: 12px;
    border-radius: 50%;
    object-fit: cover;
  }
</style>
