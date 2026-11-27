interface ModuleAppConfig {
  moduleSlots: Record<string, string>;
  moduleKeys: string[];
}

// Nuxt 4 merges CustomAppConfig into its generated input and runtime config types.
declare module 'nuxt/schema' {
  interface CustomAppConfig extends ModuleAppConfig {}
}

declare module '@nuxt/schema' {
  interface CustomAppConfig extends ModuleAppConfig {}
}

export {};
