<template>
  <div class="max-w-6xl mx-auto px-6 py-10">
    <div class="flex gap-8">
      <!-- Sidebar -->
      <aside class="w-48 shrink-0">
        <nav class="bg-white rounded-xl shadow-sm p-4 sticky top-24">
          <NuxtLink
            to="/user/info"
            class="flex items-center gap-2 px-3 py-2 rounded-lg text-sm hover:bg-blue-50 hover:text-primary"
            active-class="bg-blue-50 text-primary font-medium"
            >个人资料</NuxtLink
          >
          <NuxtLink
            v-if="useModuleAvailable('peanut.article')"
            to="/user/collection"
            class="flex items-center gap-2 px-3 py-2 rounded-lg text-sm hover:bg-blue-50 hover:text-primary"
            active-class="bg-blue-50 text-primary font-medium"
            >我的收藏</NuxtLink
          >
          <NuxtLink
            to="/account/security"
            class="flex items-center gap-2 px-3 py-2 rounded-lg text-sm hover:bg-blue-50 hover:text-primary"
            active-class="bg-blue-50 text-primary font-medium"
            >账户安全</NuxtLink
          >
          <NuxtLink
            v-if="useModuleAvailable('peanut.payment')"
            to="/recharge"
            class="flex items-center gap-2 px-3 py-2 rounded-lg text-sm hover:bg-blue-50 hover:text-primary"
            active-class="bg-blue-50 text-primary font-medium"
            >账户充值</NuxtLink
          >
        </nav>
      </aside>

      <!-- Content -->
      <main class="flex-1">
        <slot />
      </main>
    </div>
  </div>
</template>
