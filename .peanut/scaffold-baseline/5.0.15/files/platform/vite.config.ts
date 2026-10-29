import { dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { defineConfig, loadEnv } from 'vite';
import vue from '@vitejs/plugin-vue';

export default defineConfig(({ mode }) => {
  // Native ESM location also works with Vite's module-runner config loader.
  const environment = loadEnv(
    mode,
    dirname(fileURLToPath(import.meta.url)),
    ''
  );
  const allowedHosts = (environment.VITE_ALLOWED_HOSTS || '')
    .split(',')
    .map((host) => host.trim())
    .filter(Boolean);
  const apiProxyTarget =
    environment.VITE_API_PROXY_TARGET || 'http://127.0.0.1:20180';
  return {
    base: '/platform/',
    plugins: [vue()],
    resolve: { dedupe: ['vue', 'pinia', 'element-plus'] },
    define: {
      '__VUE_PROD_HYDRATION_MISMATCH_DETAILS__': false,
      'import.meta.env.VITE_API_BASE_URL': JSON.stringify(
        environment.VITE_API_BASE_URL || ''
      ),
    },
    server: {
      host: environment.VITE_HOST || '127.0.0.1',
      port: Number(environment.VITE_PORT || 5177),
      strictPort: true,
      open: environment.VITE_OPEN_BROWSER === 'true',
      allowedHosts,
      proxy: {
        '/platformapi': { target: apiProxyTarget, changeOrigin: false },
        '/adminapi/tenant/owner-invitations/': {
          target: apiProxyTarget,
          changeOrigin: false,
        },
        '/api': { target: apiProxyTarget, changeOrigin: false },
        '/favicon.ico': { target: apiProxyTarget, changeOrigin: false },
      },
    },
  };
});
