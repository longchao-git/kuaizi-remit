import { defineConfig, loadEnv } from 'vite';
import vue from '@vitejs/plugin-vue';
import path from 'path';

export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '');
  const target = env.VITE_PROXY_TARGET || 'http://127.0.0.1:8688';
  const configuredApiBase = env.VITE_API_BASE || '/api';
  const proxyPrefix = configuredApiBase.startsWith('/')
    ? configuredApiBase.replace(/\/+$/, '') || '/api'
    : '/api';

  const vendorChunkName = (moduleId: string) => {
    const id = moduleId.replaceAll('\\', '/');
    if (!id.includes('node_modules')) return undefined;
    if (id.includes('element-plus') || id.includes('@element-plus')) return 'element-plus';
    if (id.includes('echarts')) return 'echarts';
    if (
      id.includes('/vue/') ||
      id.includes('/pinia/') ||
      id.includes('/vue-router/')
    ) {
      return 'vue-vendor';
    }
    return 'vendor';
  };

  return {
    base: '/',
    plugins: [vue()],
    resolve: {
      alias: {
        '@': path.resolve(import.meta.dirname, 'src'),
      },
    },
    build: {
      rolldownOptions: {
        output: {
          strictExecutionOrder: true,
          codeSplitting: {
            groups: [{ name: (id: string) => vendorChunkName(id) ?? null }]
          }
        }
      }
    },
    server: {
      host: '0.0.0.0',
      port: 10099,
      proxy: {
        [proxyPrefix]: {
          target,
          changeOrigin: true,
        }
      }
    }
  };
});
