import { defineConfig } from '../../../../node_modules/vite/dist/node/index.js';
import vue from '../../../../../platform/node_modules/@vitejs/plugin-vue/dist/index.mjs';
import path from 'node:path';
const here = path.dirname(new URL(import.meta.url).pathname);
export default defineConfig({
  root: here,
  plugins: [vue(), {
    name: 'task-only-no-unrelated-consumers',
    resolveId(id) { return id === 'virtual:peanut-module-contributions' ? '\0task-no-contributions' : null; },
    load(id) { return id === '\0task-no-contributions' ? 'export default [];': null; },
  }],
  base: './',
  build: { outDir: path.join(here, '../dist'), emptyOutDir: true, sourcemap: false },
  resolve: {
    alias: [
      {find: /^vue$/, replacement: path.resolve(here, '../../../../node_modules/vue/dist/vue.runtime.esm-bundler.js')},
      {find: /^@peanut-admin\/peanut-content-tools\/surface$/, replacement: path.resolve(here, '../../../../src/modules/peanut-content-tools/src/index.ts')},
      {find: /^@\//, replacement: path.resolve(here, '../../../../src/')}
    ]
  }
});
