<template>
  <div class="banner" :style="bannerStyle">
    <div class="banner-inner">
      <div class="brand-message">
        <img class="brand-logo" :src="brandStore.website.web_logo" alt="" />
        <div class="brand-title">{{ brandStore.website.name }}</div>
        <div class="brand-slogan">{{ brandStore.website.slogan }}</div>
      </div>
    </div>
  </div>
</template>

<script lang="ts" setup>
  import { computed } from 'vue';
  import { useBrandStore } from '@/store';

  const brandStore = useBrandStore();
  const bannerStyle = computed(() => ({
    backgroundImage: `linear-gradient(145deg, rgb(9 30 73 / 82%), rgb(15 118 110 / 64%)), url(${brandStore.website.login_image})`,
  }));
</script>

<style lang="less" scoped>
  .banner {
    display: flex;
    align-items: center;
    justify-content: center;
    background-position: center;
    background-size: cover;

    &-inner {
      flex: 1;
      height: 100%;
    }
  }

  .brand-message {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    height: 100%;
    padding: 48px;
    color: #fff;
    text-align: center;
  }

  .brand-logo {
    width: 72px;
    height: 72px;
    margin-bottom: 24px;
  }

  .brand-title {
    font-weight: 600;
    font-size: 28px;
  }

  .brand-slogan {
    max-width: 380px;
    margin-top: 12px;
    color: rgb(255 255 255 / 78%);
    font-size: 15px;
    line-height: 1.7;
  }
</style>
