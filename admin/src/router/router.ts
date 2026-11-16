import { createRouter, createWebHashHistory, type RouteLocationNormalized, type RouteRecordRaw } from 'vue-router'
import { ElMessage } from 'element-plus'
import { useUserStore } from '@/stores/user'
import { useSessionService } from '@/shared/factory/session'
import { isNetworkError, isUnauthorizedError } from '@/request/http'
import { getErrorMessage } from '@/utils/error'
import { hasPermission } from '@/utils/permission'
import { buildSystemChildrenRoutes, resolveFirstAccessiblePath } from './menuRouteResolver'

const routes: RouteRecordRaw[] = [
  { path: '/', redirect: '/login' },
  {
    path: '/login',
    name: 'Login',
    component: () => import('@/modules/auth/pages/LoginPage.vue')
  },
  {
    path: '/system',
    name: 'SystemLayout',
    component: () => import('@/layouts/AppLayout.vue')
  }
]

if (import.meta.env.DEV) {
  routes.push({
    path: '/remit-preview',
    name: 'RemitPreview',
    component: () => import('@/modules/remit/RemitAdminPreview.vue')
  })
}

const router = createRouter({ history: createWebHashHistory(), routes })

let removeDynamicRoutes: Array<() => void> = []

function clearDynamicRoutes() {
  removeDynamicRoutes.forEach(removeRoute => removeRoute())
  removeDynamicRoutes = []
}

function registerDynamicRoutes(menuRoutes: RouteRecordRaw[]) {
  clearDynamicRoutes()

  menuRoutes.forEach(route => {
    if (route.name && router.hasRoute(route.name)) router.removeRoute(route.name)
    removeDynamicRoutes.push(router.addRoute('SystemLayout', route))
  })
}

function canAccessRoute(to: RouteLocationNormalized): boolean {
  const permission = to.meta.permission
  return typeof permission !== 'string' || !permission || hasPermission(permission)
}

router.beforeEach(async to => {
  const userStore = useUserStore()

  if (to.path === '/login' || (import.meta.env.DEV && to.name === 'RemitPreview')) return true

  if (!userStore.token) {
    clearDynamicRoutes()
    return { path: '/login', query: { redirect: to.fullPath } }
  }

  if (!userStore.sessionInitialized) {
    try {
      await useSessionService().initializeSession()
    } catch (error) {
      if (isUnauthorizedError(error)) {
        userStore.clearSession()
        clearDynamicRoutes()
        return { path: '/login', query: { redirect: to.fullPath } }
      }

      if (isNetworkError(error) && userStore.hasCachedSession) {
        userStore.setSessionInitialized(true)
      } else {
        ElMessage.error(getErrorMessage(
          error,
          isNetworkError(error) ? '网络不可用，无法恢复登录信息' : '登录信息加载失败'
        ))
        return false
      }
    }
  }

  if (!userStore.routesLoaded) {
    registerDynamicRoutes(buildSystemChildrenRoutes(userStore.menus))
    userStore.setRoutesLoaded(true)
    return { path: to.path, query: to.query, hash: to.hash, replace: true }
  }

  if (to.matched.length === 0) {
    return resolveFirstAccessiblePath(userStore.menus)
  }

  if (to.path === '/system') {
    const firstPage = resolveFirstAccessiblePath(userStore.menus)
    return firstPage !== '/system' ? firstPage : true
  }

  if (!canAccessRoute(to)) {
    ElMessage.error('暂无访问该页面的权限')
    return { path: '/system', replace: true }
  }

  return true
})

export default router
