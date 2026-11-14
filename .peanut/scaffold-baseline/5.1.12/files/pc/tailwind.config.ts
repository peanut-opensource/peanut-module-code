import type { Config } from 'tailwindcss';

export default {
  content: [
    './app.vue',
    './pages/**/*.vue',
    './components/**/*.vue',
    './layouts/**/*.vue',
  ],
  theme: {
    extend: {
      colors: {
        primary: '#2979ff',
      },
    },
  },
  plugins: [],
  // Prevent conflicts with Element Plus
  corePlugins: {
    preflight: false,
  },
} satisfies Config;
