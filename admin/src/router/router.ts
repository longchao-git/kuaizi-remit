import { createRouter, createWebHashHistory, type RouteLocationNormalized, type RouteRecordRaw } from 'vue-router'
import { ElMessage } from 'element-plus'
import { useUserStore } from '@/stores/user'
import { useSessionService } from '@/shared/factory/session'
import { isNetworkError, isUnauthorizedError } from '@/request/http'
import { getErrorMessage } from '@/utils/error'
import { hasAnyPermission, hasPermission } from '@/utils/permission'
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
    component: () => import('@/layouts/AppLayout.vue'),
    children: [
      {
        path: 'role/edit',
        name: 'RoleEdit',
        component: () => import('@/modules/role/pages/AddRolePage.vue'),
        meta: {
          title: '角色维护',
          addPermission: 'system:role:add',
          editPermission: 'system:role:edit',
          editReadPermission: 'system:role:list',
          permissionAssignmentPermission: 'system:role:assignMenu',
          permissionMode: 'all'
        }
      },
      {
        path: 'account',
        name: 'MyAccount',
        component: () => import('@/modules/auth/pages/MyAccountPage.vue'),
        meta: { title: '我的账户' }
      }
    ]
  },
  {
    path: '/dashboard',
    name: 'DashboardLayout',
    component: () => import('@/layouts/AppLayout.vue'),
    redirect: '/dashboard/index',
    children: [
      {
        path: 'index',
        name: 'DashboardHome',
        component: () => import('@/modules/dashboard/pages/DashboardPage.vue'),
        meta: { title: '首页' }
      }
    ]
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
    const path = String(route.path || '').replace(/^\//, '')
    if (route.meta?.isDashboard && path === 'index') return

    if (route.name && router.hasRoute(route.name)) router.removeRoute(route.name)
    const parentName = route.meta?.isDashboard ? 'DashboardLayout' : 'SystemLayout'
    removeDynamicRoutes.push(router.addRoute(parentName, route))
  })
}

function resolveRequiredPermissions(to: RouteLocationNormalized): string[] {
  const hasEntityId = typeof to.query.id === 'string' && Boolean(to.query.id.trim())
  const isPermissionAssignment = to.query.mode === 'permissions'
  const contextualPermission = isPermissionAssignment
    ? to.meta.permissionAssignmentPermission
    : hasEntityId ? to.meta.editPermission : to.meta.addPermission
  const permissions: string[] = []

  if (typeof contextualPermission === 'string' && contextualPermission) {
    permissions.push(contextualPermission)
  }
  if (!isPermissionAssignment && hasEntityId
      && typeof to.meta.editReadPermission === 'string' && to.meta.editReadPermission) {
    permissions.push(to.meta.editReadPermission)
  }
  if (typeof to.meta.permission === 'string' && to.meta.permission) {
    permissions.push(to.meta.permission)
  }
  if (Array.isArray(to.meta.permissions)) {
    permissions.push(...to.meta.permissions.filter((permission): permission is string => (
      typeof permission === 'string' && Boolean(permission)
    )))
  }

  return [...new Set(permissions)]
}

function canAccessRoute(to: RouteLocationNormalized): boolean {
  const permissions = resolveRequiredPermissions(to)
  if (!permissions.length) return true
  return to.meta.permissionMode === 'all'
    ? permissions.every(permission => hasPermission(permission))
    : hasAnyPermission(permissions)
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

  if (to.path === '/system') {
    return resolveFirstAccessiblePath(userStore.menus, '/dashboard/index')
  }

  if (to.matched.length === 0) {
    return resolveFirstAccessiblePath(userStore.menus, '/dashboard/index')
  }

  if (!canAccessRoute(to)) {
    ElMessage.error('暂无访问该页面的权限')
    const fallback = resolveFirstAccessiblePath(userStore.menus, '/dashboard/index')
    return { path: fallback === to.path ? '/dashboard/index' : fallback, replace: true }
  }

  return true
})

export default router
