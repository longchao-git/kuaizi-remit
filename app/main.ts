import App from './App.vue'

// Vue 3 入口
import { createSSRApp } from 'vue'
import { initializeUnauthorizedHandler } from '@/app/handlers/unauthorized'

export function createApp() {
  initializeUnauthorizedHandler()
  const app = createSSRApp(App)
  return {
    app
  }
}
