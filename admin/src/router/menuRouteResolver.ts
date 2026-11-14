import type { RouteRecordRaw } from 'vue-router'
import type { MenuVO } from '@/shared/types/menu'
import { getMenuChildren, isDashboardMenu, resolveRouteChildPath, sortMenus } from '@/shared/utils/menuTree'
import { componentMap } from './componentMap'

export function buildSystemChildrenRoutes(menus: MenuVO[]): RouteRecordRaw[] {
  const roots = Array.isArray(menus) ? menus : []

  const routes: RouteRecordRaw[] = []

  const walk = (nodes: MenuVO[]) => {
    const sorted = sortMenus(nodes)
    sorted.forEach((m) => {
      const childList = getMenuChildren(m)
      if (childList.length > 0) walk(childList)

      const compKey = String(m.component || '')
      const comp = componentMap[compKey]
      if (!comp) return

      const childPath = resolveRouteChildPath(String(m.path || ''))
      const isDashboard = isDashboardMenu(m)
      const name = `System_${childPath.replace(/[^\w]/g, '_') || String(m.id || m.name)}`
      routes.push({
        path: childPath,
        name,
        component: comp,
        meta: {
          title: m.name,
          permission: m.permission,
          menuId: m.id,
          isDashboard
        }
      } as RouteRecordRaw)
    })
  }

  // 直接遍历整棵菜单树：仅注入当前前端仍支持的页面映射
  walk(roots)
  return routes
}

/**
 * 根据后端 menus 解析“第一个可访问页面”的完整路径（/system/xxx）。
 *
 * <p>只会从当前前端已映射的 componentMap 中选择有效菜单，避免跳到未注入路由导致白屏。</p>
 */
export function resolveFirstAccessiblePath(menus: MenuVO[], fallbackPath = '/dashboard/index'): string {
  const children = buildSystemChildrenRoutes(menus)
  const first = children[0]
  if (!first) return fallbackPath
  const childPath = String(first.path || '').replace(/^\//, '')
  if (!childPath) return fallbackPath

  if (first.meta?.isDashboard) {
    return '/dashboard'
  } else {
    return `/system/${childPath}`
  }
}
