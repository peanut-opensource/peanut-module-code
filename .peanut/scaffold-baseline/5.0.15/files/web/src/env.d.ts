/// <reference types="vite/client" />
/// <reference types="vue/jsx" />

declare module '*.vue' {
  import type { DefineComponent } from 'vue';
  const component: DefineComponent;
  export default component;
}
interface ImportMetaEnv {
  readonly VITE_API_BASE_URL: string;
  readonly VITE_DEPLOYMENT_MODE?: 'standalone' | 'multi-tenant';
}

// eslint-disable-next-line no-underscore-dangle
declare const __PEANUT_INSTANCE_TOOLS_COMPILED__: boolean;

declare module 'virtual:peanut-plugin-contributions' {
  import type { ContentToolsContribution } from '@/modules/peanut-content-tools/src/contracts';

  const contributions: ContentToolsContribution[];
  export default contributions;
}

declare module 'virtual:peanut-instance-tool-routes' {
  import type { RouteRecordRaw } from 'vue-router';

  const routes: RouteRecordRaw[];
  export default routes;
}
