import type { ModuleFrontendContribution } from '@/module-contributions';
import { DEFAULT_LAYOUT } from '@/router/routes/base';

import localeFinanceRechargeEn from './views/recharge/locale/en-US';
import localeFinanceRefundEn from './views/refund/locale/en-US';
import localeAppPayEn from './views/settings/locale/en-US';
import localeFinanceRechargeCn from './views/recharge/locale/zh-CN';
import localeFinanceRefundCn from './views/refund/locale/zh-CN';
import localeAppPayCn from './views/settings/locale/zh-CN';

const contribution: ModuleFrontendContribution = {
  moduleKey: 'peanut.payment',
  locales: {
    'en-US': {
      ...localeFinanceRechargeEn,
      ...localeFinanceRefundEn,
      ...localeAppPayEn,
    },
    'zh-CN': {
      ...localeFinanceRechargeCn,
      ...localeFinanceRefundCn,
      ...localeAppPayCn,
    },
  },
  routes: [
    {
      path: '/app-setting',
      name: 'officialPaymentSettingsRoot',
      component: DEFAULT_LAYOUT,
      meta: { requiresAuth: true, tenantModuleKey: 'peanut.payment' },
      children: [
        {
          path: 'pay',
          name: 'AppSettingPay',
          component: () =>
            import('@/modules/peanut-payment/views/settings/index.vue'),
          meta: {
            locale: 'menu.appSetting.pay',
            requiresAuth: true,
            tenantModuleKey: 'peanut.payment',
            requiredPermissions: 'peanut.payment.settings.detail',
          },
        },
      ],
    },
    {
      path: '/finance',
      name: 'officialPaymentFinanceRoot',
      component: DEFAULT_LAYOUT,
      meta: { requiresAuth: true, tenantModuleKey: 'peanut.payment' },
      children: [
        {
          path: 'recharge',
          name: 'FinanceRecharge',
          component: () =>
            import('@/modules/peanut-payment/views/recharge/index.vue'),
          meta: {
            locale: 'menu.finance.recharge',
            requiresAuth: true,
            tenantModuleKey: 'peanut.payment',
            requiredPermissions: 'peanut.payment.recharge.list',
          },
        },
        {
          path: 'refund',
          name: 'FinanceRefund',
          component: () =>
            import('@/modules/peanut-payment/views/refund/index.vue'),
          meta: {
            locale: 'menu.finance.refund',
            requiresAuth: true,
            tenantModuleKey: 'peanut.payment',
            requiredPermissions: 'peanut.payment.refund.list',
          },
        },
      ],
    },
  ],
};
export default contribution;
