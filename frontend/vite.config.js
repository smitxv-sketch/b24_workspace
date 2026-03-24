import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

// Уникальная метка сборки: меняется на каждом build,
// чтобы имена файлов всегда были новыми (cache-busting).
const buildStamp = Date.now()

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
        entryFileNames: `assets/[name]-${buildStamp}-[hash].js`,
        chunkFileNames: `assets/[name]-${buildStamp}-[hash].js`,
        assetFileNames: `assets/[name]-${buildStamp}-[hash].[ext]`,
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


