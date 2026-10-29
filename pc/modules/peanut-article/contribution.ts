import {
  defineNuxtModule,
  createResolver,
  extendPages,
  addComponentsDir,
  addComponent,
} from '@nuxt/kit';

export default defineNuxtModule({
  meta: { name: 'peanut-article' },
  setup(_options, nuxt) {
    const resolver = createResolver(import.meta.url);
    const slots: Record<string, string> = nuxt.options.appConfig.moduleSlots;
    addComponentsDir({
      path: resolver.resolve('./components'),
      pathPrefix: false,
    });
    addComponent({
      name: 'ArticleHomeContent',
      filePath: resolver.resolve('./components/HomeContent.vue'),
      global: true,
    });
    slots['home'] = 'ArticleHomeContent';
    extendPages((pages) => {
      pages.push({
        name: 'peanut-article-information-index',
        path: '/information',
        file: resolver.resolve('./pages/information/index.vue'),
      });
      pages.push({
        name: 'peanut-article-information-source',
        path: '/information/:source',
        file: resolver.resolve('./pages/information/[source].vue'),
      });
      pages.push({
        name: 'peanut-article-information-detail-id',
        path: '/information/detail/:id',
        file: resolver.resolve('./pages/information/detail/[id].vue'),
      });
      pages.push({
        name: 'peanut-article-user-collection',
        path: '/user/collection',
        file: resolver.resolve('./pages/user/collection.vue'),
      });
    });
  },
});
