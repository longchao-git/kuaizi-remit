import path from 'node:path';
import { defineConfig } from 'vite';
import uni from '@dcloudio/vite-plugin-uni';

// 源码目录（UNI_INPUT_DIR）由 scripts/h5.mjs 在 CLI 启动前指定为项目根目录，
// 因为本项目沿用 HBuilderX 目录结构，manifest.json / pages.json 都在根目录。
const root = __dirname;
const unusedCloudAuthScript =
  /<script src="https:\/\/cn-shanghai-aliyun-cloudauth\.oss-cn-shanghai\.aliyuncs\.com\/web_sdk_js\/jsvm_all\.js"><\/script>/g;

export default defineConfig({
  resolve: {
    alias: {
      '@': root,
    },
  },
  plugins: [
    uni(),
    {
      name: 'remit-remove-unused-cloud-auth',
      enforce: 'post',
      apply: () => process.env.UNI_PLATFORM === 'h5' || process.env.UNI_PLATFORM === 'web',
      transformIndexHtml: {
        order: 'post',
        // uni 的 H5 开发编译会把框架自带的认证实现识别为项目调用。项目使用雅本返回的认证页面。
        handler: (html) => html.replace(unusedCloudAuthScript, ''),
      },
    },
  ],
  server: {
    host: '0.0.0.0',
    port: 5173,
    strictPort: true,
  },
});
