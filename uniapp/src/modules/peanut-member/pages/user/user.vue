<template>
  <view class="user-page" :style="pageStyle">
    <view v-if="loading" class="request-state" role="status"
      >正在加载个人中心…</view
    >
    <view v-else-if="error" class="request-state" role="alert">
      <text>{{ error }}</text>
      <button size="mini" @click="loadProfile">重新加载</button>
    </view>
    <view
      class="page-meta"
      :class="metaTextClass"
      data-decoration-role="page-meta"
    >
      <image
        v-if="showTitleImage"
        :src="String(meta.title_img)"
        class="page-title-image"
        mode="heightFix"
      />
      <text v-else>{{ meta.title || '个人中心' }}</text>
    </view>

    <template
      v-for="decorationComponent in renderComponents"
      :key="decorationComponent.name"
    >
      <view
        v-if="decorationComponent.name === 'user-info'"
        data-decoration-component="user-info"
      >
        <view class="user-header" :style="headerStyle">
          <view v-if="isLoggedIn" class="user-info" @click="goUserData">
            <image
              :src="userInfo.avatar || '/static/avatar.png'"
              class="avatar"
            />
            <view class="info">
              <view class="nickname">{{
                userInfo.nickname || '未设置昵称'
              }}</view>
              <view class="mobile">{{ userInfo.mobile || '未绑定手机' }}</view>
            </view>
          </view>
          <view v-else class="login-prompt" @click="goLogin">点击登录</view>
        </view>
        <view v-if="isLoggedIn" class="wallet-card" @click="goWallet">
          <view class="wallet-item"
            ><view class="amount">{{ userInfo.balance || '0.00' }}</view
            ><view class="label">余额</view></view
          >
          <view class="wallet-item"
            ><view class="amount">{{ userInfo.points || 0 }}</view
            ><view class="label">积分</view></view
          >
        </view>
      </view>

      <view
        v-else-if="
          decorationComponent.name === 'user-banner' &&
          componentItems(decorationComponent).length
        "
        class="user-banner-list"
        data-decoration-component="user-banner"
      >
        <view
          v-for="item in componentItems(decorationComponent)"
          :key="`${item.name}-${item.image}`"
          class="user-banner"
          @click="executeDecorationLink(item.link)"
        >
          <image v-if="item.image" :src="item.image" mode="aspectFill" />
          <text v-if="item.name">{{ item.name }}</text>
        </view>
      </view>

      <view
        v-else-if="
          decorationComponent.name === 'my-service' &&
          componentItems(decorationComponent).length
        "
        class="service-card"
        :class="`service-style-${Number(
          decorationComponent.content.style || 1
        )}`"
        data-decoration-component="my-service"
      >
        <view class="service-title">{{
          decorationComponent.content.title || '我的服务'
        }}</view>
        <view class="service-grid">
          <view
            v-for="item in componentItems(decorationComponent)"
            :key="`${item.name}-${item.image}`"
            class="service-item"
            @click="executeDecorationLink(item.link)"
          >
            <image v-if="item.image" :src="item.image" mode="aspectFill" />
            <text>{{ item.name }}</text>
          </view>
        </view>
      </view>
    </template>

    <view class="menu-list">
      <view
        v-if="moduleAvailable('peanut.article')"
        class="menu-item"
        @click="goCollection"
        ><text class="menu-icon">⭐</text
        ><text class="menu-title">我的收藏</text></view
      >
      <view class="menu-item" @click="goSettings"
        ><text class="menu-icon">⚙️</text
        ><text class="menu-title">个人设置</text></view
      >
      <view class="menu-item" @click="goCustomerService"
        ><text class="menu-icon">💬</text
        ><text class="menu-title">联系客服</text></view
      >
      <view class="menu-item" @click="goAbout"
        ><text class="menu-icon">ℹ️</text
        ><text class="menu-title">关于我们</text></view
      >
    </view>
    <DecorationTabbar />
  </view>
</template>

<script setup lang="ts">
  import { computed, onScopeDispose, ref, watch } from 'vue';
  import { onHide, onShow, onUnload } from '@dcloudio/uni-app';
  import { getUserCenter } from '@/modules/peanut-member/api/user';
  import { useUserStore } from '@/store/user';
  import { useAppStore } from '@/store/app';
  import { moduleAvailable } from '@/module-contributions';
  import DecorationTabbar from '@/components/DecorationTabbar.vue';
  import {
    executeDecorationLink,
    applyDecorationPageMeta,
    getDecorationComponents,
    getDecorationItems,
    getDecorationMeta,
    getDecorationTheme,
    getMobileDecoration,
    isDecorationComponentEnabled,
    type DecorationComponent,
    type DecorationPage,
    type DecorationItem,
  } from '@/utils/decoration';

  const userStore = useUserStore();
  const appStore = useAppStore();
  const isLoggedIn = computed(() => userStore.isLoggedIn);
  const userInfo = computed(() => userStore.userInfo);
  const decorate = ref<DecorationPage | null>(null);
  const loading = ref(false);
  const error = ref('');
  let generation = 0;
  let disposed = false;

  function invalidateProfile() {
    generation += 1;
    loading.value = false;
  }

  function disposeProfile() {
    disposed = true;
    invalidateProfile();
  }

  // A logout followed by the same token must still invalidate the old request.
  watch(
    () => userStore.token,
    () => {
      invalidateProfile();
      decorate.value = null;
      error.value = '';
    },
    { flush: 'sync' }
  );
  onHide(invalidateProfile);
  onUnload(disposeProfile);
  onScopeDispose(disposeProfile);
  const theme = computed(() => getDecorationTheme(appStore.config?.theme));

  const renderComponents = computed(() =>
    getDecorationComponents(decorate.value).filter(isDecorationComponentEnabled)
  );
  const componentItems = (component: DecorationComponent) =>
    getDecorationItems(component).filter(
      (item) => item.is_show === undefined || item.is_show === 1
    );
  const meta = computed(() => getDecorationMeta(decorate.value));
  const showTitleImage = computed(
    () =>
      Number(meta.value.title_type) === 2 &&
      typeof meta.value.title_img === 'string' &&
      meta.value.title_img !== ''
  );
  const metaTextClass = computed(() =>
    Number(meta.value.text_color) === 2 ? 'page-meta-dark' : 'page-meta-light'
  );
  const pageStyle = computed(() => {
    const style: Record<string, string> = { backgroundColor: '#f5f5f5' };
    if (
      Number(meta.value.bg_type) === 1 &&
      typeof meta.value.bg_color === 'string'
    )
      style.backgroundColor = meta.value.bg_color;
    if (
      Number(meta.value.bg_type) === 2 &&
      typeof meta.value.bg_image === 'string' &&
      meta.value.bg_image
    )
      style.backgroundImage = `url(${meta.value.bg_image})`;
    return style;
  });
  const headerStyle = computed(() => ({
    background: `linear-gradient(135deg, ${
      theme.value?.themeColor1 || '#2979ff'
    }, ${theme.value?.themeColor2 || '#1d54c4'})`,
  }));

  async function loadProfile() {
    if (disposed) return;
    const requestGeneration = ++generation;
    const sessionToken = userStore.token;
    const isCurrent = () =>
      requestGeneration === generation && sessionToken === userStore.token;
    loading.value = true;
    error.value = '';
    try {
      const [page, center] = await Promise.all([
        getMobileDecoration(2),
        isLoggedIn.value ? getUserCenter() : Promise.resolve(null),
      ]);
      if (!isCurrent()) return;
      decorate.value = page;
      applyDecorationPageMeta(page);
      if (center) userStore.setUserInfo(center);
    } catch {
      if (!isCurrent()) return;
      error.value = '个人中心加载失败，请重试';
    } finally {
      if (isCurrent()) loading.value = false;
    }
  }
  onShow(loadProfile);

  function goLogin() {
    uni.navigateTo({ url: '/pages/login/login' });
  }
  function goUserData() {
    uni.navigateTo({ url: '/pages/user_data/user_data' });
  }
  function goWallet() {
    uni.navigateTo({ url: '/packages/pages/user_wallet/user_wallet' });
  }
  function goCollection() {
    uni.navigateTo({ url: '/pages/collection/collection' });
  }
  function goSettings() {
    uni.navigateTo({ url: '/pages/user_set/user_set' });
  }
  function goCustomerService() {
    uni.navigateTo({ url: '/pages/customer_service/customer_service' });
  }
  function goAbout() {
    uni.navigateTo({ url: '/pages/as_us/as_us' });
  }
</script>

<style scoped>
  .request-state {
    padding: 24rpx;
    text-align: center;
  }
  .user-page {
    min-height: 100vh;
    padding-bottom: calc(120rpx + env(safe-area-inset-bottom));
    background-position: center;
    background-size: cover;
    box-sizing: border-box;
  }
  .page-meta {
    display: flex;
    min-height: 88rpx;
    align-items: center;
    justify-content: center;
    padding: 12rpx 24rpx;
    background: v-bind('meta.bg_color || theme?.themeColor1 || "#2979ff"');
    font-size: 32rpx;
    font-weight: 600;
    box-sizing: border-box;
  }
  .page-meta-light {
    color: #fff;
  }
  .page-meta-dark {
    color: #000;
  }
  .page-title-image {
    height: 52rpx;
    max-width: 360rpx;
  }
  .user-header {
    padding: 60rpx 40rpx 40rpx;
  }
  .user-info {
    display: flex;
    align-items: center;
  }
  .avatar {
    width: 120rpx;
    height: 120rpx;
    border-radius: 50%;
    border: 4rpx solid rgb(255 255 255 / 50%);
  }
  .info {
    margin-left: 24rpx;
  }
  .nickname {
    font-size: 34rpx;
    font-weight: 600;
    color: #fff;
  }
  .mobile {
    font-size: 26rpx;
    color: rgb(255 255 255 / 80%);
    margin-top: 8rpx;
  }
  .login-prompt {
    color: #fff;
    font-size: 32rpx;
    font-weight: 600;
  }
  .wallet-card,
  .service-card,
  .menu-list {
    background: #fff;
    margin: 24rpx;
    border-radius: 16rpx;
    overflow: hidden;
  }
  .wallet-card {
    display: flex;
    padding: 30rpx;
  }
  .wallet-item {
    flex: 1;
    text-align: center;
  }
  .amount {
    font-size: 36rpx;
    font-weight: 600;
    color: #333;
  }
  .label {
    font-size: 24rpx;
    color: #999;
    margin-top: 8rpx;
  }
  .user-banner-list {
    margin: 24rpx;
  }
  .user-banner {
    position: relative;
    margin-bottom: 16rpx;
    border-radius: 12rpx;
    overflow: hidden;
    background: #eee;
  }
  .user-banner image {
    width: 100%;
    height: 180rpx;
    display: block;
  }
  .user-banner text {
    position: absolute;
    left: 20rpx;
    bottom: 16rpx;
    color: #fff;
    text-shadow: 0 1rpx 6rpx rgb(0 0 0 / 50%);
  }
  .service-title {
    padding: 24rpx 24rpx 8rpx;
    font-weight: 600;
    font-size: 30rpx;
  }
  .service-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 14rpx;
    padding: 18rpx 24rpx 24rpx;
  }
  .service-style-2 .service-grid {
    grid-template-columns: repeat(5, 1fr);
  }
  .service-style-2 .service-item image {
    border-radius: 50%;
  }
  .service-item {
    display: flex;
    flex-direction: column;
    align-items: center;
    color: #333;
    font-size: 24rpx;
  }
  .service-item image {
    width: 70rpx;
    height: 70rpx;
    border-radius: 10rpx;
    margin-bottom: 8rpx;
  }
  .menu-item {
    display: flex;
    align-items: center;
    padding: 30rpx 24rpx;
    border-bottom: 1rpx solid #f5f5f5;
  }
  .menu-icon {
    font-size: 36rpx;
    margin-right: 20rpx;
  }
  .menu-title {
    font-size: 28rpx;
    color: #333;
  }
</style>
