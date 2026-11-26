// v1.8.1 ships a declaration but does not expose it for the ESM import.
declare module 'vite-plugin-eslint' {
  const eslint: typeof import('../node_modules/vite-plugin-eslint/dist/index.js').default;
  export default eslint;
}
