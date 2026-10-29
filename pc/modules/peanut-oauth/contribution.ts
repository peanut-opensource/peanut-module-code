import {
  defineNuxtModule,
  createResolver,
  extendPages,
  addComponent,
} from '@nuxt/kit';

export default defineNuxtModule({
  meta: { name: 'peanut-oauth' },
  setup(_options, nuxt) {
    const resolver = createResolver(import.meta.url);
    const slots: Record<string, string> = nuxt.options.appConfig.moduleSlots;
    addComponent({
      name: 'OAuthLoginButton',
      filePath: resolver.resolve('./components/OAuthLoginButton.vue'),
      global: true,
    });
    slots['oauth-login'] = 'OAuthLoginButton';
    extendPages((pages) => {
      pages.push({
        name: 'peanut-oauth-oauth-callback',
        path: '/oauth/callback',
        file: resolver.resolve('./pages/oauth/callback.vue'),
      });
      pages.push({
        name: 'peanut-oauth-oauth-complete',
        path: '/oauth/complete',
        file: resolver.resolve('./pages/oauth/complete.vue'),
      });
    });
  },
});
