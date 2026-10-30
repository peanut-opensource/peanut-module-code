interface ModuleAppConfig {
  moduleSlots: Record<string, string>;
  moduleKeys: string[];
}

// Nuxt's generated config augments both the public facade and the schema package.
declare module 'nuxt/schema' {
  interface AppConfig extends ModuleAppConfig {}
}

declare module '@nuxt/schema' {
  interface AppConfig extends ModuleAppConfig {}
}

export {};
