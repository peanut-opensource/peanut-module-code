<template>
  <el-button
    :type="collected ? 'primary' : 'default'"
    size="small"
    :icon="collected ? 'StarFilled' : 'Star'"
    :loading="pending"
    @click="toggleCollect"
    >{{ collected ? '已收藏' : '收藏' }}</el-button
  >
</template>

<script setup lang="ts">
  import {
    addArticleCollect,
    cancelArticleCollect,
    getArticleCollectState,
  } from '~/modules/peanut-article/api/article';

  const props = defineProps<{ articleId: number }>();
  const userStore = useUserStore();
  // 个人扩展的失效会话只清除个人态，保留匿名可阅读的正文。
  const request = useRequest({ redirectOnUnauthorized: false });
  const collected = ref(false);
  const pending = ref(false);
  let generation = 0;

  watch(
    () => [props.articleId, userStore.token] as const,
    async ([id, token], _previous, onCleanup) => {
      const current = ++generation;
      onCleanup(() => {
        generation++;
      });
      collected.value = false;
      pending.value = false;
      if (!token) return;
      pending.value = true;
      try {
        const state = await getArticleCollectState(request, id);
        if (current === generation) collected.value = state;
      } catch {
        // 收藏状态失败不影响公开正文；请求层保留错误提示和会话处理。
      } finally {
        if (current === generation) pending.value = false;
      }
    },
    { immediate: true }
  );

  async function toggleCollect() {
    if (!userStore.isLoggedIn) return navigateTo('/login');
    if (pending.value) return;
    const current = generation;
    pending.value = true;
    try {
      const update = collected.value ? cancelArticleCollect : addArticleCollect;
      await update(request, props.articleId);
      if (current === generation) collected.value = !collected.value;
    } catch {
      // 请求层报告业务错误，个人操作失败保留正文和原收藏状态。
    } finally {
      if (current === generation) pending.value = false;
    }
  }
</script>
