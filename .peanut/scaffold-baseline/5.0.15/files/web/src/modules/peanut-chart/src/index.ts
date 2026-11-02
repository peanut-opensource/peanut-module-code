import { use } from 'echarts/core';
import { CanvasRenderer } from 'echarts/renderers';
import {
  BarChart,
  LineChart,
  PieChart,
  RadarChart,
  ScatterChart,
  GaugeChart,
} from 'echarts/charts';
import {
  GridComponent,
  TooltipComponent,
  LegendComponent,
  DataZoomComponent,
  GraphicComponent,
  TitleComponent,
  DatasetComponent,
  AriaComponent,
} from 'echarts/components';

use([
  CanvasRenderer,
  BarChart,
  LineChart,
  PieChart,
  RadarChart,
  ScatterChart,
  GaugeChart,
  GridComponent,
  TooltipComponent,
  LegendComponent,
  DataZoomComponent,
  GraphicComponent,
  TitleComponent,
  DatasetComponent,
  AriaComponent,
]);

// Keep vue-echarts' props, events, exposed instance and lifecycle intact.
export { default as Chart, THEME_KEY } from 'vue-echarts';
export type { EChartsOption } from 'echarts';
