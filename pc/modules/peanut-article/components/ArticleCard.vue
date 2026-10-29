<template>
  <div
    class="article-card bg-white rounded-xl overflow-hidden shadow-sm hover:shadow-md transition-shadow cursor-pointer"
    @click="$router.push(`/information/detail/${article.id}`)"
  >
    <img
      :src="article.image"
      :alt="article.title"
      class="w-full h-48 object-cover"
    />
    <div class="p-4">
      <div class="text-xs text-primary font-medium mb-2">{{
        article.cate_name
      }}</div>
      <h3
        class="font-semibold text-gray-800 text-sm leading-tight mb-2 line-clamp-2"
      >
        {{ article.title }}
      </h3>
      <p class="text-gray-400 text-xs line-clamp-2 mb-3">{{ article.desc }}</p>
      <div class="flex justify-between text-xs text-gray-400">
        <span>{{ article.author }}</span>
        <span>{{ article.click }} 次浏览</span>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
  import type { Article } from '~/modules/peanut-article/api/article';

  defineProps<{ article: Article }>();
</script>
