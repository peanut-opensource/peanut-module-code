import type { ModuleFrontendContribution } from '@/module-contributions';
import { DEFAULT_LAYOUT } from '@/router/routes/base';

import localeMemberListEn from './views/list/locale/en-US';
import localeMemberTagEn from './views/tag/locale/en-US';
import localeFinanceAccountLogEn from './views/account-log/locale/en-US';
import localeMemberListCn from './views/list/locale/zh-CN';
import localeMemberTagCn from './views/tag/locale/zh-CN';
import localeFinanceAccountLogCn from './views/account-log/locale/zh-CN';

const contribution: ModuleFrontendContribution = {
  moduleKey: 'peanut.member',
  locales: {
    'en-US': {
      ...localeMemberListEn,
      ...localeMemberTagEn,
      ...localeFinanceAccountLogEn,
    },
    'zh-CN': {
      ...localeMemberListCn,
      ...localeMemberTagCn,
      ...localeFinanceAccountLogCn,
    },
  },
  routes: [
    {
      path: '/member',
      name: 'officialMemberRoot',
      component: DEFAULT_LAYOUT,
      meta: { requiresAuth: true, tenantModuleKey: 'peanut.member' },
      children: [
        {
          path: 'list',
          name: 'MemberList',
          component: () =>
            import('@/modules/peanut-member/views/list/index.vue'),
          meta: {
            locale: 'menu.member.list',
            requiresAuth: true,
            tenantModuleKey: 'peanut.member',
            requiredPermissions: 'peanut.member.list',
          },
        },
        {
          path: 'tag',
          name: 'MemberTag',
          component: () =>
            import('@/modules/peanut-member/views/tag/index.vue'),
          meta: {
            locale: 'menu.member.tag',
            requiresAuth: true,
            tenantModuleKey: 'peanut.member',
            requiredPermissions: 'peanut.member.tag.list',
          },
        },
      ],
    },
    {
      path: '/finance',
      name: 'officialMemberFinanceRoot',
      component: DEFAULT_LAYOUT,
      meta: { requiresAuth: true, tenantModuleKey: 'peanut.member' },
      children: [
        {
          path: 'account-log',
          name: 'FinanceAccountLog',
          component: () =>
            import('@/modules/peanut-member/views/account-log/index.vue'),
          meta: {
            locale: 'menu.finance.accountLog',
            requiresAuth: true,
            tenantModuleKey: 'peanut.member',
            requiredPermissions: 'peanut.member.account-log.list',
          },
        },
      ],
    },
  ],
};
export default contribution;
