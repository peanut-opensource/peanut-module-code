import { existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';
import { createRequire } from 'node:module';

const root = dirname(fileURLToPath(import.meta.url));
const webRequire = createRequire(resolve(root, 'package.json'));
const vuePlugin = webRequire('@vitejs/plugin-vue');
const selectedCore = process.env.WEB_CORE_SOURCE_ROOT;
if (!selectedCore)
  throw new Error(
    'WEB_CORE_SOURCE_ROOT is required for optional runtime tests.'
  );
const coreRoot = resolve(selectedCore);
const coreAliases = ['client', 'vue', 'ui-vue'].map((name) => {
  const entry = resolve(coreRoot, `packages/${name}/src/index.ts`);
  if (!existsSync(entry))
    throw new Error(`Selected Core entry is missing: ${entry}`);
  return { find: `@peanut-admin/${name}`, replacement: entry };
});

export default {
  root,
  plugins: [vuePlugin()],
  resolve: {
    dedupe: [
      'vue',
      '@vue/runtime-core',
      '@vue/runtime-dom',
      '@vue/reactivity',
      '@vue/shared',
      'pinia',
    ],
    alias: [
      ...coreAliases,
      { find: '@', replacement: resolve(root, 'src') },
      {
        find: '@vue/test-utils',
        replacement: resolve(
          root,
          '../tools/quality/node_modules/@vue/test-utils/dist/vue-test-utils.esm-bundler.mjs'
        ),
      },
      {
        find: 'element-plus',
        replacement: resolve(root, 'node_modules/element-plus/es/index.mjs'),
      },
      {
        find: 'vue-router',
        replacement: resolve(
          root,
          'node_modules/vue-router/dist/vue-router.mjs'
        ),
      },
      {
        find: 'vue',
        replacement: resolve(root, 'node_modules/vue/dist/vue.esm-bundler.js'),
      },
      {
        find: 'pinia',
        replacement: resolve(root, 'node_modules/pinia/dist/pinia.mjs'),
      },
    ],
  },
  test: {
    server: { deps: { inline: [/vue/, /pinia/, /element-plus/] } },
    environment: 'node',
    include: [
      'src/modules/peanut-file/optional-runtime/tests/**/*.spec.ts',
      'src/modules/peanut-import-export/optional-runtime/tests/**/*.spec.ts',
      'src/modules/peanut-notification/optional-runtime/tests/**/*.spec.ts',
      'src/modules/peanut-task/optional-runtime/tests/**/*.spec.ts',
      'src/modules/peanut-reference-codes/runtime/tests/**/*.spec.ts',
      'src/modules/peanut-settings/runtime/tests/**/*.spec.ts',
      'src/modules/peanut-content-tools/tests/**/*.spec.ts',
      'src/modules/peanut-rich-text/tests/**/*.spec.ts',
    ],
    passWithNoTests: false,
  },
};
