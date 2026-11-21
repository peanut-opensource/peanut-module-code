import type { Directive } from 'vue';

declare module 'vue' {
  interface GlobalDirectives {
    vPermission: Directive<HTMLElement, string[]>;
  }
}
