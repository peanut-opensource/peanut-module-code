export default {
  moduleKey: 'peanut.article',
  pages: [
    {
      path: 'pages/news/news',
      style: {
        navigationBarTitleText: '资讯',
        navigationBarBackgroundColor: '#ffffff',
        navigationBarTextStyle: 'black',
      },
      component: './pages/news/news.vue',
    },
    {
      path: 'pages/news_detail/news_detail',
      style: {
        navigationBarTitleText: '文章详情',
        navigationBarBackgroundColor: '#ffffff',
        navigationBarTextStyle: 'black',
      },
      component: './pages/news_detail/news_detail.vue',
    },
    {
      path: 'pages/search/search',
      style: {
        navigationBarTitleText: '搜索',
        navigationBarBackgroundColor: '#ffffff',
        navigationBarTextStyle: 'black',
      },
      component: './pages/search/search.vue',
    },
    {
      path: 'pages/collection/collection',
      style: {
        navigationBarTitleText: '我的收藏',
        navigationBarBackgroundColor: '#ffffff',
        navigationBarTextStyle: 'black',
      },
      component: './pages/collection/collection.vue',
    },
  ],
  slots: {
    home: './components/HomeContent.vue',
  },
};
