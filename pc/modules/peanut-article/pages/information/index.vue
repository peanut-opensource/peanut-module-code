<template>
  <div class="max-w-6xl mx-auto px-6 py-10">
    <!-- Category tabs -->
    <div class="flex gap-3 mb-8 flex-wrap">
      <el-tag
        :type="!currentCate ? 'primary' : 'info'"
        size="large"
        class="cursor-pointer"
        @click="switchCate(null)"
        >全部</el-tag
      >
      <el-tag
        v-for="cate in categories"
        :key="cate.id"
        :type="currentCate === cate.id ? 'primary' : 'info'"
        size="large"
        class="cursor-pointer"
        @click="switchCate(cate.id)"
        >{{ cate.name }}</el-tag
      >
    </div>

    <!-- Article grid -->
    <div
      v-if="articles.length"
      class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6"
    >
      <ArticleCard v-for="item in articles" :key="item.id" :article="item" />
    </div>
    <el-empty v-else description="暂无资讯" />

    <!-- Pagination -->
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
    type ArticleCategory,
  } from '~/modules/peanut-article/api/article';

  definePageMeta({ layout: 'default' });

  const request = useRequest();
  const currentCate = ref<number | null>(null);
  const pageNo = ref(1);
  const pageSize = 12;
  const articles = ref<Article[]>([]);
  const total = ref(0);

  const categories = ref<ArticleCategory[]>(
    await getArticleCategories(request)
  );

  await loadArticles();

  async function loadArticles() {
    const data = await getArticles(request, {
      cid: currentCate.value || undefined,
      pageNo: pageNo.value,
      pageSize,
    });
    articles.value = data?.lists || [];
    total.value = data?.count || 0;
  }

  function switchCate(id: number | null) {
    currentCate.value = id;
    pageNo.value = 1;
    loadArticles();
  }
</script>
