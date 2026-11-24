/// <reference path="./config/vite-plugin-eslint.d.ts" />

import {
  existsSync,
  lstatSync,
  readFileSync,
  readdirSync,
  realpathSync,
} from 'node:fs';
import {
  dirname,
  extname,
  isAbsolute,
  relative,
  resolve,
  sep,
} from 'node:path';
import { fileURLToPath } from 'node:url';
import {
  defineConfig,
  loadEnv,
  type ConfigEnv,
  type Plugin,
  type ResolvedConfig,
  type UserConfig,
} from 'vite';
import vue from '@vitejs/plugin-vue';
import vueJsx from '@vitejs/plugin-vue-jsx';
import svgLoader from 'vite-svg-loader';
import eslint from 'vite-plugin-eslint';
import configCompressPlugin from './config/plugin/compress';
import configVisualizerPlugin from './config/plugin/visualizer';

const configDir = dirname(fileURLToPath(import.meta.url));

interface ModuleLockDocument {
  schema_version: number;
  modules: Array<{
    key: string;
    frontend: Array<{ client_key: string; entry: string }>;
  }>;
}

function lockedAdminModuleIdentities(): Array<{ key: string; entry: string }> {
  const lock = JSON.parse(
    readFileSync(resolve(configDir, '../modules.lock'), 'utf8')
  ) as ModuleLockDocument;
  if (lock.schema_version !== 1 || !Array.isArray(lock.modules)) {
    throw new Error('modules.lock is invalid');
  }
  return lock.modules.flatMap(({ key, frontend }) =>
    (frontend || [])
      .filter((identity) => identity.client_key === 'admin-web')
      .map(({ entry }) => ({ key, entry }))
  );
}

function lockedAdminContributions(): string[] {
  return lockedAdminModuleIdentities()
    .map(({ entry }) => entry)
    .sort();
}

// Normalize locked entries only after keeping them inside the real web source tree.
function resolveContributionImport(entry: unknown): string {
  if (typeof entry !== 'string' || isAbsolute(entry)) {
    throw new Error('Module contribution entry is invalid');
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
    throw new Error(`Module contribution is outside web/src: ${entry}`);
  }
  if (!existsSync(entryPath) || realpathSync(entryPath) !== entryPath) {
    throw new Error(
      `Module contribution is unavailable or a symlink: ${entry}`
    );
  }
  return `/${relative(webRoot, entryPath).split(sep).join('/')}`;
}

function moduleContributionManifest(entriesForBuild: () => string[]): Plugin {
  const virtualId = 'virtual:peanut-module-contributions';
  const resolvedId = `\0${virtualId}`;
  return {
    name: 'peanut-module-contribution-manifest',
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

const moduleAssetDirectory = 'module-assets';
const moduleAssetContentTypes: Record<string, string> = {
  '.avif': 'image/avif',
  '.css': 'text/css',
  '.gif': 'image/gif',
  '.html': 'text/html',
  '.ico': 'image/x-icon',
  '.jpeg': 'image/jpeg',
  '.jpg': 'image/jpeg',
  '.js': 'text/javascript',
  '.json': 'application/json',
  '.mjs': 'text/javascript',
  '.png': 'image/png',
  '.svg': 'image/svg+xml',
  '.ttf': 'font/ttf',
  '.txt': 'text/plain',
  '.wasm': 'application/wasm',
  '.webp': 'image/webp',
  '.woff': 'font/woff',
  '.woff2': 'font/woff2',
  '.xml': 'application/xml',
};

function modulePublicAssetSources(hostPublicDir: string): Map<string, string> {
  const assets = new Map<string, string>();
  const paths = new Set<string>();
  const collect = (directory: string, output: string): void => {
    for (const item of readdirSync(directory, { withFileTypes: true })) {
      const source = resolve(directory, item.name);
      const fileName = `${output}/${item.name}`;
      const foldedPath = fileName.toLowerCase();
      if (
        !/^[a-z0-9_][a-z0-9_.-]*$/i.test(item.name) ||
        item.name.toLowerCase() === 'node_modules' ||
        /\.php$/i.test(item.name) ||
        paths.has(foldedPath) ||
        (!item.isDirectory() &&
          (!item.isFile() || lstatSync(source).nlink !== 1))
      ) {
        throw new Error(
          `Invalid or conflicting Module public asset: ${source}`
        );
      }
      paths.add(foldedPath);
      if (item.isDirectory()) collect(source, fileName);
      else assets.set(fileName, source);
    }
  };

  for (const { key, entry } of lockedAdminModuleIdentities()) {
    if (
      !/^[a-z][a-z0-9-]*\.[a-z][a-z0-9-]*$/.test(key) ||
      entry !== `web/src/modules/${key.replace('.', '-')}/contribution.ts`
    ) {
      throw new Error(`Invalid locked Admin module identity: ${key}`);
    }
    const contribution = resolveContributionImport(entry);
    const root = resolve(configDir, dirname(contribution.slice(1)), 'public');
    const rootStat = lstatSync(root, { throwIfNoEntry: false });
    if (!rootStat) continue;
    if (!rootStat.isDirectory()) {
      throw new Error(
        `Module public asset root must be an ordinary directory: ${root}`
      );
    }
    if (
      hostPublicDir &&
      existsSync(hostPublicDir) &&
      readdirSync(hostPublicDir).some(
        (name) => name.toLowerCase() === moduleAssetDirectory
      )
    ) {
      throw new Error(
        `The ${moduleAssetDirectory} output is reserved for locked Admin modules: ${hostPublicDir}`
      );
    }
    collect(root, `${moduleAssetDirectory}/${key}`);
  }
  return assets;
}

function modulePublicAssets(): Plugin {
  let resolvedConfig: ResolvedConfig;
  return {
    name: 'peanut-module-public-assets',
    configResolved(config) {
      resolvedConfig = config;
    },
    configureServer({ config, middlewares }) {
      const prefix = `${config.base}${moduleAssetDirectory}`;
      modulePublicAssetSources(config.publicDir);
      // Vite applies host and CORS checks before post hooks and writes SPA HTML after them.
      return () => {
        middlewares.use((request, response, next) => {
          const path = (request.originalUrl ?? request.url ?? '').split(
            '?',
            1
          )[0];
          if (path !== prefix && !path.startsWith(`${prefix}/`)) {
            return next();
          }
          response.setHeader('Cache-Control', 'no-store');
          if (request.method !== 'GET' && request.method !== 'HEAD') {
            response.statusCode = 405;
            response.setHeader('Allow', 'GET, HEAD');
            return response.end();
          }
          const sources = modulePublicAssetSources(config.publicDir);
          const source = sources.get(path.slice(config.base.length));
          if (!source) {
            response.statusCode = 404;
            return response.end();
          }
          const content = readFileSync(source);
          const contentType =
            moduleAssetContentTypes[extname(source).toLowerCase()];
          response.setHeader(
            'Content-Type',
            contentType || 'application/octet-stream'
          );
          response.setHeader('Content-Length', content.byteLength);
          return response.end(request.method === 'HEAD' ? undefined : content);
        });
      };
    },
    buildStart() {
      if (resolvedConfig.command !== 'build') return;
      for (const [fileName, source] of modulePublicAssetSources(
        resolvedConfig.publicDir
      )) {
        this.emitFile({
          type: 'asset',
          fileName,
          source: new Uint8Array(readFileSync(source)),
        });
      }
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
  const buildEdition =
    configEnv.command === 'build' &&
    (configEnv.mode === 'standalone' || configEnv.mode === 'multi-tenant')
      ? configEnv.mode
      : undefined;
  const resolvedDeploymentMode =
    fileEnv.VITE_DEPLOYMENT_MODE || buildEdition || 'standalone';
  if (
    resolvedDeploymentMode !== 'standalone' &&
    resolvedDeploymentMode !== 'multi-tenant'
  ) {
    throw new Error(
      `Invalid VITE_DEPLOYMENT_MODE: ${resolvedDeploymentMode}; expected standalone or multi-tenant`
    );
  }
  if (buildEdition && resolvedDeploymentMode !== buildEdition) {
    throw new Error(
      `VITE_DEPLOYMENT_MODE=${resolvedDeploymentMode} conflicts with build mode ${buildEdition}`
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
      moduleContributionManifest(contributionEntries),
      modulePublicAssets(),
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
        Object.keys(output.modules).forEach((moduleId) => {
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
