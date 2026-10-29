<template>
  <!-- Reuses /information/index layout filtered by source (category id) -->
  <div class="max-w-6xl mx-auto px-6 py-10">
    <div class="flex items-center gap-2 mb-6 text-sm text-gray-400">
      <NuxtLink to="/information" class="hover:text-primary">全部资讯</NuxtLink>
      <span>›</span>
      <span class="text-gray-700">{{ cateName }}</span>
    </div>

    <div
      v-if="articles.length"
      class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6"
    >
      <ArticleCard v-for="item in articles" :key="item.id" :article="item" />
    </div>
    <el-empty v-else description="该分类暂无资讯" />

    <div v-if="total > pageSize" class="flex justify-center mt-10">
      <el-pagination
        v-model:current-page="pageNo"
        :page-size="pageSize"
        :total="total"
        layout="prev, pager, next"
        @current-change="loadArticles"
      />
    </div>
  </div>
</template>

<script setup lang="ts">
  import {
    getArticleCategories,
    getArticles,
    type Article,
  } from '~/modules/peanut-article/api/article';

  definePageMeta({ layout: 'default' });

  const route = useRoute();
  const cateId = Number(route.params.source);
  const request = useRequest();

  const pageNo = ref(1);
  const pageSize = 12;
  const articles = ref<Article[]>([]);
  const total = ref(0);

  // Get category name from cate list
  const categories = await getArticleCategories(request);
  const cateName = computed(
    () =>
      categories.find((category) => category.id === cateId)?.name || '分类资讯'
  );

  await loadArticles();

  async function loadArticles() {
    const data = await getArticles(request, {
      cid: cateId,
      pageNo: pageNo.value,
      pageSize,
    });
    articles.value = data?.lists || [];
    total.value = data?.count || 0;
  }
</script>
