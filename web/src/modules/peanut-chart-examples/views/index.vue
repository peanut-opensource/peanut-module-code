<template>
  <PageContent class="chart-examples">
    <PageHeader>
      <h1>图表示例</h1>
      <p class="pa-page-header__description">
        静态演示数据，不代表业务统计。示例可独立安装和卸载。
      </p>
    </PageHeader>
    <el-space class="chart-examples__controls" wrap>
      <el-switch v-model="loading" active-text="加载状态" />
      <el-switch v-model="dark" active-text="深色主题" />
      <el-button :disabled="!lineChartReady || loading" @click="exportImage"
        >导出折线图 PNG</el-button
      >
      <span role="status">{{ interaction }}</span>
    </el-space>
    <el-row :gutter="16">
      <el-col
        v-for="example in examples"
        :key="example.title"
        :xs="24"
        :lg="12"
      >
        <el-card class="chart-examples__card">
          <template #header>{{ example.title }}</template>
          <Chart
            :ref="example.title === '折线图' ? setLineChart : undefined"
            :option="example.option"
            :theme="dark ? 'dark' : 'default'"
            :loading="loading"
            autoresize
            class="chart-examples__chart"
            @click="onChartClick"
            @rendered="example.title === '折线图' && (lineChartReady = false)"
            @finished="example.title === '折线图' && (lineChartReady = true)"
          />
        </el-card>
      </el-col>
    </el-row>
  </PageContent>
</template>

<script setup lang="ts">
  import { ref, shallowRef, watch, type ComponentPublicInstance } from 'vue';
  import { PageContent, PageHeader } from '@peanut-admin/ui-vue';
  import { Chart, type EChartsOption } from '@peanut-admin/peanut-chart/charts';
  import useThemes from '@/hooks/themes';

  const { isDark } = useThemes();
  const dark = ref(isDark.value);
  watch(isDark, (value) => {
    dark.value = value;
  });
  const loading = ref(false);
  const lineChartReady = ref(false);
  const interaction = ref('点击图形查看事件');
  const lineChart = shallowRef<InstanceType<typeof Chart> | null>(null);

  const setLineChart = (instance: Element | ComponentPublicInstance | null) => {
    lineChart.value = instance as InstanceType<typeof Chart> | null;
  };
  const onChartClick = (event: { name?: string; seriesName?: string }) => {
    interaction.value = `已点击：${event.seriesName || ''} ${event.name || ''}`;
  };
  const exportImage = () => {
    if (!lineChart.value || !lineChartReady.value || loading.value) return;
    const link = document.createElement('a');
    link.href = lineChart.value.getDataURL({ type: 'png', pixelRatio: 2 });
    link.download = 'chart-example.png';
    link.click();
  };

  const categories = ['周一', '周二', '周三', '周四', '周五'];
  const examples: { title: string; option: EChartsOption }[] = [
    {
      title: '折线图',
      option: {
        aria: { enabled: true },
        tooltip: { trigger: 'axis' },
        xAxis: { type: 'category', data: categories },
        yAxis: { type: 'value' },
        dataZoom: [{ type: 'inside' }, { type: 'slider' }],
        series: [
          {
            name: '演示访问量',
            type: 'line',
            smooth: true,
            data: [12, 19, 15, 26, 22],
          },
        ],
      },
    },
    {
      title: '堆叠柱状图',
      option: {
        aria: { enabled: true },
        tooltip: { trigger: 'axis' },
        legend: {},
        xAxis: { type: 'category', data: categories },
        yAxis: { type: 'value' },
        series: [
          {
            name: '演示 A',
            type: 'bar',
            stack: 'total',
            data: [8, 12, 10, 15, 13],
          },
          {
            name: '演示 B',
            type: 'bar',
            stack: 'total',
            data: [4, 7, 5, 11, 9],
          },
        ],
      },
    },
    {
      title: '环形图',
      option: {
        aria: { enabled: true },
        tooltip: { trigger: 'item' },
        legend: { bottom: 0 },
        series: [
          {
            name: '演示来源',
            type: 'pie',
            radius: ['40%', '65%'],
            data: [
              { name: '直接', value: 45 },
              { name: '搜索', value: 35 },
              { name: '其他', value: 20 },
            ],
          },
        ],
      },
    },
    {
      title: '雷达图',
      option: {
        aria: { enabled: true },
        tooltip: {},
        radar: {
          indicator: ['性能', '易用性', '扩展性', '维护性', '可访问性'].map(
            (name) => ({ name, max: 100 })
          ),
        },
        series: [
          {
            name: '演示指标',
            type: 'radar',
            data: [{ name: '静态示例', value: [80, 90, 75, 85, 70] }],
          },
        ],
      },
    },
    {
      title: '散点图',
      option: {
        aria: { enabled: true },
        tooltip: {},
        xAxis: { type: 'value', name: '演示 X' },
        yAxis: { type: 'value', name: '演示 Y' },
        series: [
          {
            name: '演示样本',
            type: 'scatter',
            symbolSize: 16,
            data: [
              [1, 4],
              [2, 7],
              [3, 5],
              [4, 9],
              [5, 8],
            ],
          },
        ],
      },
    },
    {
      title: '仪表盘',
      option: {
        aria: { enabled: true },
        series: [
          {
            name: '演示进度',
            type: 'gauge',
            progress: { show: true },
            detail: { formatter: '{value}%' },
            data: [{ name: '静态示例', value: 72 }],
          },
        ],
      },
    },
  ];
</script>

<style scoped>
  .chart-examples__controls {
    margin: 16px 0;
  }
  .chart-examples__card {
    margin-bottom: 16px;
  }
  .chart-examples__chart {
    width: 100%;
    height: 320px;
  }
</style>
