import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

export default defineConfig({
  plugins: [react()],
  // При сборке для Битрикса — ставим base на путь приложения
  base: '/local/ws/front/',
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
  proxy: {
    '/local/ws/api': {
      target: 'https://bitrix.ustup.ru',
      changeOrigin: true,
      secure: false,
      cookieDomainRewrite: 'localhost',  // ← добавить это
    }
  }
}
})


