<template>
  <div class="bg-white rounded-xl shadow-sm p-8">
    <h2 class="text-xl font-bold text-gray-800 mb-6">个人资料</h2>

    <el-form
      :model="form"
      label-width="80px"
      size="large"
      @submit.prevent="handleSave"
    >
      <el-form-item label="头像">
        <el-avatar :size="80" :src="form.avatar" />
      </el-form-item>
      <el-form-item label="昵称">
        <el-input v-model="form.nickname" placeholder="请输入昵称" />
      </el-form-item>
      <el-form-item label="性别">
        <el-radio-group v-model="form.sex">
          <el-radio :label="0">未知</el-radio>
          <el-radio :label="1">男</el-radio>
          <el-radio :label="2">女</el-radio>
        </el-radio-group>
      </el-form-item>
      <el-form-item label="生日">
        <el-date-picker
          v-model="form.birthday"
          type="date"
          placeholder="选择生日"
          value-format="YYYY-MM-DD"
        />
      </el-form-item>
      <el-form-item label="邮箱">
        <el-input v-model="form.email" type="email" placeholder="请输入邮箱" />
      </el-form-item>
      <el-form-item>
        <el-button type="primary" native-type="submit" :loading="loading"
          >保存修改</el-button
        >
      </el-form-item>
    </el-form>
  </div>
</template>

<script setup lang="ts">
  definePageMeta({ layout: 'user', middleware: 'auth' });

  const userStore = useUserStore();
  const request = useRequest();
  const loading = ref(false);

  interface UserInfo {
    id: number;
    nickname: string;
    avatar: string;
    sex: number;
    birthday: string;
    email: string;
    mobile: string;
  }

  const data = await request.get<UserInfo>('api/user/info');

  const form = ref({
    nickname: data?.nickname || '',
    avatar: data?.avatar || '',
    sex: data?.sex || 0,
    birthday: data?.birthday || '',
    email: data?.email || '',
  });

  async function handleSave() {
    loading.value = true;
    try {
      await request.post('api/user/setInfo', { ...form.value });
      userStore.setUserInfo({
        nickname: form.value.nickname,
        avatar: form.value.avatar,
      });
      ElMessage.success('保存成功');
    } catch {
      // useRequest already reports the server message.
    } finally {
      loading.value = false;
    }
  }
</script>
