import type { ModuleFrontendContribution } from '@/module-contributions';
import { DEFAULT_LAYOUT } from '@/router/routes/base';

import localeNoticeChannelEn from './views/channel/locale/en-US';
import localeNoticeTemplateEn from './views/template/locale/en-US';
import localeNoticeLogEn from './views/log/locale/en-US';
import localeNoticeChannelCn from './views/channel/locale/zh-CN';
import localeNoticeTemplateCn from './views/template/locale/zh-CN';
import localeNoticeLogCn from './views/log/locale/zh-CN';

const contribution: ModuleFrontendContribution = {
  moduleKey: 'peanut.notification',
  locales: {
    'en-US': {
      ...localeNoticeChannelEn,
      ...localeNoticeTemplateEn,
      ...localeNoticeLogEn,
    },
    'zh-CN': {
      ...localeNoticeChannelCn,
      ...localeNoticeTemplateCn,
      ...localeNoticeLogCn,
    },
  },
  routes: [
    {
      path: '/notice',
      name: 'officialNotificationRoot',
      component: DEFAULT_LAYOUT,
      meta: { requiresAuth: true, tenantModuleKey: 'peanut.notification' },
      children: [
        {
          path: 'channel',
          name: 'NoticeChannel',
          component: () =>
            import('@/modules/peanut-notification/views/channel/index.vue'),
          meta: {
            locale: 'menu.notice.channel',
            requiresAuth: true,
            tenantModuleKey: 'peanut.notification',
            requiredPermissions: 'peanut.notification.channel.detail',
          },
        },
        {
          path: 'template',
          name: 'NoticeTemplate',
          component: () =>
            import('@/modules/peanut-notification/views/template/index.vue'),
          meta: {
            locale: 'menu.notice.template',
            requiresAuth: true,
            tenantModuleKey: 'peanut.notification',
            requiredPermissions: 'peanut.notification.scene.list',
          },
        },
        {
          path: 'log',
          name: 'NoticeLog',
          component: () =>
            import('@/modules/peanut-notification/views/log/index.vue'),
          meta: {
            locale: 'menu.notice.log',
            requiresAuth: true,
            tenantModuleKey: 'peanut.notification',
            requiredPermissions: 'peanut.notification.log.list',
          },
        },
        {
          path: 'inbox',
          name: 'OfficialNotificationInbox',
          component: () => import('./NotificationInboxPage.vue'),
          meta: {
            locale: 'notificationInbox.title',
            requiresAuth: true,
            tenantModuleKey: 'peanut.notification',
            requiredPermissions: 'peanut.notification.inbox.read',
          },
        },
      ],
    },
  ],
};
export default contribution;
