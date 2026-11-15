import path from 'node:path';
import { defineConfig } from 'vite';
import uni from '@dcloudio/vite-plugin-uni';

// 源码目录（UNI_INPUT_DIR）由 scripts/h5.mjs 在 CLI 启动前指定为项目根目录，
// 因为本项目沿用 HBuilderX 目录结构，manifest.json / pages.json 都在根目录。
const root = __dirname;

export default defineConfig({
  resolve: {
    alias: {
      '@': root,
    },
  },
  plugins: [uni()],
  server: {
    host: '0.0.0.0',
    port: 5173,
    strictPort: true,
  },
});
