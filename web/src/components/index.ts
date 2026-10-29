import { App } from 'vue';
import { use } from 'echarts/core';
import { CanvasRenderer } from 'echarts/renderers';
import { BarChart, LineChart, PieChart, RadarChart } from 'echarts/charts';
import {
  GridComponent,
  TooltipComponent,
  LegendComponent,
  DataZoomComponent,
  GraphicComponent,
} from 'echarts/components';
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
import Chart from './chart/index.vue';
import Breadcrumb from './breadcrumb/index.vue';

// Manually introduce ECharts modules to reduce packing size

use([
  CanvasRenderer,
  BarChart,
  LineChart,
  PieChart,
  RadarChart,
  GridComponent,
  TooltipComponent,
  LegendComponent,
  DataZoomComponent,
  GraphicComponent,
]);

export default {
  install(Vue: App) {
    Vue.component('Chart', Chart);
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
