import { existsSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { defineConfig, loadEnv, type Plugin } from 'vite';
import vue from '@vitejs/plugin-vue';

function standaloneOpsFallbackPlugin(rootDir: string): Plugin {
  const realContribution = resolve(
    rootDir,
    'src/modules/peanut-ops/contribution.ts'
  );
  const virtualId = '\0peanut-ops-standalone-fallback';
  return {
    name: 'peanut-ops-standalone-fallback',
    resolveId(source) {
      if (existsSync(realContribution)) {
        return null;
      }
      if (
        source === './modules/peanut-ops/contribution' ||
        source === '../modules/peanut-ops/contribution'
      ) {
        return virtualId;
      }
      return null;
    },
    load(id) {
      if (id !== virtualId) {
        return null;
      }
      return [
        "import { defineComponent, h } from 'vue';",
        "export const opsConsoleRuntimeKey = Symbol('peanut.ops-console.runtime');",
        'export function createOpsConsoleRuntime() {',
        '  return { dispose() {}, async load() {}, async loadLogs() {} };',
        '}',
        "export const OpsConsolePage = defineComponent({ name: 'OpsConsolePageFallback', setup() { return () => h('div', 'Ops module is not installed in standalone scaffold checkout.'); } });",
      ].join('\n');
    },
  };
}

export default defineConfig(({ mode }) => {
  // Native ESM location also works with Vite's module-runner config loader.
  const rootDir = dirname(fileURLToPath(import.meta.url));
  const environment = loadEnv(mode, rootDir, '');
  const allowedHosts = (environment.VITE_ALLOWED_HOSTS || '')
    .split(',')
    .map((host) => host.trim())
    .filter(Boolean);
  const apiProxyTarget =
    environment.VITE_API_PROXY_TARGET || 'http://127.0.0.1:20180';
  return {
    base: '/platform/',
    plugins: [standaloneOpsFallbackPlugin(rootDir), vue()],
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
