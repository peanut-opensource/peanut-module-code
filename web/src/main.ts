import { createApp } from 'vue';
import ElementPlus from 'element-plus';
import 'element-plus/dist/index.css';
import 'element-plus/theme-chalk/dark/css-vars.css';
import globalComponents from '@/components';
import router from './router';
import store from './store';
import i18n from './locale';
import directive from './directive';
import App from './App.vue';
import '@/assets/style/global.less';
import '@/api/interceptor';

const app = createApp(App);

app.use(ElementPlus);

app.use(router);
app.use(store);
app.use(i18n);
app.use(globalComponents);
app.use(directive);

app.mount('#app');
