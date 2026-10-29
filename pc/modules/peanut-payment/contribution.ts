import { defineNuxtModule, createResolver, extendPages } from '@nuxt/kit';

export default defineNuxtModule({
  meta: { name: 'peanut-payment' },
  setup(_options, nuxt) {
    const resolver = createResolver(import.meta.url);
    extendPages((pages) => {
      pages.push({
        name: 'peanut-payment-recharge',
        path: '/recharge',
        file: resolver.resolve('./pages/recharge.vue'),
      });
    });
  },
});
