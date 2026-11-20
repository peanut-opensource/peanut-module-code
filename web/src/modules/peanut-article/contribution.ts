import type { ModuleFrontendContribution } from '@/module-contributions';
import { DEFAULT_LAYOUT } from '@/router/routes/base';

import localeArticleCateEn from './views/cate/locale/en-US';
import localeArticleListEn from './views/list/locale/en-US';
import localeArticleCateCn from './views/cate/locale/zh-CN';
import localeArticleListCn from './views/list/locale/zh-CN';

const contribution: ModuleFrontendContribution = {
  moduleKey: 'peanut.article',
  locales: {
    'en-US': { ...localeArticleCateEn, ...localeArticleListEn },
    'zh-CN': { ...localeArticleCateCn, ...localeArticleListCn },
  },
  routes: [
    {
      path: '/article',
      name: 'article',
      component: DEFAULT_LAYOUT,
      meta: {
        locale: 'menu.article',
        requiresAuth: true,
        icon: 'icon-file',
        order: 5,
        tenantModuleKey: 'peanut.article',
        requiredPermissions: [
          'peanut.article.category.list',
          'peanut.article.list',
        ],
      },
      children: [
        {
          path: 'cate',
          name: 'ArticleCate',
          component: () =>
            import('@/modules/peanut-article/views/cate/index.vue'),
          meta: {
            locale: 'menu.article.cate',
            requiresAuth: true,
            tenantModuleKey: 'peanut.article',
            requiredPermissions: 'peanut.article.category.list',
          },
        },
        {
          path: 'list',
          name: 'ArticleList',
          component: () =>
            import('@/modules/peanut-article/views/list/index.vue'),
          meta: {
            locale: 'menu.article.list',
            requiresAuth: true,
            tenantModuleKey: 'peanut.article',
            requiredPermissions: 'peanut.article.list',
          },
        },
        {
          path: 'edit/:id',
          name: 'ArticleEdit',
          component: () =>
            import('@/modules/peanut-article/views/edit/index.vue'),
          meta: {
            locale: 'article.modal.edit',
            requiresAuth: true,
            hideInMenu: true,
            activeMenu: 'ArticleList',
            ignoreCache: true,
            tenantModuleKey: 'peanut.article',
            requiredPermissions: 'peanut.article.edit',
          },
        },
      ],
    },
  ],
};

export default contribution;
