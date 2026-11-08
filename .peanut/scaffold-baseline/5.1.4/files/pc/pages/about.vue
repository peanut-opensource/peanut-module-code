<template>
  <div class="max-w-4xl mx-auto px-6 py-12">
    <h1 class="text-3xl font-bold text-gray-800">关于我们</h1>
    <div class="mt-8 flex items-center gap-4">
      <img
        :src="website.pc_logo"
        :alt="website.pc_title"
        class="w-16 h-16 object-contain"
      />
      <p class="text-xl font-semibold text-gray-700">{{ website.pc_title }}</p>
    </div>
    <p class="mt-6 text-gray-600 leading-8">{{ website.pc_desc }}</p>
    <p v-if="website.slogan" class="mt-4 text-gray-600">{{ website.slogan }}</p>
    <p class="mt-8 text-gray-500">{{ website.copyright }}</p>
  </div>
</template>

<script setup lang="ts">
  definePageMeta({ layout: 'default' });
  const website = computed(() => useAppStore().website);
  useSeoMeta({
    title: () => `关于我们 - ${website.value.pc_title}`,
    description: () => website.value.pc_desc,
  });
</script>
