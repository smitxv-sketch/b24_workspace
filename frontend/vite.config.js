import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

export default defineConfig({
  plugins: [react()],
  // При сборке для Битрикса — ставим base на путь приложения
  // base: '/local/app/workspace/',
  build: {
    outDir: 'dist',
    assetsDir: 'assets',
    rollupOptions: {
      output: {
        manualChunks: undefined,
      }
    }
  },
  server: {
    // Прокси на Битрикс для разработки
    proxy: {
      '/local/api': {
        target: 'https://YOUR-DOMAIN.bitrix24.ru',
        changeOrigin: true,
        secure: false,
      }
    }
  }
})
