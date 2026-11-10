import { dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { defineConfig, loadEnv } from 'vite';
import uni from '@dcloudio/vite-plugin-uni';
import { prepareModulePages } from './scripts/module-pages.mjs';

const configDir = dirname(fileURLToPath(import.meta.url));

// https://vitejs.dev/config/
export default defineConfig(async ({ mode, command }) => {
  const environment = loadEnv(mode, configDir, '');
  const apiProxyTarget =
    environment.VITE_API_PROXY_TARGET || 'http://127.0.0.1:20180';
  return {
    base: '/mobile/',
    plugins: [await prepareModulePages({ mode, command }), uni()],
    define: {
      'import.meta.env.VITE_APP_BASE_URL': JSON.stringify(
        environment.VITE_APP_BASE_URL || ''
      ),
    },
    server: {
      host: environment.VITE_HOST || '127.0.0.1',
      port: Number(environment.VITE_PORT || 5174),
      strictPort: true,
      proxy: {
        '/installapi': {
          target: apiProxyTarget,
          changeOrigin: false,
        },
        '/api': {
          target: apiProxyTarget,
          changeOrigin: false,
        },
        '/brand': {
          target: apiProxyTarget,
          changeOrigin: false,
        },
        '/storage': {
          target: apiProxyTarget,
          changeOrigin: false,
        },
      },
    },
  };
});
