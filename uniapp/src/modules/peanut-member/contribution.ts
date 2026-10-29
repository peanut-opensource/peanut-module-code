export default {
  moduleKey: 'peanut.member',
  pages: [
    {
      path: 'pages/login/login',
      style: {
        navigationBarTitleText: '登录',
        navigationBarBackgroundColor: '#ffffff',
        navigationBarTextStyle: 'black',
      },
      component: './pages/login/login.vue',
    },
    {
      path: 'pages/register/register',
      style: {
        navigationBarTitleText: '注册',
        navigationBarBackgroundColor: '#ffffff',
        navigationBarTextStyle: 'black',
      },
      component: './pages/register/register.vue',
    },
    {
      path: 'pages/user/user',
      style: {
        navigationBarTitleText: '我的',
        navigationBarBackgroundColor: '#ffffff',
        navigationBarTextStyle: 'black',
      },
      component: './pages/user/user.vue',
    },
    {
      path: 'pages/user_data/user_data',
      style: {
        navigationBarTitleText: '个人资料',
        navigationBarBackgroundColor: '#ffffff',
        navigationBarTextStyle: 'black',
      },
      component: './pages/user_data/user_data.vue',
    },
    {
      path: 'pages/user_set/user_set',
      style: {
        navigationBarTitleText: '个人设置',
        navigationBarBackgroundColor: '#ffffff',
        navigationBarTextStyle: 'black',
      },
      component: './pages/user_set/user_set.vue',
    },
    {
      path: 'pages/change_password/change_password',
      style: {
        navigationBarTitleText: '修改密码',
        navigationBarBackgroundColor: '#ffffff',
        navigationBarTextStyle: 'black',
      },
      component: './pages/change_password/change_password.vue',
    },
    {
      path: 'pages/bind_mobile/bind_mobile',
      style: {
        navigationBarTitleText: '绑定手机号',
        navigationBarBackgroundColor: '#ffffff',
        navigationBarTextStyle: 'black',
      },
      component: './pages/bind_mobile/bind_mobile.vue',
    },
    {
      path: 'pages/forget_pwd/forget_pwd',
      style: {
        navigationBarTitleText: '找回密码',
        navigationBarBackgroundColor: '#ffffff',
        navigationBarTextStyle: 'black',
      },
      component: './pages/forget_pwd/forget_pwd.vue',
    },
    {
      path: 'packages/pages/user_wallet/user_wallet',
      style: {
        navigationBarTitleText: '我的钱包',
        navigationBarBackgroundColor: '#ffffff',
        navigationBarTextStyle: 'black',
      },
      component: './packages/pages/user_wallet/user_wallet.vue',
    },
  ],
  slots: {},
};
