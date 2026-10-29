<template>
  <div class="bg-white rounded-xl shadow-sm p-8">
    <h2 class="text-xl font-bold text-gray-800 mb-6">我的收藏</h2>

    <div v-if="articles.length" class="grid grid-cols-1 md:grid-cols-2 gap-6">
      <div
        v-for="item in articles"
        :key="item.id"
        class="flex gap-4 p-4 border border-gray-100 rounded-xl hover:border-primary/30 transition-colors cursor-pointer"
        @click="$router.push(`/information/detail/${item.id}`)"
      >
        <img
          :src="item.image"
          class="w-24 h-20 rounded-lg object-cover shrink-0"
        />
        <div class="flex-1 min-w-0">
          <div
            class="font-medium text-gray-800 text-sm leading-snug mb-1 line-clamp-2"
            >{{ item.title }}</div
          >
          <div class="text-gray-400 text-xs line-clamp-2">{{ item.desc }}</div>
        </div>
      </div>
    </div>
    <el-empty v-else description="暂无收藏" />

    <div v-if="total > pageSize" class="flex justify-center mt-8">
      <el-pagination
        v-model:current-page="pageNo"
        :page-size="pageSize"
        :total="total"
        layout="prev, pager, next"
        @current-change="loadCollections"
      />
    </div>
  </div>
</template>

<script setup lang="ts">
  import {
    getArticleCollections,
    type ArticleCollectionItem,
  } from '~/modules/peanut-article/api/article';

  definePageMeta({ layout: 'user', middleware: 'auth' });

  const request = useRequest();
  const pageNo = ref(1);
  const pageSize = 12;

  const articles = ref<ArticleCollectionItem[]>([]);
  const total = ref(0);

  await loadCollections();

  async function loadCollections() {
    const data = await getArticleCollections(request, pageNo.value, pageSize);
    articles.value = data?.lists || [];
    total.value = data?.count || 0;
  }
</script>
