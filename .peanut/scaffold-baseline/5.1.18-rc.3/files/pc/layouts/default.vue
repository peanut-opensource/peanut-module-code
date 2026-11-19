<template>
  <div class="min-h-screen flex flex-col bg-gray-50">
    <!-- Header -->
    <header class="bg-white shadow-sm sticky top-0 z-50">
      <div
        class="max-w-6xl mx-auto px-6 h-16 flex items-center justify-between"
      >
        <NuxtLink to="/" class="flex items-center gap-3">
          <img
            :src="website.pc_logo"
            alt="logo"
            class="h-8 w-8 rounded object-contain"
          />
          <span class="text-lg font-bold text-gray-800">{{
            website.pc_title
          }}</span>
        </NuxtLink>

        <nav class="flex items-center gap-8">
          <NuxtLink
            to="/"
            class="text-gray-600 hover:text-primary transition-colors"
            >首页</NuxtLink
          >
          <NuxtLink
            v-if="useModuleAvailable('peanut.article')"
            to="/information"
            class="text-gray-600 hover:text-primary transition-colors"
            >资讯</NuxtLink
          >
          <NuxtLink
            to="/about"
            class="text-gray-600 hover:text-primary transition-colors"
            >关于我们</NuxtLink
          >
        </nav>

        <ClientOnly>
          <ModuleSlot name="member-navigation" />
        </ClientOnly>
      </div>
    </header>

    <!-- Main -->
    <main class="flex-1">
      <slot />
    </main>

    <!-- Footer -->
    <footer class="bg-white border-t border-gray-100 py-8 mt-12">
      <div class="max-w-6xl mx-auto px-6 text-center text-gray-400 text-sm">
        <p>© {{ new Date().getFullYear() }} {{ website.copyright }}</p>
        <div class="flex justify-center gap-6 mt-3">
          <NuxtLink
            to="/policy/privacy"
            class="hover:text-primary transition-colors"
            >隐私政策</NuxtLink
          >
          <NuxtLink
            to="/policy/service"
            class="hover:text-primary transition-colors"
            >用户协议</NuxtLink
          >
        </div>
      </div>
    </footer>
  </div>
</template>

<script setup lang="ts">
  const appStore = useAppStore();
  const website = computed(() => appStore.website);
</script>
