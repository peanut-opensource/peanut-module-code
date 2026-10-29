export default {
  moduleKey: 'peanut.payment',
  pages: [
    {
      path: 'packages/pages/recharge/recharge',
      style: {
        navigationBarTitleText: '账户充值',
        navigationBarBackgroundColor: '#ffffff',
        navigationBarTextStyle: 'black',
      },
      component: './packages/pages/recharge/recharge.vue',
    },
    {
      path: 'packages/pages/recharge_record/recharge_record',
      style: {
        navigationBarTitleText: '充值记录',
        navigationBarBackgroundColor: '#ffffff',
        navigationBarTextStyle: 'black',
      },
      component: './packages/pages/recharge_record/recharge_record.vue',
    },
  ],
  slots: {},
};
