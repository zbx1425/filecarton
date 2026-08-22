import path from 'node:path'
import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import tailwindcss from '@tailwindcss/vite'

const cdnExternals = [
  'vue',
  'pinia',
  '@vue/devtools-api',
  '@vue/devtools-kit',
  '@vue/devtools-shared',
  'birpc',
  'hookable',
  'perfect-debounce',
  '@vueuse/core',
  '@vueuse/shared',
  '@vueuse/metadata',
  'vue-sonner',
]

export default defineConfig({
  base: './',
  plugins: [
    vue(),
    tailwindcss(),
  ],
  resolve: {
    alias: {
      '@': path.resolve(__dirname, './src'),
    },
  },
  build: {
    manifest: true,
    rollupOptions: {
      input: 'src/main.ts',
      external: cdnExternals,
    },
  },
  server: {
    port: 5173,
    strictPort: true,
    cors: true,
    origin: 'http://localhost:5173',
  },
  define: {
    '__APP_VERSION__': JSON.stringify(process.env.npm_package_version),
  }
})
