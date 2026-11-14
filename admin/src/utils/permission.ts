import { useUserStore } from '@/stores/user'

/**
 * 判断当前用户是否拥有指定权限码
 * @param permission 权限码，如 'system:user:add'
 * @returns true-有权限，false-无权限
 */
export function hasPermission(permission: string): boolean {
  const userStore = useUserStore()
  const profile = userStore.profile
  if (!profile) return false

  // 超级管理员（userType === 'ADMIN'）拥有所有权限
  if ((profile as { userType?: string }).userType === 'ADMIN') return true

  return userStore.permissions.includes(permission)
}

/**
 * 判断当前用户是否拥有指定权限码中的任意一个
 * @param permissions 权限码数组
 * @returns true-有权限，false-无权限
 */
export function hasAnyPermission(permissions: string[]): boolean {
  return permissions.some(p => hasPermission(p))
}
