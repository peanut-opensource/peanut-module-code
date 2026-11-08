<template>
  <div class="container">
    <div class="logo">
      <img
        alt="机构标识"
        :src="brandStore.website.web_logo"
        style="width: 32px; height: 32px"
      />
      <span class="logo-name">{{ brandStore.website.name }}</span>
    </div>
    <LoginBanner />
    <div class="content">
      <div class="content-inner">
        <LoginForm />
      </div>
      <div class="footer">
        <Footer />
      </div>
    </div>
  </div>
</template>

<script lang="ts" setup>
  import Footer from '@/components/footer/index.vue';
  import { useBrandStore } from '@/store';
  import LoginBanner from './components/banner.vue';
  import LoginForm from './components/login-form.vue';

  const brandStore = useBrandStore();
</script>

<style lang="less" scoped>
  .container {
    display: flex;
    min-height: 100vh;
    min-height: 100dvh;
    background: var(--el-bg-color-page);

    .banner {
      width: clamp(320px, 31vw, 480px);
      flex-shrink: 0;
      background: linear-gradient(163.85deg, #1d2129 0%, #00308f 100%);
    }

    .content {
      position: relative;
      display: flex;
      flex: 1;
      min-width: 0;
      align-items: center;
      justify-content: center;
      padding: 64px clamp(24px, 4vw, 72px) 80px;
    }

    .content-inner {
      width: 100%;
      max-width: 440px;
      border: 1px solid var(--el-border-color-lighter);
      border-radius: 16px;
      padding: 36px;
      background: var(--el-bg-color-overlay);
      box-shadow: var(--el-box-shadow-light);
    }

    .footer {
      position: absolute;
      right: 0;
      bottom: 8px;
      width: 100%;
    }
  }

  .logo {
    position: absolute;
    top: 24px;
    left: 24px;
    z-index: 1;
    display: inline-flex;
    align-items: center;
    gap: 10px;
  }

  .logo-name {
    display: none;
    min-width: 0;
    overflow: hidden;
    color: var(--el-text-color-primary);
    font-weight: 600;
    white-space: nowrap;
    text-overflow: ellipsis;
  }
</style>

<style lang="less" scoped>
  @media (max-width: 1024px) {
    .container {
      .banner {
        display: none;
      }
      .content {
        min-height: 100vh;
        min-height: 100dvh;
        padding: 90px 20px 56px;
      }
    }
    .logo-name {
      display: block;
    }
  }
  @media (max-width: 480px) {
    .container .content-inner {
      border: 0;
      padding: 24px 16px;
      background: transparent;
      box-shadow: none;
    }
  }
</style>
