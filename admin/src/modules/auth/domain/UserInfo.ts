import type { MenuVO } from '@/shared/types/menu'

/**
 * 用户信息
 */
export interface UserInfo {
  id: string
  username: string
  nickname?: string
  avatar?: string
  email?: string
  phone?: string
  userType?: string
  roles?: string[]
  permissions?: string[]
  menus?: MenuVO[] // 用户菜单权限
  companyBalance?: string // 企业账户余额
}
