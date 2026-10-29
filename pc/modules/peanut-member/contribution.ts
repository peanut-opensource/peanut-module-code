import {
  defineNuxtModule,
  createResolver,
  extendPages,
  addComponent,
  addLayout,
} from '@nuxt/kit';

export default defineNuxtModule({
  meta: { name: 'peanut-member' },
  setup(_options, nuxt) {
    const resolver = createResolver(import.meta.url);
    const slots: Record<string, string> = nuxt.options.appConfig.moduleSlots;
    addLayout({ src: resolver.resolve('./layouts/user.vue') }, 'user');
    addComponent({
      name: 'MemberNavigation',
      filePath: resolver.resolve('./components/MemberNavigation.client.vue'),
      global: true,
    });
    slots['member-navigation'] = 'MemberNavigation';
    extendPages((pages) => {
      pages.push({
        name: 'peanut-member-login',
        path: '/login',
        file: resolver.resolve('./pages/login.vue'),
      });
      pages.push({
        name: 'peanut-member-user-info',
        path: '/user/info',
        file: resolver.resolve('./pages/user/info.vue'),
      });
      pages.push({
        name: 'peanut-member-account-security',
        path: '/account/security',
        file: resolver.resolve('./pages/account/security.vue'),
      });
    });
  },
});
