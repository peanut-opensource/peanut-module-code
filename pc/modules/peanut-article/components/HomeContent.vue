<template>
  <div class="max-w-6xl mx-auto px-6 py-10">
    <section
      v-if="bannerItems.length"
      class="pc-banner mb-12"
      :style="bannerStyle"
    >
      <button
        v-for="item in bannerItems"
        :key="`${item.name}-${item.image}`"
        type="button"
        class="pc-banner-item"
        @click="executeDecorationLink(item.link)"
      >
        <img v-if="item.image" :src="item.image" :alt="item.name" />
        <span v-if="item.name" class="pc-banner-title">{{ item.name }}</span>
      </button>
    </section>
    <div
      v-else
      class="rounded-2xl bg-gradient-to-r from-blue-600 to-blue-400 text-white p-12 mb-12"
    >
      <h1 class="text-4xl font-bold mb-4">{{ config?.website?.shop_name }}</h1>
      <p class="text-blue-100 text-lg">探索最新资讯，了解更多精彩内容</p>
      <NuxtLink to="/information">
        <el-button class="mt-6" size="large">浏览全部资讯</el-button>
      </NuxtLink>
    </div>

    <!-- Latest articles -->
    <section>
      <div class="flex justify-between items-center mb-6">
        <h2 class="text-2xl font-bold text-gray-800">最新资讯</h2>
        <NuxtLink to="/information" class="text-primary text-sm hover:underline"
          >查看全部 →</NuxtLink
        >
      </div>

      <div v-if="indexPending" class="article-empty" role="status"
        >正在加载资讯…</div
      >
      <div v-else-if="indexError" class="article-empty" role="alert">
        <span>资讯加载失败，请重试。</span>
        <button type="button" @click="refreshIndex()">重新加载</button>
      </div>
      <div
        v-else-if="articles.length"
        class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6"
      >
        <ArticleCard v-for="item in articles" :key="item.id" :article="item" />
      </div>
      <div v-else class="article-empty" role="status">
        <span class="article-empty-title">暂未发布资讯</span>
        <span class="article-empty-copy">新的内容将在这里展示</span>
      </div>
    </section>
  </div>
</template>

<script setup lang="ts">
  import type { CSSProperties } from 'vue';
  import { getPcIndex } from '~/modules/peanut-article/api/article';

  const appStore = useAppStore();
  const config = computed(() => appStore.config);

  interface DecorationLink {
    target_type: 'shop' | 'article' | 'custom' | 'mini_program';
    target: string | number;
    query?: Record<string, unknown>;
  }

  interface DecorationItem {
    image: string;
    name: string;
    link: DecorationLink;
    is_show?: 0 | 1;
  }

  interface DecorationComponent {
    name: string;
    content: {
      enabled?: 0 | 1;
      data?: DecorationItem[];
      [key: string]: unknown;
    };
    styles?: Record<string, string | number>;
  }

  // Capture the request-scoped client during setup. Nuxt carries the successful
  // result in this app's payload, so hydration does not repeat the same request.
  // No process-global data cache or authenticated user response is introduced.
  const request = useRequest();
  const {
    data: indexData,
    pending: indexPending,
    error: indexError,
    refresh: refreshIndex,
  } = await useAsyncData('pc:public-index', () =>
    getPcIndex<{ data: DecorationComponent[] }>(request)
  );
  const articles = computed(
    () => indexData.value?.all || indexData.value?.article || []
  );
  const decorate = computed(() => indexData.value?.decorate);
  const bannerComponent = computed(() => {
    const list = decorate.value?.data;
    return Array.isArray(list)
      ? list.find((item) => item.name === 'pc-banner')
      : undefined;
  });
  const bannerItems = computed(() => {
    const component = bannerComponent.value;
    if (
      !component ||
      component.content.enabled === 0 ||
      !Array.isArray(component.content.data)
    )
      return [];
    return component.content.data.filter(
      (item) => item.is_show === undefined || item.is_show === 1
    );
  });
  const safeCssLength = (
    value: unknown,
    fallback: string,
    allowNegative = false
  ) => {
    const candidate = String(value ?? '');
    const pattern = allowNegative
      ? /^-?\d+(?:\.\d+)?(?:px|%)$/
      : /^\d+(?:\.\d+)?(?:px|%)$/;
    return pattern.test(candidate) ? candidate : fallback;
  };
  const bannerStyle = computed<CSSProperties>(() => {
    const styles = bannerComponent.value?.styles || {};
    return {
      position: styles.position === 'absolute' ? 'absolute' : 'relative',
      left: safeCssLength(styles.left, '0px', true),
      top: safeCssLength(styles.top, '0px', true),
      width: safeCssLength(styles.width, '100%'),
      height: safeCssLength(styles.height, '340px'),
    };
  });

  function executeDecorationLink(link: DecorationLink) {
    if (
      !link ||
      (typeof link.target !== 'string' && typeof link.target !== 'number')
    )
      return;
    const target = String(link.target);
    const shopRoutes: Record<string, string> = {
      home: '/',
      news: '/information',
      profile: '/user/info',
      settings: '/account/security',
      favorites: '/user/collection',
      customer_service: '/account/security',
      wallet: '/account/security',
      privacy: '/policy/privacy',
      service: '/policy/service',
    };
    if (link.target_type === 'shop') {
      const route = shopRoutes[target];
      if (route) navigateTo(route);
      return;
    }
    if (link.target_type === 'article') {
      navigateTo(`/information/detail/${encodeURIComponent(target)}`);
      return;
    }
    if (link.target_type === 'custom' && /^https?:\/\//i.test(target)) {
      window.location.assign(target);
      return;
    }
    // A mini-program target is intentionally not interpreted as a PC route.
    const webUrl =
      typeof link.query?.web_url === 'string' ? link.query.web_url : '';
    if (/^https?:\/\//i.test(webUrl)) window.location.assign(webUrl);
  }
</script>

<style scoped>
  .pc-banner {
    min-height: 260px;
    overflow: hidden;
    border-radius: 1rem;
    background: #f4f6f8;
  }
  .pc-banner-item {
    position: relative;
    display: block;
    width: 100%;
    border: 0;
    padding: 0;
    background: transparent;
    cursor: pointer;
  }
  .pc-banner-item img {
    display: block;
    width: 100%;
    max-height: 420px;
    object-fit: cover;
  }
  .pc-banner-title {
    position: absolute;
    left: 1.25rem;
    bottom: 1rem;
    color: white;
    text-shadow: 0 1px 4px rgb(0 0 0 / 45%);
  }
  .article-empty {
    display: flex;
    min-height: 148px;
    align-items: center;
    justify-content: center;
    flex-direction: column;
    gap: 0.5rem;
    border: 1px dashed #cbd5e1;
    border-radius: 0.75rem;
    background: #f8fafc;
    color: #475569;
  }
  .article-empty-title {
    font-size: 1rem;
    font-weight: 600;
  }
  .article-empty-copy {
    font-size: 0.875rem;
    color: #64748b;
  }
</style>
