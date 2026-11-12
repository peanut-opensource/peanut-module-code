<template>
  <div>
    <NuxtLayout>
      <NuxtPage />
    </NuxtLayout>
  </div>
</template>

<script setup lang="ts">
  import { isPcPrivateRoute } from '~/utils/rendering-policy';

  const appStore = useAppStore();
  const route = useRoute();
  const website = computed(() => appStore.website);

  useHead({
    title: computed(() => website.value.pc_title),
    meta: [
      { name: 'description', content: computed(() => website.value.pc_desc) },
      { name: 'keywords', content: computed(() => website.value.pc_keywords) },
    ],
    link: [{ rel: 'icon', href: computed(() => website.value.pc_ico) }],
  });

  // HTTP headers do not run again on client-side navigation. Keep the private
  // page indexing hint in sync with the router as well; API authorization stays
  // on the backend and does not depend on this metadata.
  useHead(() => ({
    meta: isPcPrivateRoute(route.path)
      ? [{ name: 'robots', content: 'noindex, nofollow' }]
      : [],
  }));

  await appStore.loadConfig();
</script>
