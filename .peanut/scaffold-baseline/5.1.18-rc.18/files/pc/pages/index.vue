<template>
  <main>
    <ModuleSlot name="home" />
    <section v-if="!hasHome" class="max-w-6xl mx-auto px-6 py-10">
      <h1 class="text-3xl font-bold">{{ appStore.website.pc_title }}</h1>
      <p class="mt-4 text-gray-600">{{ appStore.website.pc_desc }}</p>
    </section>
  </main>
</template>

<script setup lang="ts">
  const appStore = useAppStore();
  const hasHome = Boolean(useAppConfig().moduleSlots?.home);
</script>
