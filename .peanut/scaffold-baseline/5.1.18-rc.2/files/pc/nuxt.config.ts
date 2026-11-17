import defaultBrand from './generated/brand.json';
import { resolve } from 'node:path';
import { extendRouteRules, useNitro } from '@nuxt/kit';
import { moduleFrontendEntries } from '../scripts/module-frontend-entries.mjs';
import {
  createPcRenderingOptions,
  createPcPageRules,
} from './utils/rendering-policy';

const devProxyOrigin =
  process.env.NUXT_DEV_PROXY_ORIGIN || 'http://127.0.0.1:20180';
const devProxyTarget =
  process.env.NUXT_DEV_PROXY_TARGET || `${devProxyOrigin}/api`;
const rendering = createPcRenderingOptions(process.env.NUXT_PC_RENDER_MODE);
const moduleEntries = moduleFrontendEntries(
  resolve(import.meta.dirname, '..'),
  'pc-web',
  process.env.NODE_ENV === 'development'
);

export default defineNuxtConfig({
  compatibilityDate: '2024-11-01',
  devtools: { enabled: false },
  ...rendering,
  hooks: {
    async 'pages:resolved'(pages) {
      const rules = createPcPageRules(
        pages,
        resolve(import.meta.dirname, 'pages'),
        rendering.ssr
      );
      for (const [path, rule] of Object.entries(rules)) {
        extendRouteRules(path, rule, { override: true });
      }
      // Nitro 在页面扫描前已经初始化；同时更新其实际配置，保留私有页 headers。
      const nitro = useNitro();
      const routeRules = { ...nitro.options.routeRules };
      for (const [path, rule] of Object.entries(rules)) {
        routeRules[path] = { ...routeRules[path], ...rule };
      }
      await nitro.updateConfig({ routeRules });
    },
  },

  app: {
    baseURL: '/',
    head: {
      title: defaultBrand.website.pc_title,
      meta: [
        { name: 'description', content: defaultBrand.website.pc_desc },
        { name: 'keywords', content: defaultBrand.website.pc_keywords },
      ],
      link: [{ rel: 'icon', href: '/brand/favicon.svg' }],
    },
  },

  modules: [
    '@element-plus/nuxt',
    '@pinia/nuxt',
    '@nuxtjs/tailwindcss',
    ...moduleEntries,
  ],

  appConfig: {
    moduleSlots: {},
    moduleKeys: moduleEntries.map((entry: string) => {
      const directory = entry.slice(0, entry.lastIndexOf('/'));
      return directory
        .slice(directory.lastIndexOf('/') + 1)
        .replace('peanut-', 'peanut.');
    }),
  },

  elementPlus: {
    importStyle: 'css',
  },

  runtimeConfig: {
    upstreamOrigin: process.env.NUXT_UPSTREAM_ORIGIN || devProxyOrigin,
    forwardedProto: process.env.NUXT_FORWARDED_PROTO || 'http',
    trustedHosts: process.env.NUXT_TRUSTED_HOSTS || '',
    public: {
      apiBase: '',
    },
  },

  nitro: {
    devProxy: {
      '/api': {
        target: devProxyTarget,
        changeOrigin: false,
      },
      '/brand': {
        target: `${devProxyOrigin}/brand`,
        changeOrigin: false,
      },
      '/storage': {
        target: `${devProxyOrigin}/storage`,
        changeOrigin: false,
      },
    },
  },

  tailwindcss: {
    exposeConfig: true,
  },

  typescript: {
    strict: true,
  },
});
