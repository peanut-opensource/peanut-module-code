/// <reference types="vite/client" />

declare module 'virtual:peanut-module-contributions' {
  export const moduleKeys: string[];
  export const moduleSlots: string[];
  export const modulePages: string[];
}

declare module '*.vue' {
  import type { DefineComponent } from 'vue';
  const component: DefineComponent;
  export default component;
}
