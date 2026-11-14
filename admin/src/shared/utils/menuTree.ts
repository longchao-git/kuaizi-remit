import type { MenuVO } from '@/shared/types/menu'

export const HIDDEN_MENU_REMARK = '隐藏页面'

export interface SecondaryMenuSection {
  key: string
  title?: string
  items: MenuVO[]
}

export function getMenuChildren(menu: MenuVO): MenuVO[] {
  return Array.isArray(menu.children) ? menu.children : []
}

export function sortMenus(menus: MenuVO[]): MenuVO[] {
  return [...menus].sort((a, b) => Number(a.sort ?? 0) - Number(b.sort ?? 0))
}

export function isMenuVisible(menu: MenuVO): boolean {
  if (typeof menu.visible === 'boolean') return menu.visible
  return menu.remark !== HIDDEN_MENU_REMARK
}

export function sanitizeMenuRemark(remark?: string): string | undefined {
  return remark === HIDDEN_MENU_REMARK ? undefined : remark
}

export function resolveMenuRemark(visible: boolean | undefined, remark?: string): string | undefined {
  if (visible === false) return HIDDEN_MENU_REMARK
  return sanitizeMenuRemark(remark)
}

export function getVisibleSortedMenus(menus: MenuVO[]): MenuVO[] {
  return sortMenus((Array.isArray(menus) ? menus : []).filter(isMenuVisible))
}

export function hasVisibleChildren(menu: MenuVO): boolean {
  return getMenuChildren(menu).some(isMenuVisible)
}

export function resolveMenuDisplayName(menu: MenuVO): string {
  return String(menu.name || '')
}

export function resolveMenuIndex(menu: MenuVO, forGroup = false): string {
  const raw = String(menu.path || (forGroup ? (menu.name || menu.id || '') : '') || '')
  if (!raw) return String(menu.id || menu.name || '')
  if (raw === '/dashboard') return raw
  if (raw.startsWith('/dashboard/')) return raw
  if (raw.startsWith('/system')) return raw
  if (raw.startsWith('/')) return `/system${raw}`
  return `/system/${raw}`
}

function isRouteMatchedByMenu(menuPath: string, routePath: string): boolean {
  if (!menuPath || !routePath) return false
  if (menuPath === routePath) return true
  if (menuPath === '/dashboard' && routePath.startsWith('/dashboard')) return true
  if (menuPath.startsWith('/dashboard/')) return routePath === menuPath
  return false
}

export function resolveRouteChildPath(path: string): string {
  const value = String(path || '')
  if (value.startsWith('/system/')) return value.substring('/system/'.length)
  if (value.startsWith('/dashboard/')) return value.substring('/dashboard/'.length)
  if (value === '/dashboard') return 'index'
  return value.replace(/^\//, '')
}

export function isDashboardMenu(menu: MenuVO): boolean {
  const component = String(menu.component || '')
  const path = String(menu.path || '')
  return component === 'dashboard' || component === 'dashboard/index' || path === '/dashboard' || path.startsWith('/dashboard/')
}

export function normalizeMenuTree(menus: MenuVO[]): MenuVO[] {
  return sortMenus(Array.isArray(menus) ? menus : []).map((menu) => ({
    ...menu,
    visible: isMenuVisible(menu),
    children: normalizeMenuTree(getMenuChildren(menu))
  }))
}

export function getPrimaryMenus(menus: MenuVO[]): MenuVO[] {
  return getVisibleSortedMenus(menus)
}

export function findMenuChainByPath(menus: MenuVO[], routePath: string): MenuVO[] {
  const normalizedPath = String(routePath || '')
  if (!normalizedPath) return []

  const walk = (nodes: MenuVO[], parents: MenuVO[]): MenuVO[] => {
    const sorted = sortMenus(Array.isArray(nodes) ? nodes : [])
    for (const node of sorted) {
      const currentChain = [...parents, node]
      const currentPath = resolveMenuIndex(node)
      if (isRouteMatchedByMenu(currentPath, normalizedPath)) {
        return currentChain
      }
      const childChain = walk(getMenuChildren(node), currentChain)
      if (childChain.length > 0) {
        return childChain
      }
    }
    return []
  }

  return walk(menus, [])
}

export function resolveMenuChainForRoute(menus: MenuVO[], routePath: string): MenuVO[] {
  const exactChain = findMenuChainByPath(menus, routePath)
  if (exactChain.length > 0) return exactChain

  let currentPath = String(routePath || '').replace(/\/+$/, '')
  while (currentPath) {
    currentPath = currentPath.replace(/\/[^/]+$/, '')
    if (!currentPath || currentPath === '/system' || currentPath === '/dashboard') break
    const fallbackChain = findMenuChainByPath(menus, currentPath)
    if (fallbackChain.length > 0) return fallbackChain
  }

  return []
}

export function getActivePrimaryMenu(menus: MenuVO[], routePath: string): MenuVO | null {
  const chain = resolveMenuChainForRoute(menus, routePath)
  return chain[0] ?? null
}

export function resolveFirstVisibleMenuPath(menu: MenuVO | null | undefined): string {
  if (!menu) return ''
  const children = getVisibleSortedMenus(getMenuChildren(menu))
  if (children.length === 0) {
    return resolveMenuIndex(menu)
  }
  return resolveFirstVisibleMenuPath(children[0])
}

function shouldHideSecondaryForPrimary(primaryMenu: MenuVO, children: MenuVO[]): boolean {
  if (children.length === 0) return true
  if (isDashboardMenu(primaryMenu)) return true
  if (children.length === 1 && isRouteMatchedByMenu(resolveMenuIndex(children[0]), resolveMenuIndex(primaryMenu))) {
    return true
  }
  return false
}

export function getSecondaryMenuSections(primaryMenu: MenuVO | null | undefined): SecondaryMenuSection[] {
  if (!primaryMenu) return []

  const children = getVisibleSortedMenus(getMenuChildren(primaryMenu))
  if (shouldHideSecondaryForPrimary(primaryMenu, children)) return []

  const directItems: MenuVO[] = []
  const sections: SecondaryMenuSection[] = []

  children.forEach((child) => {
    const visibleChildren = getVisibleSortedMenus(getMenuChildren(child))
    if (visibleChildren.length > 0) {
      sections.push({
        key: String(child.id || child.path || child.name || sections.length),
        title: String(child.name || ''),
        items: visibleChildren
      })
      return
    }
    directItems.push(child)
  })

  if (directItems.length > 0) {
    sections.unshift({
      key: '__direct__',
      items: directItems
    })
  }

  return sections
}

export function resolveCurrentPageTitle(menus: MenuVO[], routePath: string): string {
  const chain = resolveMenuChainForRoute(menus, routePath)
  const current = chain.at(-1)
  return String(current?.name || '')
}

export function resolveLayoutActivePath(menus: MenuVO[], routePath: string): string {
  const chain = resolveMenuChainForRoute(menus, routePath)
  const current = chain.at(-1)
  return current ? resolveMenuIndex(current) : String(routePath || '')
}

export function isSubPageRoute(menus: MenuVO[], routePath: string): boolean {
  const matchedPath = resolveLayoutActivePath(menus, routePath)
  return Boolean(routePath) && matchedPath !== String(routePath || '')
}

export function isMenuInChain(menu: MenuVO, chain: MenuVO[]): boolean {
  const currentId = String(menu.id || menu.path || menu.name || '')
  if (!currentId) return false
  return chain.some((item) => String(item.id || item.path || item.name || '') === currentId)
}
