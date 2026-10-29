import { computed } from 'vue';
import { EChartsOption } from 'echarts';
import { useAppStore } from '@/store';

/** Derive chart options from the current application theme. */
export default function useChartOption(
  sourceOption: (isDark: boolean) => EChartsOption
) {
  const appStore = useAppStore();
  const isDark = computed(() => {
    return appStore.theme === 'dark';
  });
  const chartOption = computed<EChartsOption>(() => {
    return sourceOption(isDark.value);
  });
  return {
    chartOption,
  };
}
