import { existsSync, readFileSync, realpathSync } from 'node:fs';
import { dirname, isAbsolute, relative, resolve, sep } from 'node:path';
import { fileURLToPath } from 'node:url';
import {
  defineConfig,
  loadEnv,
  type ConfigEnv,
  type OutputChunk,
  type Plugin,
  type UserConfig,
} from 'vite';
import vue from '@vitejs/plugin-vue';
import vueJsx from '@vitejs/plugin-vue-jsx';
import svgLoader from 'vite-svg-loader';
import eslint from 'vite-plugin-eslint';
import configCompressPlugin from './config/plugin/compress';
import configVisualizerPlugin from './config/plugin/visualizer';

const configDir = dirname(fileURLToPath(import.meta.url));

interface PluginLockDocument {
  schema_version: number;
  plugins: Array<{
    frontend: Array<{ client_key: string; entry: string }>;
  }>;
}

function lockedAdminContributions(): string[] {
  const lock = JSON.parse(
    readFileSync(resolve(configDir, '../plugins.lock'), 'utf8')
  ) as PluginLockDocument;
  if (lock.schema_version !== 1 || !Array.isArray(lock.plugins)) {
    throw new Error('plugins.lock is invalid');
  }
  return lock.plugins
    .flatMap((plugin) => plugin.frontend || [])
    .filter((identity) => identity.client_key === 'admin-web')
    .map((identity) => identity.entry)
    .sort();
}

// Normalize locked entries only after keeping them inside the real web source tree.
function resolveContributionImport(entry: unknown): string {
  if (typeof entry !== 'string' || isAbsolute(entry)) {
    throw new Error('Plugin contribution entry is invalid');
  }
  const projectRoot = resolve(configDir, '..');
  const webRoot = resolve(projectRoot, 'web');
  const sourceRoot = resolve(webRoot, 'src');
  const entryPath = resolve(projectRoot, entry);
  const sourceRelativePath = relative(sourceRoot, entryPath);
  if (
    sourceRelativePath === '' ||
    sourceRelativePath === '..' ||
    sourceRelativePath.startsWith(`..${sep}`) ||
    isAbsolute(sourceRelativePath)
  ) {
    throw new Error(`Plugin contribution is outside web/src: ${entry}`);
  }
  if (!existsSync(entryPath) || realpathSync(entryPath) !== entryPath) {
    throw new Error(
      `Plugin contribution is unavailable or a symlink: ${entry}`
    );
  }
  return `/${relative(webRoot, entryPath).split(sep).join('/')}`;
}

function pluginContributionManifest(entriesForBuild: () => string[]): Plugin {
  const virtualId = 'virtual:peanut-plugin-contributions';
  const resolvedId = `\0${virtualId}`;
  return {
    name: 'peanut-plugin-contribution-manifest',
    resolveId(id) {
      return id === virtualId ? resolvedId : null;
    },
    load(id) {
      if (id !== resolvedId) return null;
      const entries = entriesForBuild();
      const imports = entries.map((entry, index) => {
        return `import contribution${index} from ${JSON.stringify(
          resolveContributionImport(entry)
        )};`;
      });
      return `${imports.join('\n')}\nexport default [${entries
        .map((_, index) => `contribution${index}`)
        .join(',')}];`;
    },
  };
}

function instanceToolRouteManifest(instanceToolsCompiled: boolean): Plugin {
  const virtualId = 'virtual:peanut-instance-tool-routes';
  const resolvedId = `\0${virtualId}`;
  return {
    name: 'peanut-instance-tool-route-manifest',
    resolveId(id) {
      return id === virtualId ? resolvedId : null;
    },
    load(id) {
      if (id !== resolvedId) return null;
      if (!instanceToolsCompiled) return 'export default [];';
      return [
        "import instanceToolRoute from '/src/router/routes/modules/dev-tools.ts';",
        'export default [instanceToolRoute];',
      ].join('\n');
    },
  };
}

function compileInstanceTools(
  { command, mode }: ConfigEnv,
  deploymentMode?: string
): boolean {
  return (
    command === 'serve' &&
    mode === 'development' &&
    deploymentMode === 'standalone'
  );
}

export function createBaseConfig(
  configEnv: ConfigEnv,
  fileEnv: Record<string, string>,
  contributionEntries: () => string[] = lockedAdminContributions
): UserConfig {
  const resolvedDeploymentMode = fileEnv.VITE_DEPLOYMENT_MODE || 'standalone';
  if (
    resolvedDeploymentMode !== 'standalone' &&
    resolvedDeploymentMode !== 'multi-tenant'
  ) {
    throw new Error(
      `Invalid VITE_DEPLOYMENT_MODE: ${resolvedDeploymentMode}; expected standalone or multi-tenant`
    );
  }
  const instanceToolsCompiled = compileInstanceTools(
    configEnv,
    resolvedDeploymentMode
  );
  return {
    // The admin SPA is published below server/public/admin in every environment.
    base: '/admin/',
    plugins: [
      pluginContributionManifest(contributionEntries),
      instanceToolRouteManifest(instanceToolsCompiled),
      vue(),
      vueJsx(),
      svgLoader({ svgoConfig: {} }),
    ],
    resolve: {
      dedupe: ['vue', 'pinia', 'element-plus'],
      alias: [
        {
          find: '@',
          replacement: resolve(configDir, 'src'),
        },
        {
          find: 'assets',
          replacement: resolve(configDir, 'src/assets'),
        },
        {
          find: 'vue-i18n',
          replacement: 'vue-i18n/dist/vue-i18n.cjs.js', // Resolve the i18n warning issue
        },
        {
          find: 'vue',
          replacement: 'vue/dist/vue.esm-bundler.js', // compile template
        },
      ],
      extensions: ['.ts', '.js'],
    },
    define: {
      'process.env': {},
      '__VUE_PROD_HYDRATION_MISMATCH_DETAILS__': false,
      '__PEANUT_INSTANCE_TOOLS_COMPILED__': JSON.stringify(
        instanceToolsCompiled
      ),
      'import.meta.env.VITE_DEPLOYMENT_MODE': JSON.stringify(
        resolvedDeploymentMode
      ),
      'import.meta.env.VITE_API_BASE_URL': JSON.stringify(
        fileEnv.VITE_API_BASE_URL || ''
      ),
    },
    css: {
      preprocessorOptions: {
        less: {
          modifyVars: {
            hack: `true; @import (reference) "${resolve(
              'src/assets/style/breakpoint.less'
            )}";`,
          },
          javascriptEnabled: true,
        },
      },
    },
  };
}

function assertNoInstanceTools(): Plugin {
  const route = resolve(configDir, 'src/router/routes/modules/dev-tools.ts');
  const prefixes = [
    `${resolve(configDir, 'src/views/dev-tools')}/`,
    `${resolve(configDir, 'src/api/dev-tools')}/`,
  ];
  const normalize = (value: string) => value.replace(/\\/g, '/');
  const forbiddenRoute = normalize(route);
  const forbiddenPrefixes = prefixes.map(normalize);
  return {
    name: 'peanut-no-instance-tools-in-production',
    generateBundle(_options, bundle) {
      Object.values(bundle).forEach((output) => {
        if (output.type !== 'chunk') return;
        Object.keys((output as OutputChunk).modules).forEach((moduleId) => {
          const normalized = normalize(moduleId);
          if (
            normalized === forbiddenRoute ||
            forbiddenPrefixes.some((prefix) => normalized.startsWith(prefix))
          ) {
            throw new Error(
              `Production bundle contains instance-tool module: ${normalized}`
            );
          }
        });
      });
    },
  };
}

export default defineConfig((configEnv) => {
  const environment = loadEnv(configEnv.mode, configDir, '');
  const development = configEnv.command === 'serve' && !configEnv.isPreview;
  const apiProxyTarget =
    environment.VITE_API_PROXY_TARGET || 'http://127.0.0.1:20180';
  const base = createBaseConfig(configEnv, environment);
  return {
    ...base,
    plugins: [
      ...(base.plugins || []),
      ...(development
        ? [
            eslint({
              cache: false,
              include: ['src/**/*.ts', 'src/**/*.tsx', 'src/**/*.vue'],
              exclude: ['node_modules'],
            }),
          ]
        : [
            assertNoInstanceTools(),
            configCompressPlugin('gzip'),
            configVisualizerPlugin(environment.REPORT === 'true'),
          ]),
    ],
    server: {
      host: environment.VITE_HOST || '127.0.0.1',
      port: Number(environment.VITE_PORT || 5173),
      strictPort: true,
      open: environment.VITE_OPEN_BROWSER === 'true',
      allowedHosts: (environment.VITE_ALLOWED_HOSTS || '')
        .split(',')
        .map((host) => host.trim())
        .filter(Boolean),
      fs: { strict: true },
      proxy: Object.fromEntries(
        [
          // Installation documents must reach PHP before Vite's SPA fallback.
          '^/(?:admin/)?install(?:ation)?(?:/|$)',
          '/adminapi',
          '/platformapi',
          '/installapi',
          '/api',
          '/brand',
          '/storage',
        ].map((path) => [path, { target: apiProxyTarget, changeOrigin: false }])
      ),
    },
    build: {
      manifest: true,
      chunkSizeWarningLimit: 2000,
    },
  };
});
