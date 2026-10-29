<template>
  <view class="page">
    <view class="avatar-row" @click="chooseAvatar">
      <text class="label">头像</text>
      <view class="right">
        <image :src="form.avatar || '/static/avatar.png'" class="avatar" />
        <text class="arrow">›</text>
      </view>
    </view>

    <view class="row">
      <text class="label">昵称</text>
      <input
        v-model="form.nickname"
        class="row-input"
        placeholder="请输入昵称"
      />
    </view>

    <view class="row">
      <text class="label">性别</text>
      <picker :value="sexIndex" :range="sexOptions" @change="onSexChange">
        <view class="right">
          <text>{{ sexOptions[sexIndex] }}</text>
          <text class="arrow">›</text>
        </view>
      </picker>
    </view>

    <view class="row">
      <text class="label">生日</text>
      <picker mode="date" :value="form.birthday" @change="onBirthdayChange">
        <view class="right">
          <text>{{ form.birthday || '请选择' }}</text>
          <text class="arrow">›</text>
        </view>
      </picker>
    </view>

    <view class="row">
      <text class="label">邮箱</text>
      <input
        v-model="form.email"
        class="row-input"
        placeholder="请输入邮箱"
        type="email"
      />
    </view>

    <view class="btn-area">
      <button class="btn-primary" :disabled="loading" @click="handleSave">
        {{ loading ? '保存中...' : '保存' }}
      </button>
    </view>
  </view>
</template>

<script setup lang="ts">
  import { ref, onMounted } from 'vue';
  import { useUserStore } from '@/store/user';
  import {
    getUserInfo,
    setUserInfo,
    type SetInfoParams,
  } from '@/modules/peanut-member/api/user';
  import { uploadImage } from '@/utils/request';

  const userStore = useUserStore();
  const loading = ref(false);
  const sexOptions = ['未知', '男', '女'];
  const sexIndex = ref(0);

  const form = ref({ nickname: '', avatar: '', birthday: '', email: '' });
  const initialForm = ref({
    nickname: '',
    avatar: '',
    birthday: '',
    email: '',
  });
  const initialSexIndex = ref(0);

  onMounted(async () => {
    try {
      const info = await getUserInfo();
      form.value = {
        nickname: info.nickname,
        avatar: info.avatar,
        birthday: info.birthday,
        email: info.email,
      };
      initialForm.value = { ...form.value };
      sexIndex.value = info.sex || 0;
      initialSexIndex.value = sexIndex.value;
    } catch (error) {
      console.error('Failed to load user info:', error);
    }
  });

  function pickerValue(event: unknown): unknown {
    if (typeof event !== 'object' || event === null || !('detail' in event)) {
      throw new Error('PICKER_EVENT_INVALID');
    }
    const { detail } = event;
    if (typeof detail !== 'object' || detail === null || !('value' in detail)) {
      throw new Error('PICKER_EVENT_INVALID');
    }
    return detail.value;
  }

  function onSexChange(event: unknown) {
    const value = pickerValue(event);
    if (
      value !== 0 &&
      value !== 1 &&
      value !== 2 &&
      value !== '0' &&
      value !== '1' &&
      value !== '2'
    ) {
      throw new Error('PICKER_VALUE_INVALID');
    }
    sexIndex.value = Number(value);
  }
  function onBirthdayChange(event: unknown) {
    const value = pickerValue(event);
    if (typeof value !== 'string') throw new Error('PICKER_VALUE_INVALID');
    form.value.birthday = value;
  }

  function chooseAvatar() {
    uni.chooseImage({
      count: 1,
      success: async (res) => {
        const filePath = res.tempFilePaths[0];
        if (!filePath) return;
        loading.value = true;
        try {
          const uploaded = await uploadImage(filePath);
          form.value.avatar = uploaded.url;
        } catch (error) {
          uni.showToast({
            title: error instanceof Error ? error.message : '头像上传失败',
            icon: 'none',
          });
        } finally {
          loading.value = false;
        }
      },
      fail: () => uni.showToast({ title: '未能选择图片', icon: 'none' }),
    });
  }

  async function handleSave() {
    loading.value = true;
    try {
      const updates: SetInfoParams[] = [
        ...(form.value.nickname !== initialForm.value.nickname
          ? [{ field: 'nickname' as const, value: form.value.nickname }]
          : []),
        ...(form.value.avatar !== initialForm.value.avatar
          ? [{ field: 'avatar' as const, value: form.value.avatar }]
          : []),
        ...(sexIndex.value !== initialSexIndex.value
          ? [{ field: 'sex' as const, value: sexIndex.value }]
          : []),
        ...(form.value.birthday !== initialForm.value.birthday
          ? [{ field: 'birthday' as const, value: form.value.birthday }]
          : []),
        ...(form.value.email !== initialForm.value.email
          ? [{ field: 'email' as const, value: form.value.email }]
          : []),
      ];
      for (const update of updates) {
        await setUserInfo(update);
      }
      userStore.setUserInfo({
        nickname: form.value.nickname,
        avatar: form.value.avatar,
      });
      uni.showToast({ title: '保存成功' });
      setTimeout(() => uni.navigateBack(), 800);
    } catch (error) {
      uni.showToast({
        title: error instanceof Error ? error.message : '保存失败，请稍后重试',
        icon: 'none',
      });
    } finally {
      loading.value = false;
    }
  }
</script>

<style scoped>
  .page {
    background: #f5f5f5;
    min-height: 100vh;
  }
  .avatar-row,
  .row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    background: #fff;
    padding: 28rpx 32rpx;
    border-bottom: 1rpx solid #f5f5f5;
  }
  .label {
    font-size: 28rpx;
    color: #333;
  }
  .right {
    display: flex;
    align-items: center;
    gap: 16rpx;
  }
  .avatar {
    width: 80rpx;
    height: 80rpx;
    border-radius: 50%;
  }
  .arrow {
    font-size: 36rpx;
    color: #ccc;
  }
  .row-input {
    text-align: right;
    font-size: 28rpx;
    color: #666;
  }
  .btn-area {
    padding: 60rpx 40rpx;
  }
  .btn-primary {
    width: 100%;
    height: 90rpx;
    background: #2979ff;
    color: #fff;
    font-size: 32rpx;
    border-radius: 45rpx;
    border: none;
  }
  .btn-primary[disabled] {
    opacity: 0.6;
  }
</style>
