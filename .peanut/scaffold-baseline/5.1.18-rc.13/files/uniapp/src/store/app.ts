import { defineStore } from 'pinia';
import { ref } from 'vue';
import type { ConfigData } from '@/api/index';
import { getConfig } from '@/api/index';
import { applyDecorationTheme } from '@/utils/decoration';

export const useAppStore = defineStore('app', () => {
  const config = ref<ConfigData | null>(null);
  let loadingPromise: Promise<ConfigData> | null = null;

  async function loadConfig() {
    if (config.value) {
      applyDecorationTheme(config.value.theme);
      return config.value;
    }
    if (loadingPromise) {
      return loadingPromise;
    }
    loadingPromise = getConfig()
      .then((data) => {
        config.value = data;
        applyDecorationTheme(data.theme);
        return data;
      })
      .catch((error) => {
        console.error('Failed to load config:', error);
        throw error;
      })
      .finally(() => {
        loadingPromise = null;
      });
    return loadingPromise;
  }

  return {
    config,
    loadConfig,
  };
});
