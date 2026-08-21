import path from 'node:path'
import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import tailwindcss from '@tailwindcss/vite'
import monacoEditorEsmPlugin from 'vite-plugin-monaco-editor-esm'

export default defineConfig({
  base: './',
  plugins: [
    vue(),
    tailwindcss(),
    monacoEditorEsmPlugin({
      languageWorkers: ['json', 'css', 'html', 'typescript'],
    }),
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
    },
  },
  server: {
    port: 5173,
    strictPort: true,
    cors: true,
    origin: 'http://localhost:5173',
  },
})
