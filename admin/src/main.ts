import { createApp } from 'vue'
import App from './App.vue'
import router from './router/router'
import store from './stores/pinia'
import ElementPlus from 'element-plus'
import 'element-plus/dist/index.css'
import './style.css'
import zhCn from 'element-plus/es/locale/lang/zh-cn'
import * as ElementPlusIconsVue from '@element-plus/icons-vue'
import { vPermission } from '@/directives/permission'
import { setUnauthorizedHandler } from '@/request/http'
import { useSessionService } from '@/shared/factory/session'

const app = createApp(App)

for (const [key, component] of Object.entries(ElementPlusIconsVue)) {
  app.component(key, component)
}

app.directive('permission', vPermission)

app.use(store)
app.use(router)
app.use(ElementPlus, { locale: zhCn })

let unauthorizedHandling = false
const sessionService = useSessionService()
setUnauthorizedHandler(async () => {
  if (unauthorizedHandling) return
  unauthorizedHandling = true
  try {
    sessionService.handleUnauthorized()
    if (router.currentRoute.value.path !== '/login') {
      await router.replace('/login')
    }
  } finally {
    unauthorizedHandling = false
  }
})

app.mount('#app')
