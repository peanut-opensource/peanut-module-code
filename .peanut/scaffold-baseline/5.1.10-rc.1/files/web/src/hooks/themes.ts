import { computed } from 'vue';
import { useAppStore } from '@/store';

export default function useThemes() {
  const appStore = useAppStore();
  const isDark = computed(() => {
    return appStore.shellAppearance.theme.mode === 'dark';
  });
  return {
    isDark,
  };
}
