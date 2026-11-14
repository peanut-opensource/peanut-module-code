import { App } from 'vue';
import {
  ArrowRight,
  DataLine,
  Delete,
  Document,
  Download,
  Edit,
  Plus,
  Refresh,
  Search,
  Upload,
  VideoPlay,
} from '@element-plus/icons-vue';
import Breadcrumb from './breadcrumb/index.vue';

export default {
  install(Vue: App) {
    Vue.component('Breadcrumb', Breadcrumb);
    Vue.component('IconCode', DataLine);
    Vue.component('IconDelete', Delete);
    Vue.component('IconEdit', Edit);
    Vue.component('IconExport', Download);
    Vue.component('IconFile', Document);
    Vue.component('IconPlayCircle', VideoPlay);
    Vue.component('IconPlus', Plus);
    Vue.component('IconRefresh', Refresh);
    Vue.component('IconRight', ArrowRight);
    Vue.component('IconSearch', Search);
    Vue.component('IconUpload', Upload);
  },
};
