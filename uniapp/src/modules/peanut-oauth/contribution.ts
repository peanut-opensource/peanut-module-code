export default {
  moduleKey: 'peanut.oauth',
  pages: [
    {
      path: 'pages/oauth/complete',
      style: {
        navigationBarTitleText: '完善资料',
        navigationBarBackgroundColor: '#ffffff',
        navigationBarTextStyle: 'black',
      },
      component: './pages/oauth/complete.vue',
    },
    {
      path: 'pages/oauth/callback',
      style: {
        navigationBarTitleText: '微信授权',
        navigationBarBackgroundColor: '#ffffff',
        navigationBarTextStyle: 'black',
      },
      component: './pages/oauth/callback.vue',
    },
  ],
  slots: {
    'oauth-login': './components/OAuthLoginButtons.vue',
    'oauth-binding': './components/OAuthBindButton.vue',
  },
};
