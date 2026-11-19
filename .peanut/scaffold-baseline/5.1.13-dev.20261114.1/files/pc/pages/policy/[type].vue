<template>
  <div class="max-w-4xl mx-auto px-6 py-10">
    <h1 class="text-2xl font-bold text-gray-800 mb-8">{{ pageTitle }}</h1>
    <el-empty v-if="pending" description="政策内容加载中..." />
    <el-empty v-else-if="error" description="政策内容暂不可用" />
    <div
      v-else-if="policy?.content"
      class="bg-white rounded-xl p-8 shadow-sm prose max-w-none text-gray-600 leading-relaxed"
      v-html="safePolicyContent"
    />
    <el-empty v-else description="暂未配置政策内容" />
  </div>
</template>

<script setup lang="ts">
  import sanitizeRichText from '~/utils/sanitize-rich-text';

  definePageMeta({ layout: 'default' });

  const route = useRoute();
  const type = route.params.type as string;
  const apiBase = useRuntimeConfig().public.apiBase;

  const { data, pending, error } = await useFetch<{
    code: number;
    data: { title: string; content: string };
  }>(`${apiBase}/api/index/policy?type=${type}`);
  const policy = computed(() => data.value?.data || null);
  const safePolicyContent = computed(() =>
    sanitizeRichText(policy.value?.content)
  );
  const pageTitle = computed(
    () =>
      policy.value?.title ||
      (type === 'privacy' ? '隐私政策' : type === 'service' ? '用户协议' : '政策')
  );

  useHead({ title: pageTitle });
</script>
